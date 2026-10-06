#!/usr/bin/env python3
"""
TymOS — ONVIF Bridge: kamery ONVIF (person/motion/vehicle/doorbell) -> MQTT.

Architektura:
  1. HTTP server (aiohttp, port 8585) nasluchuje webhookow POST od kamer.
  2. Dla kazdej kamery subskrypcja ONVIF PullPoint z `ConsumerReference` wskazujacym na nasz
     HTTP server — gdy kamera wykryje event, wysyla POST do nas.
  3. POST -> parse SOAP -> publish na MQTT `tymos/onvif/camera/<cam_name>`.
  4. Subskrypcje odswiezane co ONVIF_RENEW_INTERVAL (8 min) — w razie rozlaczenia resubscribe.

Reload:
  `_sync_cameras()` co CAMERA_REFRESH_INTERVAL (30s) czyta DB i adaptuje aktywne taski:
  dodaje nowe kamery, usuwa usuniete, restartuje przy zmianie konfiguracji (ip/port/user/pass).
  Tym samym `_enabled_set` jest odswiezany — nie trzeba osobnego mechanizmu.

Kamery ONVIF w `devices`: ieee `cam_<ip_podkreslone>`, model `tp-link Tapo ...` lub podobny.
"""

import json
import sys
import os
import time
import signal
import threading
import asyncio
import unicodedata
import paho.mqtt.client as mqtt

# Wspolne helpery DB/log
from lib.db import db_connect, db_ping, db_exec, db_log

BROKER = "localhost"
MQTT_PORT = 1883
MQTT_USER = ""
MQTT_PASS = ""
MQTT_TOPIC_PREFIX = "tymos/onvif"

ONVIF_CALLBACK_PORT = 8585
ONVIF_WSDL_DIR = "/opt/tymos/venv/lib/python3.13/site-packages/onvif/wsdl"
ONVIF_RENEW_INTERVAL = 480       # co ile sekund odnawiac subskrypcje ONVIF
CAMERA_REFRESH_INTERVAL = 30     # co ile sekund `_sync_cameras` czyta DB (ujednolicone z bridge'ami)
DB_PING_INTERVAL = 30
LAST_SEEN_THROTTLE = 120

shutdown_event = threading.Event()


def handle_signal(signum, frame):
    print("Sygnal %d — zamykam" % signum)
    shutdown_event.set()


signal.signal(signal.SIGTERM, handle_signal)
signal.signal(signal.SIGINT, handle_signal)


def log(level, source, message):
    """Wrapper na db_log dodajacy prefix `onvif_bridge:` do source'a."""
    db_log(level, "onvif_bridge:" + source, message)


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

# Zbior cam_device_id wlaczonych w GUI (enabled_fields niepusty)
_enabled_set = set()

# Zbior wszystkich device_id juz zarejestrowanych w `devices` (zeby nie robic INSERT co iteracje)
registered_devices = set()
last_seen_update = {}


def refresh_enabled_set():
    """Odswieza `_enabled_set` z DB — wolane z `_sync_cameras`."""
    global _enabled_set
    rows = db_exec("SELECT ieee FROM devices WHERE enabled_fields IS NOT NULL AND deleted=0 AND ieee IS NOT NULL")
    _enabled_set = {r[0] for r in rows} if rows else set()


def is_disabled(device_id):
    """True gdy urzadzenie nie jest wlaczone w GUI (enabled_fields puste/null lub deleted)."""
    return device_id not in _enabled_set


# ---------------------------------------------------------------------------
# Rejestracja urzadzen w `devices`
# ---------------------------------------------------------------------------
def load_registered_devices():
    """Przy starcie zaladuj zbior juz zarejestrowanych IEEE — zeby unikac INSERT duplikatow."""
    rows = db_exec("SELECT ieee FROM devices WHERE ieee IS NOT NULL")
    if rows:
        for r in rows:
            registered_devices.add(r[0])


def sanitize(name):
    """Usun emoji/symbole i znormalizuj nazwe (spacje -> _, lowercase, itp.)."""
    clean = ""
    for ch in name:
        cat = unicodedata.category(ch)
        if cat.startswith("So") or cat.startswith("Sk"):
            continue
        clean += ch
    return clean.strip().lower().replace(" ", "_").replace("/", "_").replace("=>", "do").replace("<=", "z")


def load_cameras_from_db():
    """Zwraca liste konfigow kamer ONVIF z DB (ieee LIKE 'cam_%', z ip + onvif_port)."""
    rows = db_exec("SELECT device, ip, onvif_port, onvif_user, onvif_pass FROM devices "
                   "WHERE ieee LIKE 'cam_%%' AND deleted=0 AND onvif_port IS NOT NULL AND ip IS NOT NULL")
    cams = []
    if rows:
        for r in rows:
            cams.append({"name": sanitize(r[0]), "ip": r[1], "onvif_port": int(r[2]),
                         "user": r[3], "pass": r[4]})
    return cams


def register_device(nickname, device_id, model, fields_dict, ip=None):
    """
    Zapis urzadzenia w `devices` przy pierwszym widzeniu, UPDATE last_seen przy kolejnych
    (throttled co LAST_SEEN_THROTTLE sekund).
    """
    if device_id in registered_devices:
        now_t = time.time()
        if (now_t - last_seen_update.get(device_id, 0)) >= LAST_SEEN_THROTTLE:
            db_exec("UPDATE devices SET last_seen=NOW() WHERE ieee=%s", (device_id,))
            last_seen_update[device_id] = now_t
        return
    fields_json = json.dumps(fields_dict)
    db_exec("""INSERT INTO devices (device, ieee, model, ip, fields, first_seen, last_seen)
               VALUES (%s, %s, %s, %s, %s, NOW(), NOW())
               ON DUPLICATE KEY UPDATE last_seen=NOW()""",
            (nickname, device_id, model, ip, fields_json))
    registered_devices.add(device_id)
    last_seen_update[device_id] = time.time()
    log("INFO", "register", "Zarejestrowano: %s (%s) [%s]" % (nickname, model, device_id[:16]))


# ---------------------------------------------------------------------------
# Parser SOAP XML z webhook ONVIF
# ---------------------------------------------------------------------------
camera_ip_to_name = {}


def parse_onvif_event(soap_xml):
    """
    Wyciaga eventy ONVIF z SOAP XML (regex, bez XML parser).
    Zwraca liste (event_type, is_active, topic):
      event_type: person / motion / vehicle / pet / doorbell / line_cross
      is_active:  True (trigger) lub False (koniec eventu)
    """
    import re
    events = []
    topics = re.findall(r'<[^>]*Topic[^>]*>([^<]+)</', soap_xml)
    # Uwaga: kolejnosc atrybutow zalezy od producenta — Tapo daje Value przed Name.
    items = re.findall(r'SimpleItem\s+Name="(\w+)"\s+Value="([^"]*)"', soap_xml)
    items += [(n, v) for v, n in re.findall(r'SimpleItem\s+Value="([^"]*)"\s+Name="(\w+)"', soap_xml)]

    # Tapo (C325WB) nie uzywa osobnych tematow — wszystko leci jako TPSmartEvent,
    # a typ siedzi w nazwie pola: IsVehicle / IsPeople / IsPet (Value true/false).
    tp_map = {"isvehicle": "vehicle", "ispeople": "person", "isperson": "person", "ispet": "pet"}

    for topic in topics:
        event_type = "unknown"
        is_active = True

        for item_name, item_value in items:
            if item_value.lower() in ("false", "0"):
                is_active = False
            if item_value.lower() in ("true", "1"):
                is_active = True

        if "TPSmartEvent" in topic:
            for item_name, item_value in items:
                if item_name.lower() in tp_map:
                    event_type = tp_map[item_name.lower()]
                    is_active = item_value.lower() in ("true", "1")
                    events.append((event_type, is_active, topic))
            continue

        if "PeopleDetect" in topic or "PersonDetect" in topic:
            event_type = "person"
        elif "VehicleDetect" in topic:
            event_type = "vehicle"
        elif "PetDetect" in topic:
            event_type = "pet"
        elif "LineCross" in topic:
            event_type = "line_cross"
        elif "Visitor" in topic or "ButtonPress" in topic or "DoorBell" in topic:
            event_type = "doorbell"
        elif "CellMotion" in topic or "Motion" in topic:
            event_type = "motion"

        if event_type != "unknown":
            events.append((event_type, is_active, topic))

    return events


# ---------------------------------------------------------------------------
# Glowny watek ONVIF — asyncio (HTTP server + subskrypcje per kamera)
# ---------------------------------------------------------------------------
def camera_onvif_thread():
    """
    Watek roboczy oparty na asyncio.
    Dziala w tle (jako osobny thread Pythona). W srodku uruchamia asyncio event loop ktory:
      - Trzyma HTTP server na porcie 8585 (webhook od kamer)
      - Trzyma taski per kamera (subskrypcja + renew co 8 min)
      - Co CAMERA_REFRESH_INTERVAL sekund _sync_cameras() adaptuje stan do DB
    """
    db_connect()

    import asyncio
    from aiohttp import web

    # Aktywne taski per kamera: cam_device_id -> asyncio.Task
    _active_tasks = {}
    # Aktywne konfiguracje: cam_device_id -> cam_cfg dict
    _active_cfgs = {}
    # Cache modeli kamer (zeby nie pytac przy kazdym sync)
    _camera_models = {}

    async def handle_onvif_notify(request):
        """
        Handler webhook POST od kamery. Parsuje SOAP, publikuje eventy na MQTT.
        URL: http://<tymos_ip>:8585/onvif (lub dowolny path — accept wszystko).
        """
        try:
            body = await request.text()
            peer = request.remote or ""

            cam_name = None
            for ip, name in camera_ip_to_name.items():
                if ip in peer or ip in body:
                    cam_name = name
                    break
            if not cam_name:
                cam_name = "unknown"

            events = parse_onvif_event(body)
            for event_type, is_active, topic in events:
                cam_device_id = "cam_%s" % next(
                    (ip.replace(".", "_") for ip, name in camera_ip_to_name.items() if name == cam_name),
                    cam_name)
                mqtt_topic = "%s/camera/%s" % (MQTT_TOPIC_PREFIX, cam_name)
                payload = {event_type: 1 if is_active else 0, "device_id": cam_device_id}
                mqtt_publish(mqtt_topic, payload)
                register_device(cam_name, cam_device_id, "ONVIF Camera", {event_type: is_active})
                # Debug: event do journal (nie do log table zeby nie spamowac)
                print("[INFO] onvif: [%s] %s=%s topic=%s" % (cam_name, event_type, 1 if is_active else 0, topic[:80]))

            # Gdy webhook przyszedl ale parser nie wyciagnal eventu — dump RAW body do /tmp/tymos/
            # (tmpfs, zero zapisow SD). Raz na 30s max (throttle), zeby nie puchlo.
            if not events and body:
                try:
                    import time as _t, os as _os
                    _throttle_file = "/tmp/tymos/onvif_last_dump.ts"
                    now = int(_t.time())
                    last = 0
                    try:
                        if _os.path.exists(_throttle_file):
                            with open(_throttle_file) as f: last = int(f.read().strip() or 0)
                    except Exception: pass
                    if now - last >= 30:
                        with open(_throttle_file, 'w') as f: f.write(str(now))
                        path = "/tmp/tymos/onvif_raw_%s_%d.xml" % (cam_name, now)
                        with open(path, 'w') as f: f.write(body)
                        print("[WARN] onvif: [%s] webhook bez rozpoznanego eventu, dump -> %s (len=%d)" % (cam_name, path, len(body)))
                except Exception:
                    pass

        except Exception as e:
            log("ERROR", "onvif_callback", str(e))

        return web.Response(status=200, text="OK")

    async def subscribe_camera(cam_cfg, callback_url, cam_model="ONVIF Camera"):
        """
        Subskrybuje eventy ONVIF na kamerze, odnawia co ONVIF_RENEW_INTERVAL.
        Gdy kamera offline — czeka na powrot (reachability check co 5s).
        Przerywana przez shutdown_event (SIGTERM) lub anulowanie taska (zmiana konfiguracji).
        """
        from onvif import ONVIFCamera
        import socket as _sock

        name = cam_cfg["name"]
        cam_device_id = "cam_%s" % cam_cfg["ip"].replace(".", "_")
        cam_ip = cam_cfg["ip"]
        cam_port = cam_cfg.get("onvif_port", 2020)

        def _is_reachable():
            try:
                s = _sock.socket(_sock.AF_INET, _sock.SOCK_STREAM)
                s.settimeout(2)
                s.connect((cam_ip, cam_port))
                s.close()
                return True
            except Exception:
                return False

        while not shutdown_event.is_set():
            if is_disabled(cam_device_id):
                await asyncio.sleep(60)
                continue

            if not _is_reachable():
                await asyncio.sleep(10)
                continue

            try:
                cam = ONVIFCamera(cam_ip, cam_port, cam_cfg["user"], cam_cfg["pass"],
                                  wsdl_dir=ONVIF_WSDL_DIR)
                await cam.update_xaddrs()
                notification_service = await cam.create_notification_service()

                subscribe_request = {
                    "ConsumerReference": {"Address": callback_url},
                    "InitialTerminationTime": "PT10M",
                }
                response = await notification_service.Subscribe(subscribe_request)
                log("INFO", "onvif", "[%s] Subscribed (webhook -> %s)" % (name, callback_url))

                register_device(name, cam_device_id, cam_model, {
                    "person": {"sql": "TINYINT(1)", "label": "Person"},
                    "motion": {"sql": "TINYINT(1)", "label": "Motion"},
                    "vehicle": {"sql": "TINYINT(1)", "label": "Vehicle"},
                })

                while not shutdown_event.is_set():
                    await asyncio.sleep(ONVIF_RENEW_INTERVAL)
                    if not _is_reachable():
                        log("WARN", "onvif", "[%s] Kamera niedostepna — czekam na powrot" % name)
                        while not shutdown_event.is_set():
                            await asyncio.sleep(5)
                            if _is_reachable():
                                log("INFO", "onvif", "[%s] Kamera wrocila — resubscribe" % name)
                                await asyncio.sleep(3)
                                break
                        break
                    try:
                        response = await notification_service.Subscribe(subscribe_request)
                        print("[INFO] onvif_bridge:onvif: [%s] Renewed subscription" % name)
                        # Keepalive last_seen — bez tego kamera bez zdarzen (person/motion) zostanie
                        # uznana za offline przez availability_check.php mimo ze subskrypcja zyje.
                        try:
                            db_exec("UPDATE devices SET last_seen=NOW() WHERE ieee=%s", (cam_device_id,))
                        except Exception:
                            pass
                    except Exception as e:
                        log("ERROR", "onvif_renew", "[%s] %s — resubscribe" % (name, e))
                        break

            except Exception as e:
                log("ERROR", "onvif_subscribe", "[%s] %s" % (name, e))
                await asyncio.sleep(10)

    def _cfg_key(cfg):
        """Klucz porownawczy — zmiana dowolnego pola = restart subskrypcji."""
        return (cfg["ip"], cfg["onvif_port"], cfg["user"], cfg["pass"], cfg["name"])

    async def _start_camera(cam_cfg, callback_url):
        """Pobiera model kamery (one-time) i uruchamia task subscribe_camera."""
        cam_device_id = "cam_%s" % cam_cfg["ip"].replace(".", "_")
        camera_ip_to_name[cam_cfg["ip"]] = cam_cfg["name"]

        if cam_cfg["name"] not in _camera_models:
            try:
                from onvif import ONVIFCamera as _ONVIF
                _cam = _ONVIF(cam_cfg["ip"], cam_cfg.get("onvif_port", 2020),
                              cam_cfg["user"], cam_cfg["pass"], wsdl_dir=ONVIF_WSDL_DIR)
                await _cam.update_xaddrs()
                dm = await _cam.create_devicemgmt_service()
                info = await dm.GetDeviceInformation()
                cam_model = "%s %s" % (info.Manufacturer or "ONVIF", info.Model or "Camera")
                _camera_models[cam_cfg["name"]] = cam_model
                register_device(cam_cfg["name"], cam_device_id, cam_model, {}, ip=cam_cfg["ip"])
                log("INFO", "onvif", "[%s] Model: %s" % (cam_cfg["name"], cam_model))
            except Exception as e:
                log("ERROR", "onvif_model", "[%s] %s" % (cam_cfg["name"], e))

        model = _camera_models.get(cam_cfg["name"], "ONVIF Camera")
        task = asyncio.create_task(subscribe_camera(cam_cfg, callback_url, model))
        _active_tasks[cam_device_id] = task
        _active_cfgs[cam_device_id] = cam_cfg

    def _stop_camera(cam_device_id):
        """Anuluje task subskrypcji i usuwa kamere z aktywnych."""
        task = _active_tasks.pop(cam_device_id, None)
        cfg = _active_cfgs.pop(cam_device_id, None)
        if task and not task.done():
            task.cancel()
        if cfg:
            camera_ip_to_name.pop(cfg["ip"], None)
            log("INFO", "onvif", "[%s] Odsubskrybowano (usunieto/zmieniono)" % cfg["name"])

    async def _sync_cameras(callback_url):
        """
        Porownuje liste kamer w DB z aktualnie aktywnymi taskami i:
          - Startuje taski dla nowych kamer (dodane w GUI)
          - Zatrzymuje i startuje nowe gdy konfiguracja zmieniona (ip/port/user/pass)
          - Zatrzymuje taski dla usunietych (deleted=1)
          - Restartuje taski martwe (task.done() bez shutdown_event)
        Przy okazji odswieza `_enabled_set` — jeden mechanizm zamiast dwoch.
        """
        refresh_enabled_set()
        new_cams = load_cameras_from_db()
        new_by_id = {}
        for cam in new_cams:
            did = "cam_%s" % cam["ip"].replace(".", "_")
            new_by_id[did] = cam

        # Usuniete kamery
        for did in list(_active_cfgs.keys()):
            if did not in new_by_id:
                _stop_camera(did)

        # Nowe / zmienione / martwe taski
        for did, cam in new_by_id.items():
            old_cfg = _active_cfgs.get(did)
            if old_cfg is None:
                log("INFO", "onvif", "[%s] Nowa kamera — subskrybuje" % cam["name"])
                await _start_camera(cam, callback_url)
            elif _cfg_key(old_cfg) != _cfg_key(cam):
                log("INFO", "onvif", "[%s] Zmiana konfiguracji — resubskrybuje" % cam["name"])
                _stop_camera(did)
                await _start_camera(cam, callback_url)
            else:
                task = _active_tasks.get(did)
                if task and task.done():
                    log("WARN", "onvif", "[%s] Task martwy — restart" % cam["name"])
                    await _start_camera(cam, callback_url)

    async def _run():
        """Glowny async entrypoint: serwer HTTP + petla sync kamer."""
        import socket
        # Wykryj IP tego hosta w LAN (uzywane w callback URL dla kamer)
        import config
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        try:
            s.connect((config.get('LAN_GATEWAY'), 80))
            my_ip = s.getsockname()[0]
        except Exception as e:
            my_ip = config.get('TYMOS_IP_LAN')
            log("ERROR", "onvif", "Nie wykryto IP hosta, fallback %s — webhooks kamer moga nie dzialac: %s" % (my_ip, e))
        finally:
            s.close()
        callback_url = "http://%s:%d/onvif" % (my_ip, ONVIF_CALLBACK_PORT)
        log("INFO", "onvif", "Callback URL: %s" % callback_url)

        app = web.Application()
        app.router.add_post("/onvif", handle_onvif_notify)
        app.router.add_post("/{path:.*}", handle_onvif_notify)
        runner = web.AppRunner(app)
        await runner.setup()
        site = web.TCPSite(runner, "0.0.0.0", ONVIF_CALLBACK_PORT)
        await site.start()
        log("INFO", "onvif", "HTTP callback serwer na porcie %d" % ONVIF_CALLBACK_PORT)

        # Pierwszy sync — zaladuj i zasubskrybuj kamery
        await _sync_cameras(callback_url)

        # Petla — co CAMERA_REFRESH_INTERVAL sprawdzaj zmiany w DB
        while not shutdown_event.is_set():
            await asyncio.sleep(CAMERA_REFRESH_INTERVAL)
            if shutdown_event.is_set():
                break
            try:
                await _sync_cameras(callback_url)
            except Exception as e:
                log("ERROR", "onvif_sync", str(e))

    asyncio.run(_run())


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
def main():
    """
    Glowna funkcja daemona.
    1. Polaczenie DB z retry (30 prob x 5s) + zaladuj registered_devices.
    2. Polaczenie MQTT z retry (30 prob x 5s).
    3. Uruchom watek camera_onvif_thread (asyncio).
    4. Glowna petla: co DB_PING_INTERVAL ping DB + watchdog watku.
    """
    global mqtt_client

    print("TymOS ONVIF Bridge uruchamiany (PID %d)" % os.getpid())

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

    load_registered_devices()

    mqtt_client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id="onvif_bridge")
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

    t = threading.Thread(target=camera_onvif_thread, name="camera_onvif", daemon=True)
    t.start()
    print("Watek camera_onvif uruchomiony")

    # Glowna petla — watchdog + ping DB
    while not shutdown_event.is_set():
        shutdown_event.wait(timeout=DB_PING_INTERVAL)
        if shutdown_event.is_set():
            break
        try:
            db_ping()
            if not t.is_alive():
                log("ERROR", "watchdog", "Watek camera_onvif martwy — restart")
                t = threading.Thread(target=camera_onvif_thread, name="camera_onvif", daemon=True)
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
