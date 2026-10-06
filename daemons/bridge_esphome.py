#!/usr/bin/env python3
"""
TymOS — ESPHome Bridge: ESPHome devices -> MQTT (polling /events snapshot)

Kazde urzadzenie ESPHome wystawia /events (SSE) ktory przy polaczeniu zwraca PELNY
SNAPSHOT stanu jako serie eventow "state". Bridge co POLL_INTERVAL laczy sie, czyta
snapshot przez READ_TIME sekund, zamyka polaczenie i publishuje na MQTT. Dzieki temu
zerwanie WiFi nie wiesza watku — timeout na request wystarczy.

Jeden watek per urzadzenie ESPHome — padniecie jednego ESP nie blokuje innych.

Reload:
  Co RELOAD_CHECK_INTERVAL watki sprawdzaja flage `settings.tymos_reload`. Gdy sie zmienila,
  odswiezana jest lista `_enabled_set`. Gdy zmieniala sie LISTA urzadzen ESPHome (dodane/usuniete),
  daemon robi self-exit — systemd zrestartuje go z czystym stanem i nowymi watkami.
"""

import json
import sys
import os
import time
import signal
import threading
import socket
import paho.mqtt.client as mqtt

# Wspolne helpery DB/log dla wszystkich demonow TymOS
from lib.db import db_connect, db_ping, db_exec, db_log

BROKER = "localhost"
MQTT_PORT = 1883
MQTT_USER = ""
MQTT_PASS = ""
MQTT_TOPIC_PREFIX = "tymos/esphome"

# Domeny z /events do publikowania na MQTT (ignorujemy button, update, itp.)
PUBLISH_DOMAINS = {"sensor", "binary_sensor", "text_sensor", "switch", "fan", "climate", "number", "select"}

POLL_INTERVAL = 10            # co ile sekund odpytywac ESP /events
CONNECT_TIMEOUT = 5           # timeout TCP connect (gdy ESP offline — szybkie fail)
READ_TIME = 3                 # ile sekund czytac SSE (snapshot <1s, ale przy zatloczonym 2.4 GHz
                              # potrafi sie wlec — przy 2 s regularnie wychodzil „pusty snapshot")
LOG_FAIL_AFTER = 3            # dopiero tyle NIEUDANYCH POD RZAD warto zglosic (1-2 lecza sie same)
MAX_RESPONSE = 256 * 1024     # max 256KB odpowiedzi (safety)
RELOAD_CHECK_INTERVAL = 30    # co ile sekund sprawdzic flage reload
DB_PING_INTERVAL = 30         # co ile sekund main loop pinguje DB + watchdog
LAST_SEEN_THROTTLE = 120      # co ile aktualizowac devices.last_seen
NO_DEVICES_RETRY = 60         # gdy brak ESP'ow w DB, retry za X sekund (zamiast exit)

# Statystyki per urzadzenie (uzywane do logowania zdrowia)
device_stats = {}  # ip -> {ok, fail, consecutive_fail, last_ok}

# Shutdown event — ustawiany przez SIGTERM, przerywa wszystkie petle
shutdown_event = threading.Event()


def handle_signal(signum, frame):
    print("Sygnal %d — zamykam" % signum)
    shutdown_event.set()


signal.signal(signal.SIGTERM, handle_signal)
signal.signal(signal.SIGINT, handle_signal)


def log(level, source, message):
    """Wrapper na db_log dodajacy prefix `esphome_bridge:` do source'a."""
    db_log(level, "esphome_bridge:" + source, message)


# ---------------------------------------------------------------------------
# MQTT
# ---------------------------------------------------------------------------
mqtt_client = None


def on_mqtt_connect(client, userdata, flags, reason_code, properties):
    log("INFO", "mqtt", "Polaczono (rc=%s)" % reason_code)


def on_mqtt_disconnect(client, userdata, flags, reason_code, properties):
    print("[WARN] mqtt: ROZLACZONO (rc=%s) — paho reconnect" % reason_code)


def mqtt_publish(topic, payload):
    """Publikuje dict jako JSON na MQTT (qos=1)."""
    if mqtt_client:
        mqtt_client.publish(topic, json.dumps(payload) if isinstance(payload, dict) else str(payload), qos=1)


# ---------------------------------------------------------------------------
# Stan/cache daemona
# ---------------------------------------------------------------------------

# Zbior device_id ESPHome "wlaczonych" w GUI. Dla tych publikujemy na MQTT.
_enabled_set = set()

# Zbior IP aktualnie monitorowanych (watek aktywny). Uzywane do wykrycia zmiany listy.
_active_ips = set()

# Zbior device_id juz zarejestrowanych w DB (zeby nie robic INSERT przy kazdym iter)
registered_devices = set()
last_seen_update = {}

# Ostatnio widziana wartosc flagi reload — per daemon (nie per watek — wszystkie patrza na te sama)
_last_reload_ts = "0"
_reload_lock = threading.Lock()


def load_esphome_ips_from_db():
    """Zwraca zbior IP wszystkich ESPHome w DB (ieee LIKE 'esphome_%', nie deleted)."""
    rows = db_exec("SELECT ip FROM devices WHERE ieee LIKE 'esphome_%%' AND deleted=0 AND ip IS NOT NULL")
    return {r[0] for r in rows} if rows else set()


def refresh_enabled_set():
    """Odswieza `_enabled_set` z DB — uzywane do filtrowania publish na MQTT."""
    global _enabled_set
    rows = db_exec("SELECT ieee FROM devices WHERE enabled_fields IS NOT NULL AND deleted=0 AND ieee IS NOT NULL")
    _enabled_set = {r[0] for r in rows} if rows else set()


def check_reload_flag_shared():
    """
    Sprawdza `settings.tymos_reload`. Gdy sie zmieni:
      1. Odswieza `_enabled_set`
      2. Sprawdza czy lista ESPHome w DB zmienila sie vs aktywne watki — jesli tak,
         wola sys.exit(0). Systemd zrestartuje daemon z czysta lista urzadzen.
    Wolane z watkow workerow — lock chroni przed kilkoma reloadami jednoczesnie.
    """
    global _last_reload_ts
    with _reload_lock:
        rows = db_exec("SELECT v FROM settings WHERE k='tymos_reload' LIMIT 1")
        if not rows:
            return
        new_ts = rows[0][0]
        if new_ts == _last_reload_ts:
            return
        _last_reload_ts = new_ts
        refresh_enabled_set()
        log("INFO", "reload", "Reload: %d enabled w DB" % len(_enabled_set))
        # Wykryj zmiane listy urzadzen ESPHome — self-exit zeby systemd poprawnie uruchomil watki
        current_ips = load_esphome_ips_from_db()
        if current_ips != _active_ips:
            added = current_ips - _active_ips
            removed = _active_ips - current_ips
            log("WARN", "reload", "Lista ESPHome zmieniona (dodane=%s, usuniete=%s) — self-exit do systemd" %
                (sorted(added), sorted(removed)))
            shutdown_event.set()
            os.kill(os.getpid(), signal.SIGTERM)


# ---------------------------------------------------------------------------
# Rejestracja urzadzen (auto-discovery do `devices`)
# ---------------------------------------------------------------------------
def register_device(nickname, device_id, model, fields_dict, ip=None):
    """
    Zapisuje urzadzenie w tabeli `devices` przy pierwszym widzeniu.
    Przy kolejnych — aktualizuje `last_seen` (throttled co LAST_SEEN_THROTTLE sekund).
    """
    if device_id in registered_devices:
        now_t = time.time()
        if (now_t - last_seen_update.get(device_id, 0)) >= LAST_SEEN_THROTTLE:
            db_exec("UPDATE devices SET last_seen=NOW() WHERE ieee=%s", (device_id,))
            last_seen_update[device_id] = now_t
        return
    fields_json = json.dumps(fields_dict)
    sql = """INSERT INTO devices (device, ieee, model, fields, ip, first_seen, last_seen)
             VALUES (%s, %s, %s, %s, %s, NOW(), NOW())
             ON DUPLICATE KEY UPDATE last_seen=NOW()"""
    db_exec(sql, (nickname, device_id, model, fields_json, ip))
    registered_devices.add(device_id)
    last_seen_update[device_id] = time.time()
    log("INFO", "register", "Zarejestrowano: %s (%s)" % (nickname, device_id))


# ---------------------------------------------------------------------------
# HTTP fetch z raw socketem (pelna kontrola timeoutow)
# ---------------------------------------------------------------------------
def fetch_events_snapshot(ip):
    """
    Laczy sie z /events ESPHome (HTTP + SSE), czyta przez READ_TIME sekund
    i zwraca liste JSON stringow z linii zaczynajacych sie od 'data: '.
    Snapshot stanu przychodzi w <1s, reszta to live updates (bez szkody).

    W razie timeoutu/braku polaczenia — rzuca ConnectionError.
    """
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.settimeout(CONNECT_TIMEOUT)
    try:
        sock.connect((ip, 80))
    except (socket.timeout, OSError) as e:
        sock.close()
        raise ConnectionError("connect %s: %s" % (ip, e))

    sock.settimeout(1.0)  # krotki timeout na pojedynczy recv
    request = b"GET /events HTTP/1.0\r\nHost: " + ip.encode() + b"\r\nConnection: close\r\n\r\n"
    try:
        sock.sendall(request)
    except (socket.timeout, OSError) as e:
        sock.close()
        raise ConnectionError("send %s: %s" % (ip, e))

    buf = b""
    deadline = time.time() + READ_TIME
    try:
        while len(buf) < MAX_RESPONSE and time.time() < deadline:
            try:
                chunk = sock.recv(4096)
            except socket.timeout:
                continue
            if not chunk:
                break
            buf += chunk
    finally:
        sock.close()

    # Odroz HTTP header od body, wyciagnij linie 'data: '
    full = buf.decode("utf-8", errors="replace")
    if "\r\n\r\n" in full:
        _, full = full.split("\r\n\r\n", 1)

    lines = []
    for line in full.split("\n"):
        line = line.strip()
        if line.startswith("data: "):
            lines.append(line[6:])
    return lines


# ---------------------------------------------------------------------------
# Parser snapshot SSE -> stan
# ---------------------------------------------------------------------------
def parse_snapshot(lines):
    """
    Z listy JSON stringow (kazdy event SSE) wyciaga:
      - model (nazwa urzadzenia z event 'info')
      - state_dict (klucz -> wartosc — dla publikacji na MQTT)
      - fields_dict (klucz -> {sql, unit, label} — dla rejestracji w devices)
    """
    model = None
    state = {}
    fields = {}

    for raw in lines:
        try:
            data = json.loads(raw)
        except (json.JSONDecodeError, ValueError):
            continue

        # Event 'info' z metadanymi urzadzenia
        if "title" in data and "domain" not in data:
            model = data.get("comment") or data.get("title") or "ESPHome"
            continue

        domain = data.get("domain", "")
        if domain not in PUBLISH_DOMAINS:
            continue

        eid = data.get("id", "")
        value = data.get("value")
        uom = data.get("uom", "")
        label = data.get("name", eid)

        # Klucz — snake_case z id (np. "sensor-temperatura_bufora" -> "temperatura_bufora")
        key = eid.split("-", 1)[1] if "-" in eid else eid
        key = key.replace("-", "_").replace(" ", "_").lower()

        state[key] = value

        # Typ SQL na podstawie wartosci (do rejestracji w devices.fields)
        if isinstance(value, bool):
            fields[key] = {"sql": "TINYINT", "unit": uom, "label": label}
        elif isinstance(value, (int, float)):
            fields[key] = {"sql": "DOUBLE", "unit": uom, "label": label}
        elif isinstance(value, str) and domain in ("text_sensor", "select", "switch"):
            fields[key] = {"sql": "VARCHAR(50)", "unit": "", "label": label}

    return model, state, fields


# ---------------------------------------------------------------------------
# Watek per urzadzenie
# ---------------------------------------------------------------------------
def device_thread(cfg):
    """
    Glowna petla watku roboczego dla jednego urzadzenia ESPHome.
    Co POLL_INTERVAL sekund: fetch snapshot, parsuj, zarejestruj, publikuj na MQTT.
    Co RELOAD_CHECK_INTERVAL sekund: sprawdz flage reload.

    Gdy ESP nie odpowiada — loguje blad rzadko (pierwszy, co 10-ty), nie spamuje.
    Gdy polaczenie wraca — loguje info o przywroceniu.
    """
    name = cfg["name"]
    ip = cfg["ip"]
    safe_name = name.lower().replace(" ", "_")
    device_id = "esphome_%s" % ip.replace(".", "_")

    db_connect()

    stats = {"ok": 0, "fail": 0, "consecutive_fail": 0, "last_ok": 0}
    device_stats[ip] = stats

    # Ostatni znany stan urzadzenia — baza do scalania niepelnych snapshotow (patrz nizej).
    last_state = {}

    log("INFO", safe_name, "Polling %s co %ds" % (ip, POLL_INTERVAL))

    last_reload_check = time.time()

    while not shutdown_event.is_set():
        # Sprawdz flage reload (co RELOAD_CHECK_INTERVAL)
        now_t = time.time()
        if (now_t - last_reload_check) >= RELOAD_CHECK_INTERVAL:
            last_reload_check = now_t
            check_reload_flag_shared()
            if shutdown_event.is_set():
                break

        try:
            lines = fetch_events_snapshot(ip)
            if not lines:
                raise ValueError("pusty snapshot")

            model, state, fields = parse_snapshot(lines)
            if not state:
                raise ValueError("brak stanow w snapshot")

            if fields:
                register_device(name, device_id, model or "ESPHome", fields, ip)

            # Publikuj tylko dla urzadzen wlaczonych w GUI
            if device_id not in _enabled_set:
                shutdown_event.wait(timeout=60)
                continue

            # SCALANIE z ostatnim znanym stanem. ESPHome zrzuca wszystkie encje przy polaczeniu,
            # ale czytamy SSE tylko przez READ_TIME — przy wolnym WiFi albo gdy ESP akurat gada
            # po CAN, czesc encji nie zdazy dojsc. Wczesniej publikowalismy taki ogryzek i wiersz
            # w device<ID> mial NULL-e, a widget pokazywal puste pola / OFF (rekuperator „Away"
            # bez fan_level i trybu, 2026-08-01 08:12:55). Teraz nowy odczyt naklada sie na
            # poprzedni: encje ktore nie przyszly zachowuja ostatnia wartosc.
            # Pomijamy tez PUSTE wartosci: ESP przy przelaczaniu trybu publikuje text_sensor ze
            # stanem "" (2026-08-01 09:16:53 — fan_level i operating_mode puste). To nie jest
            # informacja, tylko dziura, wiec traktujemy ja jak brak encji.
            last_state.update({k: v for k, v in state.items() if v != ""})
            payload = dict(last_state)
            payload["device_id"] = device_id
            topic = "%s/%s" % (MQTT_TOPIC_PREFIX, safe_name)
            mqtt_publish(topic, payload)

            stats["ok"] += 1
            stats["last_ok"] = time.time()
            # „Przywrocone" tylko gdy wczesniej zglosilismy awarie — inaczej para WARN+INFO
            # robila sie z kazdego pojedynczego, samoleczacego sie potkniecia.
            if stats["consecutive_fail"] >= LOG_FAIL_AFTER:
                log("INFO", safe_name, "Polaczenie przywrocone po %d bledach" % stats["consecutive_fail"])
            stats["consecutive_fail"] = 0

        except Exception as e:
            stats["fail"] += 1
            stats["consecutive_fail"] += 1
            # Loguj dopiero od LOG_FAIL_AFTER pod rzad (przy POLL_INTERVAL=10s to ~30 s ciszy),
            # potem co 10-ty. Pojedyncze wpadki przy zatloczonym WiFi sa normalne i wracaja same.
            if stats["consecutive_fail"] == LOG_FAIL_AFTER or stats["consecutive_fail"] % 10 == 0:
                log("WARN", safe_name, "Poll #%d nieudany: %s" % (stats["consecutive_fail"], e))

        shutdown_event.wait(timeout=POLL_INTERVAL)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
def main():
    """
    Glowna funkcja daemona.
    1. Polaczenie DB z retry (30 prob x 5s).
    2. Polaczenie MQTT z retry (30 prob x 5s).
    3. Zaladuj liste ESPHome z DB. Gdy brak — czekaj NO_DEVICES_RETRY i sprawdz znow.
    4. Uruchom watek per urzadzenie.
    5. Glowna petla: co DB_PING_INTERVAL ping DB + watchdog watkow.
    """
    global mqtt_client, _active_ips, _last_reload_ts

    log("INFO", "main", "TymOS ESPHome Bridge uruchamiany (PID %d)" % os.getpid())

    for _attempt in range(30):
        try:
            db_connect()
            break
        except Exception as e:
            print("DB connect failed: %s — retry za 5s (%d/30)" % (e, _attempt + 1), file=sys.stderr)
            time.sleep(5)
    else:
        print("FATAL: nie udalo sie polaczyc z DB po 30 probach", file=sys.stderr)
        sys.exit(1)

    mqtt_client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id="esphome_bridge")
    if MQTT_USER:
        mqtt_client.username_pw_set(MQTT_USER, MQTT_PASS)
    mqtt_client.reconnect_delay_set(min_delay=1, max_delay=120)
    mqtt_client.on_connect = on_mqtt_connect
    mqtt_client.on_disconnect = on_mqtt_disconnect

    for _attempt in range(30):
        try:
            mqtt_client.connect(BROKER, MQTT_PORT, keepalive=60)
            break
        except Exception as e:
            print("MQTT connect failed: %s — retry za 5s (%d/30)" % (e, _attempt + 1), file=sys.stderr)
            time.sleep(5)
    else:
        print("FATAL: nie udalo sie polaczyc z MQTT po 30 probach", file=sys.stderr)
        sys.exit(1)
    mqtt_client.loop_start()

    # Zaladuj enabled_set + initial _last_reload_ts (zeby nie trigerowac reload od razu)
    refresh_enabled_set()
    rows = db_exec("SELECT v FROM settings WHERE k='tymos_reload' LIMIT 1")
    if rows:
        _last_reload_ts = rows[0][0]

    # Zaladuj liste urzadzen ESPHome. Gdy brak — czekaj zamiast exit (systemd by restartowal w petli).
    while not shutdown_event.is_set():
        rows = db_exec("SELECT device, ip FROM devices WHERE ieee LIKE 'esphome_%%' AND deleted=0 AND ip IS NOT NULL GROUP BY ip")
        devices = [{"name": r[0], "ip": r[1]} for r in rows] if rows else []
        if devices:
            break
        log("INFO", "main", "Brak ESPHome urzadzen w DB — retry za %ds" % NO_DEVICES_RETRY)
        if shutdown_event.wait(timeout=NO_DEVICES_RETRY):
            return

    _active_ips = {cfg["ip"] for cfg in devices}

    threads = []
    for cfg in devices:
        t = threading.Thread(target=device_thread, args=(cfg,),
                             name="esphome_%s" % cfg["name"].lower().replace(" ", "_"), daemon=True)
        t.start()
        threads.append((cfg, t))
        log("INFO", "main", "Watek %s uruchomiony (%s)" % (cfg["name"], cfg["ip"]))

    # Glowna petla — watchdog watkow + ping DB
    while not shutdown_event.is_set():
        shutdown_event.wait(timeout=DB_PING_INTERVAL)
        if shutdown_event.is_set():
            break
        try:
            db_ping()
            for i, (cfg, t) in enumerate(threads):
                if not t.is_alive():
                    log("ERROR", "watchdog", "Watek %s martwy — restart" % cfg["name"])
                    new_t = threading.Thread(target=device_thread, args=(cfg,),
                                             name="esphome_%s" % cfg["name"].lower().replace(" ", "_"), daemon=True)
                    new_t.start()
                    threads[i] = (cfg, new_t)
        except Exception as e:
            log("ERROR", "main_loop", str(e))
            try:
                db_connect()
            except Exception as e2:
                log("ERROR", "db_reconnect", "w main loop: %s" % e2)

    print("Zamykam...")
    mqtt_client.loop_stop()
    mqtt_client.disconnect()
    print("Zamknieto.")


if __name__ == "__main__":
    main()
