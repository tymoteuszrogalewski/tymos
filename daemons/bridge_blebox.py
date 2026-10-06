#!/usr/bin/env python3
"""
TymOS — BleBox Bridge: PstrykEnergyMeter -> MQTT

Co robi daemon:
  1. Pobiera liste urzadzen BleBox z tabeli `devices` (gdzie ieee zaczyna sie od "blebox_").
  2. Co 10s odpytuje HTTP `/state` kazdego urzadzenia (miernik 3-fazowy: L1/L2/L3 + suma).
  3. Parsuje odpowiedz i publikuje na MQTT do `tymos/blebox/<nazwa>`.
  4. Glowny listener (tymos.py) subskrybuje `tymos/blebox/#` i zapisuje do `device<ID>`.

Reload:
  Co 30s daemon sprawdza flage `settings.tymos_reload`. Gdy sie zmieni (GUI zrobilo jakas
  zmiane) — przeladowuje liste urzadzen i liste "enabled".
"""

import json
import sys
import os
import time
import signal
import threading
import urllib.request
import paho.mqtt.client as mqtt

# Wspolne helpery DB/log dla wszystkich demonow TymOS
from lib.db import db_connect, db_ping, db_exec, db_log

BROKER = "localhost"
MQTT_PORT = 1883
MQTT_USER = ""
MQTT_PASS = ""
MQTT_TOPIC_PREFIX = "tymos/blebox"

POLL_INTERVAL = 10          # co ile sekund odpytywac BleBox'y (HTTP /state)
FAIL_THRESHOLD = 3          # ile porazek POD RZAD zanim uznamy to za ERROR (1-2 = mrugniecie sieci)
RELOAD_CHECK_INTERVAL = 30  # co ile sekund sprawdzic flage reload w DB
LAST_SEEN_THROTTLE = 120    # co ile sekund aktualizowac `devices.last_seen`
DB_PING_INTERVAL = 30       # co ile sekund pingowac DB w glownej petli (main)
NO_DEVICES_RETRY = 60       # gdy brak BleBox'ow w DB, sprawdz znow za X sekund

# Shutdown event — ustawiany przez sygnal, przerywa wszystkie petle
shutdown_event = threading.Event()


def handle_signal(signum, frame):
    print("Sygnal %d — zamykam" % signum)
    shutdown_event.set()


signal.signal(signal.SIGTERM, handle_signal)
signal.signal(signal.SIGINT, handle_signal)


def log(level, source, message):
    """Wrapper na db_log dodajacy prefix `blebox_bridge:` do source'a."""
    db_log(level, "blebox_bridge:" + source, message)


# ---------------------------------------------------------------------------
# MQTT
# ---------------------------------------------------------------------------
mqtt_client = None


def on_mqtt_connect(client, userdata, flags, reason_code, properties):
    log("INFO", "mqtt", "Polaczono (rc=%s)" % reason_code)


def on_mqtt_disconnect(client, userdata, flags, reason_code, properties):
    print("[WARN] mqtt: ROZLACZONO (rc=%s) — paho reconnect" % reason_code)


def mqtt_publish(topic, payload):
    """Publikuje dict jako JSON na MQTT (qos=1). Gdy nie ma klienta — milcz."""
    if mqtt_client:
        mqtt_client.publish(topic, json.dumps(payload) if isinstance(payload, dict) else str(payload), qos=1)


# ---------------------------------------------------------------------------
# Stan/cache daemona
# ---------------------------------------------------------------------------

# Lista konfigow urzadzen do odpytywania (po reload z DB)
DEVICES = []

# Zbior device_id (blebox_<ip>_<phase>) ktore sa "wlaczone" w GUI (enabled_fields niepusty).
# Dla tych publikujemy na MQTT. Pozostale rejestrujemy ale nie publikujemy.
_enabled_set = set()

# Zbior device_id juz zarejestrowanych w tabeli `devices` (zeby nie robic INSERT przy kazdym iter).
registered_devices = set()
last_seen_update = {}  # device_id -> epoch ostatniego UPDATE last_seen

# Ostatnio widziana wartosc flagi reload. Gdy sie zmieni — robimy do_reload().
_last_reload_ts = "0"

# ip -> ile pollow POD RZAD sie nie udalo. Zerowane przy pierwszym udanym odczycie.
fail_count = {}


def do_reload():
    """Przeladowuje liste BleBox'ow i `_enabled_set` z DB. Wolane na zmiane flagi reload."""
    global DEVICES, _enabled_set
    DEVICES = load_devices_from_db()
    rows = db_exec("SELECT ieee FROM devices WHERE enabled_fields IS NOT NULL AND deleted=0 AND ieee IS NOT NULL")
    _enabled_set = {r[0] for r in rows} if rows else set()
    log("INFO", "reload", "Reload: %d urzadzen BleBox, %d enabled w DB" % (len(DEVICES), len(_enabled_set)))


def check_reload_flag():
    """Sprawdza `settings.tymos_reload` — gdy zmienione, wola do_reload(). Zwraca True gdy byl reload."""
    global _last_reload_ts
    rows = db_exec("SELECT v FROM settings WHERE k='tymos_reload' LIMIT 1")
    if not rows:
        return False
    new_ts = rows[0][0]
    if new_ts != _last_reload_ts:
        _last_reload_ts = new_ts
        do_reload()
        return True
    return False


# ---------------------------------------------------------------------------
# Rejestracja urzadzen (auto-discovery do `devices`)
# ---------------------------------------------------------------------------
def register_device(nickname, device_id, model, fields_dict):
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
    db_exec("""INSERT INTO devices (device, ieee, model, fields, first_seen, last_seen)
               VALUES (%s, %s, %s, %s, NOW(), NOW())
               ON DUPLICATE KEY UPDATE last_seen=NOW()""",
            (nickname, device_id, model, fields_json))
    registered_devices.add(device_id)
    last_seen_update[device_id] = time.time()
    log("INFO", "register", "Zarejestrowano: %s (%s)" % (nickname, device_id[:16]))


# ---------------------------------------------------------------------------
# BleBox Pstryk parser
# ---------------------------------------------------------------------------
def parse_pstryk_state(data):
    """
    Parsuje JSON z endpointu `/state` BleBox Pstryk. Zwraca dict per faza (id 0-3):
      0 -> suma, 1 -> L1, 2 -> L2, 3 -> L3
    Kazda faza ma pola: power, reactive_power, apparent_power, voltage, current, frequency,
    energy, energy_reverse (po dekodowaniu z wartosci surowych API).
    """
    sensors = data.get("multiSensor", {}).get("sensors", [])
    phases = {}
    for s in sensors:
        pid = s.get("id", 0)
        stype = s.get("type", "")
        value = s.get("value", 0)

        if pid not in phases:
            phases[pid] = {}

        # Jednostki API potwierdzone 2026-08-16 przez porownanie z licznikiem Energi
        # oraz przez tozsamosc apparentPower = voltage * current:
        #   moce  -> W / var / VA  (BEZ dzielnika; wczesniejsze /10 zanizalo odczyt 10x)
        #   energia -> Wh          (dzielnik 1000, nie 100)
        #   voltage -> 0,1 V, current -> mA, frequency -> mHz
        if stype == "activePower":
            phases[pid]["power"] = float(value)
        elif stype == "reactivePower":
            phases[pid]["reactive_power"] = float(value)
        elif stype == "apparentPower":
            phases[pid]["apparent_power"] = float(value)
        elif stype == "voltage":
            phases[pid]["voltage"] = round(value / 10.0, 1)
        elif stype == "current":
            phases[pid]["current"] = round(value / 1000.0, 2)
        elif stype == "frequency":
            phases[pid]["frequency"] = round(value / 1000.0, 1)
        elif stype == "forwardActiveEnergy":
            phases[pid]["energy"] = round(value / 1000.0, 3)
        elif stype == "reverseActiveEnergy":
            phases[pid]["energy_reverse"] = round(value / 1000.0, 3)

    return phases


# ---------------------------------------------------------------------------
# Ladowanie urzadzen z DB
# ---------------------------------------------------------------------------
def load_devices_from_db():
    """Zwraca liste dictow [{name, ip}] dla urzadzen BleBox (ieee zaczyna sie od 'blebox_')."""
    rows = db_exec("SELECT DISTINCT ip, device FROM devices "
                   "WHERE ieee LIKE 'blebox_%%' AND deleted=0 AND ip IS NOT NULL "
                   "GROUP BY ip")
    devs = []
    if rows:
        for r in rows:
            devs.append({"name": r[0].replace(".", "_"), "ip": r[0]})
    return devs


# ---------------------------------------------------------------------------
# Glowny watek polling
# ---------------------------------------------------------------------------
def poll_thread():
    """
    Watek roboczy: co POLL_INTERVAL sekund odpytuje kazdy BleBox i publikuje na MQTT.
    Co RELOAD_CHECK_INTERVAL sekund sprawdza flage reload i odswieza liste urzadzen.
    Gdy brak urzadzen w DB — czeka NO_DEVICES_RETRY sekund i sprawdza znow (nie konczy watku).
    """
    db_connect()
    do_reload()  # wstepny load + init _last_reload_ts przez check_reload_flag poprzez do_reload
    # Inicjalny ts flagi (zeby pierwsze check_reload_flag nie triggeruj drugiego reloadu)
    rows = db_exec("SELECT v FROM settings WHERE k='tymos_reload' LIMIT 1")
    global _last_reload_ts
    if rows:
        _last_reload_ts = rows[0][0]

    # Zamiast blokujacego time.sleep(3) — czekamy reagujac na SIGTERM
    shutdown_event.wait(timeout=3)
    if shutdown_event.is_set():
        return

    phase_names = {0: "suma", 1: "L1", 2: "L2", 3: "L3"}
    last_reload_check = time.time()

    while not shutdown_event.is_set():
        # Brak urzadzen w DB — czekaj i spr znow (poprzednio watek konczyl sie, teraz retry)
        if not DEVICES:
            log("INFO", "poll", "Brak BleBox urzadzen w DB — retry za %ds" % NO_DEVICES_RETRY)
            # Wait with reload check
            for _ in range(NO_DEVICES_RETRY // RELOAD_CHECK_INTERVAL):
                if shutdown_event.wait(timeout=RELOAD_CHECK_INTERVAL):
                    return
                check_reload_flag()
                if DEVICES:
                    break
            continue

        # Sprawdz flage reload (co RELOAD_CHECK_INTERVAL)
        now_t = time.time()
        if (now_t - last_reload_check) >= RELOAD_CHECK_INTERVAL:
            last_reload_check = now_t
            check_reload_flag()

        # Odpytaj kazdy BleBox i publikuj
        for cfg in DEVICES:
            try:
                url = "http://%s/state" % cfg["ip"]
                req = urllib.request.urlopen(url, timeout=5)
                data = json.loads(req.read().decode())
                phases = parse_pstryk_state(data)

                # Odczyt sie udal — jesli wczesniej byly porazki, zamknij je jednym INFO
                if fail_count.get(cfg["ip"]):
                    log("INFO", "poll", "%s: wrocil po %d nieudanych pollach" % (cfg["name"], fail_count[cfg["ip"]]))
                    fail_count[cfg["ip"]] = 0

                for pid, values in phases.items():
                    pname = phase_names.get(pid, "phase_%d" % pid)
                    safe_name = cfg["name"].lower().replace(" ", "_") + "_" + pname
                    device_id = "blebox_%s_%s" % (cfg["ip"].replace(".", "_"), pname)

                    values["device_id"] = device_id
                    values["nickname"] = "%s %s" % (cfg["name"], pname.upper())
                    values["phase"] = pname

                    topic = "%s/%s" % (MQTT_TOPIC_PREFIX, safe_name)
                    fields_for_db = {k: v for k, v in values.items() if k not in ("device_id", "nickname", "phase")}
                    register_device(values["nickname"], device_id, "Pstryk", fields_for_db)

                    # Publikuj tylko dla urzadzen wlaczonych w GUI
                    if device_id not in _enabled_set:
                        continue
                    mqtt_publish(topic, values)

            except Exception as e:
                # Pojedyncze pudlo to zwykle mrugniecie sieci (timeout 5s, poll co 10s).
                # ERROR (= alert na Telegram) dopiero po FAIL_THRESHOLD porazkach POD RZAD
                # i tylko RAZ na awarie — powyzej progu cisza, zeby nie logowac co 10s.
                n = fail_count.get(cfg["ip"], 0) + 1
                fail_count[cfg["ip"]] = n
                if n < FAIL_THRESHOLD:
                    log("WARN", "poll", "%s: %s (%d/%d)" % (cfg["name"], e, n, FAIL_THRESHOLD))
                elif n == FAIL_THRESHOLD:
                    log("ERROR", "poll", "%s: %s (%d nieudane polle pod rzad)" % (cfg["name"], e, n))

        shutdown_event.wait(timeout=POLL_INTERVAL)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
def main():
    """
    Glowna funkcja daemona.
    1. Polaczenie DB z retry (30 prob × 5s).
    2. Polaczenie MQTT z retry (30 prob × 5s).
    3. Uruchomienie watku poll_thread (odpytywanie BleBox).
    4. Glowna petla: co DB_PING_INTERVAL sekund ping DB + watchdog watku poll.
    """
    global mqtt_client

    log("INFO", "main", "TymOS BleBox Bridge uruchamiany (PID %d)" % os.getpid())

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

    mqtt_client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id="blebox_bridge")
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

    t = threading.Thread(target=poll_thread, name="blebox_poll", daemon=True)
    t.start()
    log("INFO", "main", "Watek blebox_poll uruchomiony")

    # Glowna petla — watchdog i ping DB
    while not shutdown_event.is_set():
        shutdown_event.wait(timeout=DB_PING_INTERVAL)
        if shutdown_event.is_set():
            break
        try:
            db_ping()
            if not t.is_alive():
                log("ERROR", "watchdog", "Watek blebox_poll martwy — restart")
                t = threading.Thread(target=poll_thread, name="blebox_poll", daemon=True)
                t.start()
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
