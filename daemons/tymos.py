#!/usr/bin/env python3
"""TymOS — nasluchiwanie MQTT (Zigbee2MQTT) + zapis do MariaDB
v3: stabilnosc, reverse mapy, atomic reload, sun UTC fix"""

import json
import sys
import os
import time
import math
import signal
import threading
import queue
import subprocess
import unicodedata
from datetime import datetime, timedelta, timezone
from concurrent.futures import ThreadPoolExecutor
import paho.mqtt.client as mqtt

# Wspolny modul DB + log (daemons/lib/db.py) — zastepuje dawny lokalny db_connect/db_exec/db_log
from lib.db import db_connect, db_ping, db_exec, db_log

def utcnow_naive():
    """Zamiennik datetime.utcnow() (deprecated w 3.12+). Zwraca naive UTC — zgodne z istniejacymi porownaniami."""
    return datetime.now(timezone.utc).replace(tzinfo=None)

BROKER = "localhost"
PORT = 1883
MQTT_USER = ""
MQTT_PASS = ""
TOPIC = "zigbee2mqtt/#"
TOPIC_ONVIF = "tymos/onvif/#"
TOPIC_BLEBOX = "tymos/blebox/#"
TOPIC_ESPHOME = "tymos/esphome/#"

# Lokalizacja domu: HOME_LAT/HOME_LON z config.inc.php
import config
SUN_LAT = float(config.get('HOME_LAT'))
SUN_LON = float(config.get('HOME_LON'))

# Cache: ieee -> {"id": int, "device": str, "fields": {...}, "enabled": bool, "enabled_fields": list|None}
device_cache = {}
# Reverse mapa: dev_id (int) -> ieee (szybki lookup po ID)
id_to_ieee = {}
# Mapa: friendly_name -> ieee (budowana z bridge/devices)
name_to_ieee = {}
# Mapa: ieee -> z2m friendly_name (do sterowania Zigbee)
ieee_to_z2m_name = {}
# Mapa: ieee -> {field: {"unit": "W", "label": "Power"}} (z bridge/devices exposes)
device_exposes = {}
# Cache akcji i helperow
actions_cache = []
helpers_cache = {}
# Indeks akcji: dev_id (str) -> [action, ...] (event/threshold triggery)
_action_index = {}
# Mapa: dev_id (int) -> set(field, ...) — pola uzywane w warunkach `last_activity` z filtrem value.
# Dzieki temu cache `__val_ts` jest trzymany TYLKO dla faktycznie potrzebnych (field, value) par
# (zamiast bez potrzeby rozdmuchiwac pamiec dla kazdego dyskretnego pola).
_last_activity_used = {}
# Stan poprzednich wartosci (dla threshold — wykrywanie przejscia)
prev_values = {}  # key: "dev_id:field" -> last_value
# Tabele juz stworzone w tej sesji (nie powtarzaj DDL)
_tables_created = set()
# Licznik dropniętych wiadomosci (queue full)
_drop_count = 0

# --- BRAMKA SWIEZOSCI ODCZYTOW (fail-safe) ---
# Czujka Zigbee moze wypasc z sieci, a jej ostatnia wartosc zostaje w last_state i wyglada jak zywa.
# Warunek akcji oparty na TAKIM polu = sterowanie na oslep (awaria 2026-08-19: grzalki grzaly 95 min
# na zamrozonym odczycie). Odczyt starszy niz prog -> warunek FALSE, czyli akcja NIE odpala.
# Bramka dotyczy tylko pol POMIAROWYCH (temperatura/wilgotnosc/...). Pol stanowych (state, position,
# contact, occupancy) NIE bramkujemy — one z natury nie zmieniaja sie godzinami.
# Progi zmierzone na 60 dniach (max normalny odstep raportu): wiekszosc czujek <= 75 min,
# bufor 120 min, dev10 "Patio" (bateryjny, slaby mesh) do 208 min -> ma wlasny, luzniejszy prog.
STALE_MIN_DEFAULT = 120
STALE_MIN_BY_DEV = {"10": 240}
STALE_FIELDS = ("temperature", "humidity", "co2", "pressure", "illuminance", "soil_moisture",
                "outdoor_air_temperature", "extract_air_temperature", "supply_air_temperature",
                "extract_air_humidity", "running_mean_outdoor_temperature")

# --- CZUJNIK ZAPASOWY (druga czujka w tym samym pomieszczeniu) ---
# Salon ma dwie czujki lezace obok siebie. GLOWNA = dev684 (SNZB-02D, z ekranem): trzyma
# `max_report_interval` co do sekundy, raport co 30 min jak w zegarku. ZAPASOWA = dev852
# (SNZB-02P): to samo ustawienie honoruje luzno, obserwowane dziury do 55 min (analiza 2026-09-19,
# 9 h po rejoinie) — dlatego role sa takie, a nie odwrotne. Gdy glowna milczy dluzej niz
# FALLBACK_MAX_AGE, push z zapasowej zapisujemy TAKZE pod glowna, wiec caly system (akcje,
# skrypty, kiosk) czyta dalej JEDNO urzadzenie i nigdy nie stoi na martwym odczycie.
# Kalibracji swiadomie NIE ma: roznica miedzy czujkami zmienia sie w ciagu doby (user 2026-09-19).
SENSOR_FALLBACK = {852: {"target": 684, "fields": ("temperature", "humidity")}}
FALLBACK_MAX_AGE = 3600   # sekund ciszy glownej czujki, po ktorych wchodzi zapasowa
# Stemple PRAWDZIWYCH pushow ("dev:field" -> epoch) — bez nich wlasny zapis fallbacku odmladzalby
# glowna czujke i zapasowa zamilklaby na godzine.
_push_real_ts = {}

def _is_stale(dev_id, field, cache):
    """True gdy pole pomiarowe nie bylo raportowane dluzej niz prog (albo nie ma znacznika czasu)."""
    if field not in STALE_FIELDS:
        return False
    limit = STALE_MIN_BY_DEV.get(str(dev_id), STALE_MIN_DEFAULT) * 60
    ts = (cache.get("__ts") or {}).get(field)
    if not ts:
        return False          # brak znacznika (stary wpis w DB) — nie blokuj, niech dziala jak dotad
    try:
        return (time.time() - float(ts)) > limit
    except (ValueError, TypeError):
        return False

# Debug payload — flaga z settings, sprawdzana co 10s (przy reload check)
_debug_payload = False

# --- Obliczenia sloneczne (NOAA) ---

_sun_cache = {}  # date -> {"sunrise": datetime, "sunset": datetime}

def sun_times(dt=None):
    """Oblicza wschod/zachod slonca (Spencer 1971, dokladnosc ~1 min).
    Zwraca dict {"sunrise": datetime, "sunset": datetime} w UTC."""
    if dt is None:
        dt = utcnow_naive()
    day = dt.date()
    if day in _sun_cache:
        return _sun_cache[day]

    n = dt.timetuple().tm_yday
    B = 2 * math.pi * (n - 1) / 365.0
    # Rownanie czasu (minuty) — Spencer
    eot = 229.18 * (0.000075 + 0.001868 * math.cos(B) - 0.032077 * math.sin(B)
                     - 0.014615 * math.cos(2*B) - 0.04089 * math.sin(2*B))
    # Deklinacja slonca (radiany) — Spencer
    decl = (0.006918 - 0.399912 * math.cos(B) + 0.070257 * math.sin(B)
            - 0.006758 * math.cos(2*B) + 0.000907 * math.sin(2*B)
            - 0.002697 * math.cos(3*B) + 0.00148 * math.sin(3*B))
    lat_rad = math.radians(SUN_LAT)
    # Kat godzinny (refrakcja -0.833 stopnia)
    cos_ha = (math.sin(math.radians(-0.833)) - math.sin(lat_rad) * math.sin(decl)) / (math.cos(lat_rad) * math.cos(decl))
    cos_ha = max(-1.0, min(1.0, cos_ha))
    ha = math.degrees(math.acos(cos_ha))
    # Poludnie sloneczne UTC (minuty od polnocy)
    solar_noon = 720 - 4 * SUN_LON - eot
    sunrise_min = solar_noon - ha * 4
    sunset_min = solar_noon + ha * 4

    base = datetime(day.year, day.month, day.day)
    result = {
        "sunrise": base + timedelta(minutes=sunrise_min),
        "sunset": base + timedelta(minutes=sunset_min),
    }
    _sun_cache[day] = result
    for k in [k for k in _sun_cache if (day - k).days > 2]:
        _sun_cache.pop(k, None)
    return result

# Kolejka MQTT -> worker
msg_queue = queue.Queue(maxsize=5000)

# Shutdown event
shutdown_event = threading.Event()

# MQTT client (globalny — uzywany do publish w akcjach)
mqtt_client = None

# --- Urzadzenia ---

def table_name(dev_id):
    return "device%d" % dev_id

def python_to_sql_type(val):
    # WAZNE: bool musi byc PRZED int — w Pythonie `isinstance(True, int) == True`.
    if isinstance(val, bool):
        return "TINYINT(1)"
    # Wszystkie liczby (int i float) jako DOUBLE — zapobiega obcinaniu ulamkow gdy
    # pierwszy push ma wartosc calkowita (np. energy=0) a kolejne floaty (energy=15.42).
    if isinstance(val, (int, float)):
        return "DOUBLE"
    if isinstance(val, str):
        if len(val) <= 32:
            return "VARCHAR(64)"
        return "TEXT"
    return None

def flatten_payload(payload):
    flat = {}
    if not isinstance(payload, dict):
        return flat
    for key, val in payload.items():
        if isinstance(val, (dict, list)):
            continue
        flat[key] = val
    return flat

def load_device_cache():
    global device_cache, id_to_ieee
    new_cache = {}
    new_id_map = {}
    rows = db_exec("SELECT id, device, ieee, fields, enabled_fields, model, available FROM devices WHERE deleted=0")
    if rows is None:
        print("WARN: load_device_cache — blad DB, zachowuje stary cache (%d)" % len(device_cache), file=sys.stderr)
        return
    if rows:
        for dev_id, device, ieee, fields_json, enabled_fields_json, model, z2m_avail in rows:
            if ieee:
                try:
                    ef = json.loads(enabled_fields_json) if enabled_fields_json else None
                    fields = json.loads(fields_json) if fields_json else {}
                except (json.JSONDecodeError, TypeError) as e:
                    print("WARN: zepsuty JSON w devices id=%s: %s — pomijam" % (dev_id, e), file=sys.stderr)
                    continue
                new_cache[ieee] = {
                    "id": dev_id,
                    "device": device,
                    "fields": fields,
                    "enabled": bool(ef and len(ef) > 0),
                    "enabled_fields": ef,
                    "model": model or "",
                    "available": z2m_avail,
                }
                new_id_map[dev_id] = ieee
    device_cache = new_cache  # atomic swap
    id_to_ieee = new_id_map
    print("Cache: %d urzadzen" % len(device_cache))

def _extract_exposes(exposes):
    result = {}
    for exp in exposes:
        if "features" in exp:
            result.update(_extract_exposes(exp["features"]))
        elif "property" in exp:
            info = {}
            if "unit" in exp:
                info["unit"] = exp["unit"]
            if "label" in exp:
                info["label"] = exp["label"]
            if "description" in exp:
                info["desc"] = exp["description"]
            if "access" in exp:
                info["access"] = exp["access"]
            if "type" in exp:
                info["z2m_type"] = exp["type"]
            if "values" in exp:
                info["values"] = exp["values"]
            if "value_min" in exp:
                info["value_min"] = exp["value_min"]
            if "value_max" in exp:
                info["value_max"] = exp["value_max"]
            if "category" in exp:
                info["category"] = exp["category"]
            result[exp["property"]] = info
    return result

def process_bridge_devices(payload):
    """
    Obsluga retained `zigbee2mqtt/bridge/devices` (Z2M publikuje liste urzadzen przy starcie
    i zmianie konfiguracji). Buduje TYLKO pamieciowe mapy potrzebne daemonowi do routingu
    i typowania pol (nie zapisuje do DB — to robi systemowy skrypt z2m_sync_devices.php):
      - name_to_ieee[friendly_name] = ieee   (routing push Zigbee -> dev_id)
      - ieee_to_z2m_name[ieee]       = name  (odwrotna, do sterowania przez MQTT set)
      - device_exposes[ieee]         = {field: {unit, label, access, z2m_type, values, ...}}
        — daemon uzywa z2m_type/values zeby wymusic VARCHAR dla enum zamiast BIGINT.
      - W cache devices.fields (tylko pamiec) dokonuje merge metadata z exposes.
    Zapisy DB (model, last_state options, writable fields, no_occupancy_since) robi
    `actions/z2m_sync_devices.php` co 15 min.
    Zapisy available/offline robi `actions/availability_check.php` co 5 min.
    """
    if not isinstance(payload, list):
        return
    for dev in payload:
        ieee = dev.get("ieee_address")
        name = dev.get("friendly_name")
        if not ieee or not name:
            continue
        if name == "Coordinator":
            continue
        name_to_ieee[name] = ieee
        ieee_to_z2m_name[ieee] = name
        definition = dev.get("definition") or {}
        exposes = definition.get("exposes", [])
        if exposes:
            device_exposes[ieee] = _extract_exposes(exposes)
        # Aktualizuj pamieciowy cache urzadzenia (bez DB writes)
        cached = device_cache.get(ieee)
        if not cached:
            continue
        model = definition.get("model", "")
        if model and cached.get("model") != model:
            cached["model"] = model
        # Merge metadata z exposes do istniejacych fields (tylko pamiec)
        if exposes and cached.get("fields"):
            exp_map = device_exposes.get(ieee, {})
            for fname, finfo in cached["fields"].items():
                if not isinstance(finfo, dict):
                    continue
                exp = exp_map.get(fname, {})
                for mk in ("access", "z2m_type", "values", "value_min", "value_max", "category"):
                    if mk in exp and finfo.get(mk) != exp[mk]:
                        finfo[mk] = exp[mk]
    print("Mapa name->ieee: %d urzadzen" % len(name_to_ieee))

def build_field_info(key, val, ieee):
    sql_type = python_to_sql_type(val)
    if not sql_type:
        return None
    info = {"sql": sql_type}
    exp = device_exposes.get(ieee, {}).get(key, {})
    for k in ("unit", "label", "access", "z2m_type", "values", "value_min", "value_max", "category"):
        if k in exp:
            info[k] = exp[k]
    # Enum/binary z Z2M → wymusz VARCHAR (np. state ON/OFF trafia jako BIGINT gdy pierwsza wartosc to int)
    if exp.get("z2m_type") in ("enum", "binary") or "values" in exp:
        info["sql"] = "VARCHAR(64)"
    # Numeric z unit pomiarowym → wymusz DOUBLE. Bez tego pierwszy push z calkowita wartoscia
    # (np. energy=0) tworzy BIGINT i kolejne floaty (energy=15.42) traca ulamki.
    # Wykluczamy unit-y ktore sa naturalnie calkowite: lqi (signal), seconds (czas).
    elif exp.get("z2m_type") == "numeric":
        unit = (exp.get("unit") or "").lower()
        if unit and unit not in ("lqi", "seconds", "s"):
            info["sql"] = "DOUBLE"
    return info

SQL_TYPES = {"TINYINT", "BIGINT", "INT", "DOUBLE", "FLOAT", "VARCHAR", "TEXT", "DATETIME"}

def get_field_sql(field_info):
    if isinstance(field_info, dict):
        return field_info.get("sql", "TEXT")
    if isinstance(field_info, str):
        upper = field_info.split("(")[0].upper()
        if upper in SQL_TYPES:
            return field_info
        return "VARCHAR(64)" if len(field_info) <= 32 else "TEXT"
    return python_to_sql_type(field_info) or "TEXT"

def get_active_fields(cached):
    if cached["enabled_fields"]:
        return {k: v for k, v in cached["fields"].items() if k in cached["enabled_fields"]}
    return {}

def find_or_register(friendly_name, ieee, payload, flat=None):
    if flat is None:
        flat = flatten_payload(payload)
    field_types = {}
    for key, val in flat.items():
        info = build_field_info(key, val, ieee)
        if info:
            field_types[key] = info

    if ieee in device_cache:
        cached = device_cache[ieee]
        if cached["device"] != friendly_name:
            print("Zmiana nazwy Z2M: %s -> %s (ieee=%s)" % (cached["device"], friendly_name, ieee))
            db_exec("UPDATE devices SET device=%s WHERE id=%s", (friendly_name, cached["id"]))
            cached["device"] = friendly_name

        updated = False
        for key, info in field_types.items():
            if key not in cached["fields"]:
                cached["fields"][key] = info
                updated = True
                if cached["enabled_fields"] and key in cached["enabled_fields"]:
                    try:
                        db_exec("ALTER TABLE `%s` ADD COLUMN `%s` %s DEFAULT NULL" % (table_name(cached["id"]), key, get_field_sql(info)))
                        print("Nowa kolumna: %s.%s (%s)" % (table_name(cached["id"]), key, get_field_sql(info)))
                    except Exception as e:
                        db_log("ERROR", "alter_table", "ADD COLUMN %s.%s (%s) BLAD: %s" % (table_name(cached["id"]), key, get_field_sql(info), e))
            else:
                old = cached["fields"][key]
                if isinstance(old, str):
                    cached["fields"][key] = info
                    updated = True
                elif isinstance(old, dict):
                    for mk in ("unit", "label", "access", "z2m_type", "values", "value_min", "value_max", "category"):
                        if mk not in old and mk in info:
                            old[mk] = info[mk]
                            updated = True
        if updated:
            db_exec("UPDATE devices SET fields=%s, last_seen=NOW() WHERE id=%s",
                    (json.dumps(cached["fields"]), cached["id"]))
            last_seen_update[ieee] = time.time()
        else:
            now_t = time.time()
            if (now_t - last_seen_update.get(ieee, 0)) >= LAST_SEEN_THROTTLE:
                db_exec("UPDATE devices SET last_seen=NOW() WHERE id=%s", (cached["id"],))
                last_seen_update[ieee] = now_t
    else:
        # Moze byc urzadzenie z deleted=1 — jest w DB, ale load_device_cache go nie laduje
        # (filtr WHERE deleted=0). Bez tego checku kazdy push dawalby Duplicate entry error w logach.
        existing = db_exec("SELECT id FROM devices WHERE ieee=%s", (ieee,))
        if existing:
            return
        db_exec(
            "INSERT INTO devices (device, ieee, fields) VALUES (%s, %s, %s)",
            (friendly_name, ieee, json.dumps(field_types))
        )
        rows = db_exec("SELECT id FROM devices WHERE ieee=%s", (ieee,))
        if not rows:
            print("WARN: INSERT urzadzenia %s nie powiodl sie — pomijam" % ieee, file=sys.stderr)
            return
        dev_id = rows[0][0]
        device_cache[ieee] = {
            "id": dev_id,
            "device": friendly_name,
            "fields": field_types,
            "enabled": False,
            "enabled_fields": [],
        }
        id_to_ieee[dev_id] = ieee
        # Twórz tabelę od razu przy discovery (wszystkie pola z payloadu)
        create_device_table(ieee, all_fields=True)
        _tables_created.add(ieee)
        db_log("INFO", "discovery", "Nowe: %s ieee=%s (id=%d)" % (friendly_name, ieee, dev_id))
        # New device — force an immediate full metadata sync from Z2M (model/fields/last_state)
        # instead of waiting up to 15 min for system_z2m_sync_devices. Fire-and-forget.
        try:
            subprocess.Popen(["php", "/opt/tymos/actions/z2m_sync_devices.php"],
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                             start_new_session=True)
        except Exception as e:
            db_log("WARN", "discovery", "sync trigger blad: %s" % e)

def create_device_table(ieee, all_fields=False):
    cached = device_cache.get(ieee)
    if not cached:
        return
    tbl = table_name(cached["id"])
    fields = cached["fields"] if all_fields else get_active_fields(cached)
    cols = ["ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"]
    for field, info in fields.items():
        cols.append("`%s` %s DEFAULT NULL" % (field, get_field_sql(info)))
    cols.append("INDEX idx_ts (ts)")
    sql = "CREATE TABLE IF NOT EXISTS `%s` (%s) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" % (tbl, ", ".join(cols))
    db_exec(sql)
    existing = set()
    rows = db_exec("SHOW COLUMNS FROM `%s`" % tbl)
    if rows:
        for row in rows:
            existing.add(row[0])
    for field, info in fields.items():
        if field not in existing:
            try:
                db_exec("ALTER TABLE `%s` ADD COLUMN `%s` %s DEFAULT NULL" % (tbl, field, get_field_sql(info)))
                print("Dodano kolumne: %s.%s (%s)" % (tbl, field, get_field_sql(info)))
            except Exception as e:
                db_log("ERROR", "alter_table", "ADD COLUMN %s.%s (%s) BLAD: %s" % (tbl, field, get_field_sql(info), e))

DB_WRITE_THROTTLE = 30
last_db_write = {}
LAST_SEEN_THROTTLE = 60
last_seen_update = {}

def _is_empty(v):
    if v is None:
        return True
    if isinstance(v, str) and v.strip() == "":
        return True
    return False

def insert_device_data(ieee, payload, flat=None):
    cached = device_cache.get(ieee)
    if not cached or not cached["enabled"]:
        return
    if flat is None:
        flat = flatten_payload(payload)
    if not flat:
        return
    # Pomijaj throttling gdy istotne pole zmienilo wartosc (nie meta)
    _ignore_change = {"linkquality", "rssi", "battery", "voltage"}
    value_changed = False
    for key, val in flat.items():
        if key in _ignore_change:
            continue
        pkey = "%s:%s" % (cached["id"], key)
        prev = prev_values.get(pkey)
        if prev is not None and str(prev) != str(val):
            value_changed = True
            break
    now = time.time()
    last = last_db_write.get(ieee, 0)
    if not value_changed and (now - last) < DB_WRITE_THROTTLE:
        return
    last_db_write[ieee] = now
    if ieee not in _tables_created:
        create_device_table(ieee)
        _tables_created.add(ieee)
    active_fields = get_active_fields(cached)
    keys = []
    cols = []
    vals = []
    for key, val in flat.items():
        if key in active_fields:
            keys.append(key)
            cols.append("`%s`" % key)
            vals.append(val)
    if not cols:
        return
    # Nie zapisuj rekordu jesli wszystkie wartosci sa None/puste/whitespace (pusty ping)
    if all(_is_empty(v) for v in vals):
        return
    # Nie zapisuj jesli jedyne niepuste pola to meta (heartbeat) lub sam state bez danych
    meta_fields = {"linkquality", "rssi", "battery", "voltage", "power_on_behavior"}
    data_vals = [v for k, v in zip(keys, vals) if k not in meta_fields]
    if not data_vals or all(_is_empty(v) for v in data_vals):
        return
    tbl = table_name(cached["id"])
    placeholders = ", ".join(["%s"] * len(vals))
    sql = "INSERT INTO `%s` (%s) VALUES (%s)" % (tbl, ", ".join(cols), placeholders)
    db_exec(sql, tuple(vals))

def save_debug_payload(topic, payload_bytes, dev_id):
    if not _debug_payload:
        return
    try:
        raw = payload_bytes.decode("utf-8", errors="replace")[:4000]
        db_exec("INSERT INTO debug_payload (device_id, topic, payload) VALUES (%s, %s, %s)",
                (dev_id, topic, raw))
    except Exception as e:
        print("WARN: debug_payload zapis: %s" % e, file=sys.stderr)

def extract_device(topic):
    parts = topic.split("/", 2)
    if len(parts) >= 2:
        return parts[1]
    return topic

# --- Wspolna obsluga device push (akcje + prev_values) ---

_last_state_cache = {}  # ieee -> merged dict
_last_state_write = {}  # ieee -> last write time

def _init_last_state_cache():
    """Laduj last_state z DB przy starcie — zeby nie tracic rzadkich pol po restarcie.
    Jednorazowa migracja kluczy __val_ts: "True"/"False" (stary format str(bool)) -> "1"/"0"
    zeby zgadzaly sie z filtrami value w akcjach GUI."""
    rows = db_exec("SELECT ieee, last_state FROM devices WHERE last_state IS NOT NULL")
    if rows:
        for ieee, ls in rows:
            if ls:
                try:
                    data = json.loads(ls)
                    vts = data.get("__val_ts")
                    if isinstance(vts, dict):
                        for field, vmap in vts.items():
                            if isinstance(vmap, dict):
                                if "True" in vmap:
                                    vmap["1"] = vmap.pop("True")
                                if "False" in vmap:
                                    vmap["0"] = vmap.pop("False")
                    _last_state_cache[ieee] = data
                except (json.JSONDecodeError, TypeError):
                    pass
    print("last_state cache: %d urzadzen" % len(_last_state_cache))

def _sensor_fallback(src_id, flat):
    """Push z czujki zapasowej przepisz na glowna, gdy glowna milczy dluzej niz FALLBACK_MAX_AGE."""
    fb = SENSOR_FALLBACK.get(src_id)
    if not fb:
        return
    tgt = fb["target"]
    tgt_ieee = id_to_ieee.get(tgt)
    if not tgt_ieee:
        return
    ts_cache = (_last_state_cache.get(tgt_ieee) or {}).get("__ts") or {}
    now = time.time()
    sub = {}
    for f in fb["fields"]:
        if f not in flat:
            continue
        # Po restarcie demona nie znamy jeszcze prawdziwych pushow — bierzemy stempel z cache.
        real = _push_real_ts.get("%s:%s" % (tgt, f))
        if real is None:
            real = ts_cache.get(f, 0)
        if now - real > FALLBACK_MAX_AGE:
            sub[f] = flat[f]
    if not sub:
        return
    db_log("INFO", "czujnik_zapasowy", "dev%s -> dev%s: %s" % (src_id, tgt, sub))
    try:
        insert_device_data(tgt_ieee, None, sub)
    except Exception as e:
        db_log("ERROR", "czujnik_zapasowy", "insert dev%s: %s" % (tgt, e))
    _handle_device_push(tgt, sub, fallback=True)

def _handle_device_push(dev_id, flat, fallback=False):
    """
    Odpala akcje i aktualizuje prev_values + last_state — wspolne dla Zigbee/ONVIF/BleBox/ESPHome.

    Co trzymamy w `_last_state_cache[ieee]` (per urzadzenie):
      - Zwykle klucze:     field -> aktualna wartosc  (np. "occupancy": true, "state": "ON")
      - `__ts` meta:       {field: epoch}  — timestamp ostatniego push tego pola (dla last_activity bez val filter)
      - `__val_ts` meta:   {field: {str(value): epoch}}  — ostatni push pola Z KONKRETNA wartoscia
                                                             (dla last_activity z filtrem, np. occupancy=1)
                           Trzymane tylko dla pol dyskretnych (bool + string <= 32 znakow).
                           Limit 20 unikalnych wartosci per pole (LRU), zeby nie puchlo przy enum z wielu opcjami.

    Persist: meta-klucze lecą do `devices.last_state` JSON razem z wartosciami (co 60s).
    Przy restarcie daemona `_init_last_state_cache` ladowuje wszystko z DB — cache ma pelny stan od sekundy.
    """
    has_actions = bool(actions_cache)
    ieee = id_to_ieee.get(dev_id, str(dev_id))
    now = time.time()

    if ieee not in _last_state_cache:
        _last_state_cache[ieee] = {}
    cache = _last_state_cache[ieee]
    if "__ts" not in cache:
        cache["__ts"] = {}
    if "__val_ts" not in cache:
        cache["__val_ts"] = {}

    for field, value in flat.items():
        if has_actions:
            process_actions_on_push(dev_id, field, value)
        prev_values["%s:%s" % (dev_id, field)] = value

        # Meta: timestamp ostatniego pushu tego pola (zawsze, niezaleznie od typu wartosci)
        cache["__ts"][field] = now
        if not fallback:
            _push_real_ts["%s:%s" % (dev_id, field)] = now

        # Meta per-value: trzymaj TYLKO jesli (dev_id, field) jest uzywany w warunku `last_activity`
        # z filtrem value (zbudowane z actions_cache). Dodatkowo tylko dla dyskretnych (bool/krotki string).
        # Dzieki temu cache __val_ts ma tylko to co faktycznie potrzebne do akcji — zero nieuzywanych entries.
        if (dev_id in _last_activity_used
                and field in _last_activity_used[dev_id]
                and (isinstance(value, (bool, int)) or (isinstance(value, str) and len(value) <= 32))):
            if field not in cache["__val_ts"]:
                cache["__val_ts"][field] = {}
            # Normalizacja bool: str(True)="True" kolidowaloby z filtrem value="1" w akcjach GUI
            val_key = "1" if value is True else "0" if value is False else str(value)
            cache["__val_ts"][field][val_key] = now
            # LRU cap: max 20 unikalnych wartosci per pole — gdyby enum mial wiele opcji
            if len(cache["__val_ts"][field]) > 20:
                oldest = min(cache["__val_ts"][field].items(), key=lambda kv: kv[1])[0]
                cache["__val_ts"][field].pop(oldest, None)

    # Merge flat do last_state — wartosci glowne. Wykryj zmiane wartosci (dla szybkiego zapisu DB).
    value_changed = False
    for k, v in flat.items():
        if v is None and k in cache:
            continue
        if cache.get(k) != v:
            value_changed = True
        cache[k] = v

    # Zapis do DB: normalnie co 60s (throttle dla czestych pushy), ale od razu gdy wartosc sie zmienila.
    # Dzieki temu restart daemona nie gubi ostatnich stanow binarnych (contact, person, state, ...).
    if value_changed or (now - _last_state_write.get(ieee, 0)) >= 60:
        _last_state_write[ieee] = now
        try:
            # Merge z DB — nie nadpisuj pol zmienionych recznie (przez GUI).
            # Meta-klucze (__ts, __val_ts) zawsze preferuj z pamieci (swiezsze niz to co w DB).
            rows = db_exec("SELECT last_state FROM devices WHERE ieee=%s", (ieee,))
            if rows and rows[0][0]:
                db_state = json.loads(rows[0][0])
                # Z pamieci wszystkie pola not-None/empty oraz meta zawsze
                for k, v in cache.items():
                    if k.startswith("__") or (v is not None and v != ""):
                        db_state[k] = v
                cache.clear()
                cache.update(db_state)
                _last_state_cache[ieee] = cache
            state_json = json.dumps(cache, default=str)
            db_exec("UPDATE devices SET last_state=%s WHERE ieee=%s", (state_json, ieee))
        except Exception as e:
            db_log("ERROR", "last_state", "merge/write ieee=%s BLAD: %s" % (ieee, e))

    # Zapasowa czujka pomieszczenia — dopiero po zapisie stanu glownego urzadzenia.
    if not fallback:
        _sensor_fallback(dev_id, flat)

# --- MQTT callbacks (LEKKIE — tylko queue) ---

def on_connect(client, userdata, flags, reason_code, properties):
    print("[INFO] mqtt: Polaczono (rc=%s)" % reason_code)
    client.subscribe(TOPIC)
    client.subscribe(TOPIC_ONVIF)
    client.subscribe(TOPIC_BLEBOX)
    client.subscribe(TOPIC_ESPHOME)
    print("Subskrypcja: %s + %s + %s + %s" % (TOPIC, TOPIC_ONVIF, TOPIC_BLEBOX, TOPIC_ESPHOME))

def on_disconnect(client, userdata, flags, reason_code, properties):
    print("[WARN] mqtt: ROZLACZONO (rc=%s) — paho reconnect" % reason_code)

def on_message(client, userdata, msg):
    """Tylko wrzuca do kolejki — ZERO przetwarzania, natychmiastowy return"""
    global _drop_count
    try:
        msg_queue.put_nowait((msg.topic, msg.payload, msg.retain))
    except queue.Full:
        _drop_count += 1

# --- Worker thread (przetwarza kolejke) ---

def worker_loop():
    """Glowna petla workera — przetwarza wiadomosci MQTT z kolejki"""
    while not shutdown_event.is_set():
        try:
            topic, payload_bytes, retain = msg_queue.get(timeout=1)
        except queue.Empty:
            continue

        try:
            _process_message(topic, payload_bytes, retain)
        except Exception as e:
            db_log("ERROR", "worker", "%s: %s" % (type(e).__name__, e))
        finally:
            msg_queue.task_done()

# --- Sciezka B: fast-path recovery dongla po fatalnym bledzie Z2M ---
# Sygnatury SMIERCI koordynatora/serial (NIE per-device timeouty typu 'genOnOff.read failed').
# Te bledy = adapter odpadl / EZSP fatal => reboot ma sens od reki.
_DONGLE_FATAL_SIGS = ("Adapter disconnected", "ERROR_SERIAL_INIT",
                      "ASH_NCP_FATAL_ERROR", "HOST_FATAL_ERROR")
_last_fast_recover = 0.0  # monotonic; throttle bo jeden crash wypluwa serie bledow w 1 sekunde

def _maybe_fast_recover(msg):
    """Fatalny zwis koordynatora w logu Z2M => natychmiast zigbee_recover.php.
    In-memory throttle 60s (crash = kilka linii ERROR naraz). Cooldown/reboot/Telegram robi PHP
    (ten sam cooldown co sciezka A - staleness watchdog - wiec zero podwojnych rebootow)."""
    global _last_fast_recover
    if not any(sig in msg for sig in _DONGLE_FATAL_SIGS):
        return
    now = time.monotonic()
    if now - _last_fast_recover < 60:
        return
    _last_fast_recover = now
    try:
        subprocess.Popen(["php", "/opt/tymos/actions/zigbee_recover.php", "fatal: " + msg[:80]],
                         stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                         start_new_session=True)
    except Exception as e:
        db_log("ERROR", "zigbee_fast_recover", "Popen blad: %s" % e)


# --- Republish po starcie Z2M ---
# Z2M po starcie rozsyla stany WSZYSTKICH urzadzen ze swojej bazy (NIE retained na brokerze —
# to zwykly publish). Dla nas to falszywie swieze odczyty: martwy czujnik dostawal stempel `__ts`
# i wiersz w `deviceNNN` ze STARA wartoscia, przez co `ai_failsafe` i `ai_zigbee_monitor` byly
# slepe przez caly swoj prog (4 h / 3 h). Okno liczymy od `bridge/state` online.
_z2m_online_ts = 0.0          # monotonic; 0 = nie widzielismy startu Z2M
REPUBLISH_WINDOW = 20.0       # s — tyle po starcie Z2M ignorujemy pushe urzadzen
# Pola diagnostyczne pomijane przy porownaniu z cache: Z2M odtwarza je z wlasnego stanu i potrafia
# sie roznic o jeden odczyt (LQI zmienia sie przy kazdej ramce), co przepuszczalo caly republish.
# Porownujemy tylko pola nosne: temperature, humidity, state, occupancy, contact...
_REPUB_IGNORE = ("linkquality", "voltage", "battery", "update", "update_available", "last_seen", "elapsed")

def _process_message(topic, payload_bytes, retain=False):
    # bridge/devices — buduj mape ieee
    if topic == "zigbee2mqtt/bridge/devices":
        try:
            payload = json.loads(payload_bytes.decode())
            process_bridge_devices(payload)
        except (json.JSONDecodeError, UnicodeDecodeError) as e:
            db_log("WARN", "mqtt_parse", "bridge/devices JSON BLAD: %s" % e)
        except Exception as e:
            db_log("ERROR", "mqtt_parse", "bridge/devices BLAD: %s" % e)
        return

    # bridge/logging — przepisz bledy Z2M do tabeli `log` (admin -> Logi).
    # Cheap bytes check pierwszy: 99% to level info (kazdy publish), nie parsuj JSON niepotrzebnie.
    if topic == "zigbee2mqtt/bridge/logging":
        if b'"level":"error"' in payload_bytes:
            try:
                msg = json.loads(payload_bytes.decode()).get("message", "")
            except Exception:
                msg = payload_bytes.decode("utf-8", "replace")[:300]
            db_log("ERROR", "z2m", msg)
            # Sciezka B: fatalny zwis koordynatora => natychmiastowy recovery (bez czekania na staleness)
            _maybe_fast_recover(msg)
        return

    # bridge/state — start Z2M. Retained kopia przychodzi przy KAZDYM naszym starcie i nie oznacza
    # restartu Z2M, wiec okna wtedy nie uzbrajamy (inaczej po kazdym restarcie demona gubilibysmy 20 s).
    if topic == "zigbee2mqtt/bridge/state":
        global _z2m_online_ts
        try:
            st = json.loads(payload_bytes.decode()).get("state", "")
        except Exception:
            st = payload_bytes.decode("utf-8", "replace").strip().strip('"')
        if st == "online" and not retain:
            _z2m_online_ts = time.monotonic()
            db_log("INFO", "mqtt", "Z2M online — pomijam republish stanow przez %d s" % int(REPUBLISH_WINDOW))
        return

    # Ignoruj reszta bridge/*
    if topic.startswith("zigbee2mqtt/bridge/"):
        return

    # --- ONVIF/BLEBOX/ESPHOME ---
    if topic.startswith("tymos/onvif/") or topic.startswith("tymos/blebox/") or topic.startswith("tymos/esphome/"):
        try:
            payload = json.loads(payload_bytes.decode())
        except (json.JSONDecodeError, UnicodeDecodeError) as e:
            db_log("WARN", "mqtt_parse", "%s JSON BLAD: %s" % (topic, e))
            return
        if not isinstance(payload, dict):
            return
        device_id = payload.get("device_id", "")
        if not device_id:
            return
        cached = device_cache.get(device_id)
        if not cached:
            return
        flat = flatten_payload(payload)
        save_debug_payload(topic, payload_bytes, cached["id"])
        # insert_device_data sam ma guard na enabled_fields — zapisze tylko gdy sa kolumny w device<ID>.
        # _handle_device_push ZAWSZE — enabled_fields to tylko kontrola historii, nie filtr pushow.
        try:
            insert_device_data(device_id, payload, flat)
        except Exception as e:
            db_log("ERROR", "onvif_zapis", "Blad: %s" % e)
            try:
                db_connect()
            except Exception as e2:
                db_log("ERROR", "db_reconnect", "po onvif_zapis: %s" % e2)
        _handle_device_push(cached["id"], flat)
        return

    # --- Zigbee ---
    try:
        payload = json.loads(payload_bytes.decode())
    except (json.JSONDecodeError, UnicodeDecodeError):
        return
    if not isinstance(payload, dict):
        return

    friendly_name = extract_device(topic)
    ieee = name_to_ieee.get(friendly_name)
    if not ieee:
        return

    flat = flatten_payload(payload)

    # Republish po starcie Z2M — to nie odczyt z urzadzenia, tylko odtworzenie stanu z bazy Z2M.
    # Odrzucamy TYLKO payload identyczny z tym, co juz mamy w cache (republish jest z definicji 1:1).
    # Realne zdarzenie w tym oknie (np. occupancy 0->1 przy wejsciu do WC) rozni sie od cache i przechodzi,
    # zeby restart Z2M nie gasil swiatla na ruch.
    if _z2m_online_ts and (time.monotonic() - _z2m_online_ts) < REPUBLISH_WINDOW:
        _cache_st = _last_state_cache.get(ieee) or {}
        _cmp = {k: v for k, v in flat.items() if k not in _REPUB_IGNORE}
        if _cmp and all(_cache_st.get(k) == v for k, v in _cmp.items()):
            return

    cached_z = device_cache.get(ieee)
    if cached_z:
        save_debug_payload(topic, payload_bytes, cached_z["id"])
    try:
        find_or_register(friendly_name, ieee, payload, flat)
        insert_device_data(ieee, payload, flat)
    except Exception as e:
        db_log("ERROR", "zapis", "Blad: %s" % e)
        try:
            db_connect()
        except Exception as e2:
            db_log("ERROR", "db_reconnect", "po zapis: %s" % e2)

    cached = device_cache.get(ieee)
    if cached:
        # Debug raw push occupancy — odhaszuj przy debugowaniu problemow z pushami ruchu
        # if "occupancy" in flat:
        #     db_log("INFO", "push", "%s occupancy=%s" % (cached["device"], flat["occupancy"]))
        _handle_device_push(cached["id"], flat)

# === AKCJE ===

def _collect_last_activity_fields(cond, result):
    """Rekurencyjnie przechodzi po conditions (moga byc zagnieżdżone AND/OR) i zbiera pary
    (dev_id, field) dla warunkow typu `last_activity` z filtrem value. Zapisuje do `result`
    (dict: dev_id -> set(field, ...))."""
    if not isinstance(cond, dict):
        return
    # Zagnieżdżona grupa
    if "items" in cond and isinstance(cond["items"], list):
        for item in cond["items"]:
            _collect_last_activity_fields(item, result)
        return
    # Pojedynczy warunek
    if cond.get("type") == "last_activity" and cond.get("value", "") != "":
        try:
            did = int(cond.get("dev_id", ""))
        except (ValueError, TypeError):
            return
        field = cond.get("field", "")
        if field:
            result.setdefault(did, set()).add(field)


def load_actions_cache():
    global actions_cache, helpers_cache, _action_index, _last_activity_used
    rows = db_exec("SELECT id, name, enabled, trigger_config, conditions, actions, last_triggered, min_interval FROM actions")
    if rows is None:
        print("WARN: load_actions_cache — blad DB, zachowuje stary cache (%d)" % len(actions_cache), file=sys.stderr)
        return
    # Zachowaj _last_exec_epoch ze starego cache
    old_epochs = {}
    for a in actions_cache:
        if "_last_exec_epoch" in a:
            old_epochs[a["id"]] = a["_last_exec_epoch"]
    new_cache = []
    new_index = {}
    new_last_activity_used = {}  # dev_id -> set(fields)
    if rows:
        for row in rows:
            try:
                action = {
                    "id": row[0], "name": row[1], "enabled": bool(row[2]),
                    "triggers": json.loads(row[3]) if row[3] else [],
                    "conditions": json.loads(row[4]) if row[4] else None,
                    "actions": json.loads(row[5]) if row[5] else [],
                    "last_triggered": row[6],
                    "min_interval": row[7],
                }
            except (json.JSONDecodeError, TypeError) as e:
                print("WARN: zepsuty JSON w actions id=%s: %s — pomijam" % (row[0], e), file=sys.stderr)
                continue
            # Przywroc epoch ze starego cache
            if action["id"] in old_epochs:
                action["_last_exec_epoch"] = old_epochs[action["id"]]
            new_cache.append(action)
            # Buduj indeks dev_id -> [action] dla state/event/threshold triggerow
            for trigger in action["triggers"]:
                if trigger.get("type") in ("state", "event", "threshold"):
                    dev_id = str(trigger.get("dev_id", ""))
                    if dev_id:
                        new_index.setdefault(dev_id, []).append(action)
            # Zbierz pola dla last_activity z filtrem value (tylko z enabled akcji)
            if action["enabled"] and action["conditions"]:
                _collect_last_activity_fields(action["conditions"], new_last_activity_used)
    # Atomic swap — worker widzi albo stary albo nowy, nigdy czesciowy
    actions_cache = new_cache
    _action_index = new_index
    _last_activity_used = new_last_activity_used
    print("Cache akcji: %d (last_activity uzywa %d par dev/field)" % (len(actions_cache), sum(len(v) for v in _last_activity_used.values())))
    rows = db_exec("SELECT id, name, value FROM helpers")
    if rows is None:
        print("WARN: load_actions_cache/helpers — blad DB, zachowuje stary helpers cache (%d)" % len(helpers_cache), file=sys.stderr)
    else:
        new_helpers = {}
        if rows:
            for hid, hname, hval in rows:
                new_helpers[str(hid)] = hval
        helpers_cache = new_helpers  # atomic swap
    print("Cache helperow: %d" % len(helpers_cache))

def resolve_value(cond):
    if cond.get("value_src") == "helper":
        hid = str(cond.get("helper_id", ""))
        base = helpers_cache.get(hid, "0")
        try:
            base = float(base)
        except (ValueError, TypeError):
            return base
        offset = float(cond.get("offset", 0) or 0)
        return base + offset
    return cond.get("value", "")

def compare(actual, op, expected):
    try:
        a = float(actual)
        e = float(expected)
        if op == "=":  return a == e
        if op == "!=": return a != e
        if op == ">":  return a > e
        if op == "<":  return a < e
        if op == ">=": return a >= e
        if op == "<=": return a <= e
    except (ValueError, TypeError):
        sa = str(actual)
        se = str(expected)
        if op == "=":  return sa == se
        if op == "!=": return sa != se
    return False

def match_trigger(trigger, dev_id, field, value):
    t = trigger.get("type", "state")
    if t in ("state", "event"):
        if str(trigger.get("dev_id", "")) != str(dev_id) or trigger.get("field", "") != field:
            return False
        tv = trigger.get("value", "")
        op = trigger.get("op", "")
        if tv:
            sv = str(value).lower()
            stv = str(tv).lower()
            # Normalizacja bool/int: True/1/"1"/"true" traktowane jako rowne
            truthy = {"true", "1", "on", "yes"}
            falsy = {"false", "0", "off", "no"}
            if sv in truthy and stv in truthy:
                return True
            if sv in falsy and stv in falsy:
                return True
            return sv == stv
        if not op:
            # Brak value i op = triggeruj na KAZDA zmiane pola (np. czujnik drzwi)
            return True
        if isinstance(value, bool):
            return value
        if isinstance(value, (int, float)):
            return value != 0
        sv = str(value).lower()
        if sv in ("false", "0", "none", "null", "off", ""):
            return False
        return True
    if t == "threshold":
        if str(trigger.get("dev_id", "")) != str(dev_id) or trigger.get("field", "") != field:
            return False
        op = trigger.get("op", "=")
        expected = trigger.get("value", "")
        pkey = "%s:%s" % (dev_id, field)
        prev = prev_values.get(pkey)
        now_match = compare(value, op, expected)
        if prev is None:
            return False  # brak prev = nie wiadomo czy przejscie, nie triggeruj
        prev_match = compare(prev, op, expected)
        return now_match and not prev_match
    return False

def _device_by_id(dev_id):
    """Zwraca (ieee, cached) po dev_id lub (None, None) jesli nie znaleziono"""
    try:
        did = int(dev_id)
    except (ValueError, TypeError):
        return None, None
    ieee = id_to_ieee.get(did)
    if ieee:
        cached = device_cache.get(ieee)
        if cached:
            return ieee, cached
    return None, None

def eval_single_condition(cond):
    ct = cond.get("type", "value")
    dev_id = cond.get("dev_id", "")
    field = cond.get("field", "")

    if dev_id:
        ieee, cached = _device_by_id(dev_id)
        if not cached:
            rows = db_exec("SELECT device FROM devices WHERE id=%s", (dev_id,))
            dev_name = rows[0][0] if rows else "id=%s" % dev_id
            db_log("ERROR", "warunek", "Urzadzenie '%s' nie istnieje w cache (usuniety lub wylaczony?)" % dev_name)
            return False

    if ct == "value":
        # Najpierw zerknij do cache w pamieci — _last_state_cache trzyma ostatnia wartosc kazdego
        # pola urzadzenia (aktualizowany przy kazdym push w _handle_device_push, przy starcie
        # daemon laduje snapshot z devices.last_state). Dzieki temu zero SQL query dla warunku
        # i dziala nawet gdy pole jest wylaczone z enabled_fields (czyli nie jest w device<ID>).
        actual = None
        ieee_cached, _ = _device_by_id(dev_id)
        if ieee_cached and ieee_cached in _last_state_cache:
            actual = _last_state_cache[ieee_cached].get(field)
        # Fallback: gdy cache pusty (edge case — np. urzadzenie dopiero zarejestrowane i daemon
        # nie dostal jeszcze pushu dla tego pola) — zerknij do devices.last_state JSON.
        # NIE siegamy do device<ID> (tam tylko historia dla wykresow, pola disabled nie maja kolumn).
        if actual is None:
            try:
                rows = db_exec("SELECT last_state FROM devices WHERE id=%s", (dev_id,))
                if rows and rows[0][0]:
                    ls = json.loads(rows[0][0])
                    actual = ls.get(field)
            except Exception as e:
                db_log("WARN", "warunek_value", "fallback last_state dev=%s field=%s: %s" % (dev_id, field, e))
        if actual is None:
            return False
        # Fail-safe: nie sterujemy na zamrozonym odczycie (patrz STALE_FIELDS wyzej).
        if ieee_cached and ieee_cached in _last_state_cache:
            if _is_stale(dev_id, field, _last_state_cache[ieee_cached]):
                return False
        expected = resolve_value(cond)
        op = cond.get("op", "=")
        return compare(actual, op, expected)

    if ct == "sun":
        sun_mode = cond.get("sun_mode", "after_sunset")
        offset_min = int(cond.get("sun_offset", 0) or 0)
        now = utcnow_naive()
        sun = sun_times(now)
        sunrise = sun["sunrise"] + timedelta(minutes=offset_min)
        sunset = sun["sunset"] + timedelta(minutes=offset_min)
        if sun_mode == "after_sunset":
            return now >= sunset or now < sunrise
        elif sun_mode == "after_sunrise":
            return sunrise <= now < sunset
        elif sun_mode == "before_sunrise":
            return now < sunrise
        elif sun_mode == "before_sunset":
            return now < sunset
        return False

    if ct == "helper":
        hid = str(cond.get("helper_id", ""))
        actual = helpers_cache.get(hid, "0")
        return compare(actual, cond.get("op", "="), cond.get("value", ""))

    if ct == "last_activity":
        # Ile sekund minelo od ostatniego pushu tego pola (opcjonalnie z konkretna wartoscia)?
        # Cache first: `__ts[field]` dla bez-filter, `__val_ts[field][value]` gdy jest filter.
        # Fallback SQL dla edge case (pole nigdy nie bylo pushowane od startu daemona).
        val_filter = cond.get("value", "")
        seconds = int(cond.get("seconds", 120) or 120)
        now_ts = time.time()
        actual_diff = None

        ieee_cached, _ = _device_by_id(dev_id)
        if ieee_cached and ieee_cached in _last_state_cache:
            c = _last_state_cache[ieee_cached]
            if val_filter:
                ts = c.get("__val_ts", {}).get(field, {}).get(str(val_filter))
            else:
                ts = c.get("__ts", {}).get(field)
            if ts:
                try:
                    actual_diff = now_ts - float(ts)
                except (ValueError, TypeError):
                    pass

        if actual_diff is None:
            # Fallback: zerknij do devices.last_state JSON (cold cache po restarcie lub nowe urzadzenie).
            # NIE siegamy do device<ID> (tam tylko historia dla wykresow, pola disabled nie maja kolumn).
            try:
                rows = db_exec("SELECT last_state FROM devices WHERE id=%s", (dev_id,))
                if rows and rows[0][0]:
                    ls = json.loads(rows[0][0])
                    if val_filter:
                        val_key = "1" if str(val_filter) in ("1", "True", "true") else "0" if str(val_filter) in ("0", "False", "false") else str(val_filter)
                        ts = ls.get("__val_ts", {}).get(field, {}).get(val_key)
                    else:
                        ts = ls.get("__ts", {}).get(field)
                    if ts:
                        try:
                            actual_diff = now_ts - float(ts)
                        except (ValueError, TypeError):
                            pass
            except Exception as e:
                db_log("WARN", "warunek_last_activity", "fallback last_state dev=%s field=%s: %s" % (dev_id, field, e))

        if actual_diff is None:
            # Pole ZNANE (ma __ts), ale tej WARTOSCI nigdy nie bylo w __val_ts -> "nigdy" = nieskonczenie
            # dawno -> TRUE. Przypadek 2026-09-08: do akcji 10 dodano last_activity dzwonek.person=1,
            # a __val_ts.person mialo tylko "0" (para dev/field wchodzi do sledzenia dopiero, gdy jakas
            # akcja jej uzywa) -> warunek byl wiecznie FALSE i swiatla frontu/parkingu nie gasly 2 h.
            # Bez val_filter albo bez __ts[field] (pole nigdy nie pushowane) zostaje stare "nie wiem" = FALSE.
            if val_filter:
                field_known = False
                if ieee_cached and ieee_cached in _last_state_cache:
                    field_known = field in _last_state_cache[ieee_cached].get("__ts", {})
                if not field_known:
                    try:
                        rows = db_exec("SELECT last_state FROM devices WHERE id=%s", (dev_id,))
                        if rows and rows[0][0]:
                            field_known = field in json.loads(rows[0][0]).get("__ts", {})
                    except Exception:
                        pass
                if field_known:
                    return True
            # Brak danych w RAM i w devices.last_state — "nie wiem" traktuj jako False
            # (bezpieczniej: nie wylaczaj swiatla gdy nie ma pewnosci czy nikogo nie ma)
            return False

        return actual_diff >= seconds

    if ct == "last_change":
        # True jesli ostatnia ZMIANA wartosci pola byla >= X sekund temu
        tbl = "device%s" % dev_id
        seconds = int(cond.get("seconds", 3600) or 3600)
        try:
            rows = db_exec(
                "SELECT ts, `%s` FROM `%s` WHERE `%s` IS NOT NULL ORDER BY ts DESC LIMIT 50" % (field, tbl, field)
            )
            if not rows or len(rows) < 2:
                return True
            cur_val = str(rows[0][1])
            last_change_ts = rows[0][0]
            for i in range(1, len(rows)):
                if str(rows[i][1]) != cur_val:
                    last_change_ts = rows[i - 1][0]
                    break
            else:
                last_change_ts = rows[-1][0]
            diff = (datetime.now() - last_change_ts).total_seconds()
            return diff >= seconds
        except Exception:
            return False

    return False

def eval_conditions(conditions):
    if not conditions or not conditions.get("items"):
        return True
    logic = conditions.get("logic", "AND")
    results = []
    for item in conditions["items"]:
        if "logic" in item and "items" in item:
            results.append(eval_conditions(item))
        else:
            results.append(eval_single_condition(item))
    if logic == "OR":
        return any(results)
    return all(results)

# --- Wykonywanie akcji ---

_action_pool = ThreadPoolExecutor(max_workers=32, thread_name_prefix="action-worker")

# --- Telegram fire-and-forget (wola actions/telegram_send.php, nie blokuje workera) ---
TG_SCRIPT = "/opt/tymos/actions/telegram_send.php"

def _tg_fire(payload):
    """Fire-and-forget: odpala PHP CLI, pisze JSON na stdin, NIE czeka na wynik.
    Worker zwraca natychmiast (~ms). PHP wysyla HTTP sam, padniecie TG nie dotyka daemona."""
    proc = subprocess.Popen(
        ["php", TG_SCRIPT],
        stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        start_new_session=True,
    )
    try:
        proc.stdin.write(json.dumps(payload).encode())
        proc.stdin.close()
    except Exception as e:
        db_log("ERROR", "telegram", "stdin write BLAD: %s" % e)

def _fire_action(action):
    """
    Odpala akcje w TLE (pool action-worker, max 32 watki).

    Co sie dzieje w kolejnosci:
      1. Glowny watek MQTT wola ta funkcje — wraca natychmiast po submit (nic nie blokuje).
      2. W tle w pool: najpierw eval_conditions() — moze robic SELECT-y do DB (np. warunek "value"
         patrzy ostatnia wartosc z device<ID>, "last_change" czyta historie). Jesli warunki
         niespelnione, akcja nie rusza dalej.
      3. Jesli warunki OK — _execute_action_steps() wykonuje kroki akcji (mqtt_set, sleep,
         telegram, script, tymos_sound) po kolei.
      4. _last_exec_epoch (RAM) ustawiany OD RAZU (przed eval) — to chroni przed podwojnym
         fire tej samej akcji przy burscie pushow. Skutek: jesli warunki nie pasuja, akcja
         "zjada" swoj slot min_interval (zazwyczaj 5s) — akceptowalne, bo kolejny push i tak
         za chwile przyjdzie.
      5. UPDATE actions.last_triggered=NOW() robimy DOPIERO po eval_conditions()=True —
         kolumna DB oznacza "ostatnie rzeczywiste wykonanie akcji" (nie "ostatnie match triggera").

    Glowny watek MQTT NIE czeka na DB queries z warunkow — to byl dawny bottleneck.
    """
    name = action.get("name", "?")
    def _run():
        try:
            # Warunki (DB queries) — teraz w tle, zamiast blokowac watek MQTT
            if not eval_conditions(action.get("conditions")):
                return
            db_exec("UPDATE actions SET last_triggered=NOW() WHERE id=%s", (action["id"],))
            _execute_action_steps(action)
        except Exception as e:
            db_log("ERROR", "worker", "[%s] %s" % (name, e))
    _action_pool.submit(_run)
    action["_last_exec_epoch"] = time.time()

def _sanitize_name(name):
    """Usun emoji, lowercase, spacje→_"""
    clean = ""
    for ch in name:
        cat = unicodedata.category(ch)
        if cat.startswith("So") or cat.startswith("Sk"):
            continue
        clean += ch
    return clean.strip().lower().replace(" ", "_").replace("/", "_")

def _resolve_set_topic(ieee, device_name):
    """Zwraca topic MQTT set dla urzadzenia na podstawie ieee"""
    if ieee.startswith("blebox_"):
        return None  # BleBox: read-only
    z2m_name = ieee_to_z2m_name.get(ieee, device_name)
    return "zigbee2mqtt/%s/set" % z2m_name

def _execute_action_steps(action_def):
    for act in action_def.get("actions", []):
        atype = act.get("type", "")
        if atype == "mqtt_set":
            target_dev_id = act.get("dev_id", "")
            payload = act.get("payload", "{}")
            target_ieee, target_cached = _device_by_id(target_dev_id)
            if not target_cached:
                db_log("ERROR", "akcja", "[%s] Urzadzenie id=%s nie istnieje (usuniety?)" % (action_def["name"], target_dev_id))
                continue
            target_name = target_cached["device"]
            topic = _resolve_set_topic(target_ieee, target_name)
            if not topic:
                db_log("WARN", "akcja", "[%s] Urzadzenie '%s' nie obsluguje sterowania" % (action_def["name"], target_name))
                continue
            # MQTT publish: paho buforuje wewnetrznie, publish() zwraca natychmiast.
            # Jedna proba wystarczy — MQTT_ERR_SUCCESS oznacza "wrzucone do bufora do wyslania",
            # faktyczna dostawa idzie po connect/reconnect (QoS 1 gwarantuje dostarczenie).
            rc = mqtt_client.publish(topic, payload, qos=1)
            if rc.rc == mqtt.MQTT_ERR_SUCCESS:
                print("[akcja] [%s] MQTT %s -> %s" % (action_def["name"], topic, payload))
            else:
                db_log("ERROR", "akcja", "[%s] BLAD publish rc=%s" % (action_def["name"], rc.rc))
        elif atype == "sleep":
            seconds = min(int(act.get("seconds", 1) or 1), 300)
            print("[akcja] [%s] sleep %ds" % (action_def["name"], seconds))
            time.sleep(seconds)
        elif atype == "telegram":
            msg = act.get("message", "")
            if msg:
                try:
                    _tg_fire({"type": "text", "msg": msg})
                    print("[akcja] [%s] Telegram: %s" % (action_def["name"], msg[:100]))
                except Exception as e:
                    db_log("ERROR", "akcja", "[%s] Telegram BLAD: %s" % (action_def["name"], e))
        elif atype == "telegram_photo":
            msg = act.get("message", "")
            photo_url = act.get("photo_url", "")
            if photo_url:
                try:
                    _tg_fire({"type": "photo", "url": photo_url, "caption": msg})
                    print("[akcja] [%s] Telegram photo: %s" % (action_def["name"], photo_url[:80]))
                except Exception as e:
                    db_log("ERROR", "akcja", "[%s] Telegram photo BLAD: %s" % (action_def["name"], e))
        elif atype == "script":
            script = act.get("script", "")
            if script:
                try:
                    parts = script.split()
                    if parts[0].endswith(".php"):
                        cmd = ["php"] + parts
                    else:
                        cmd = ["bash"] + parts
                    # Fire-and-forget: worker wraca od razu (~50ms fork). Skrypt sam loguje bledy
                    # do tabeli `log` przez actions/lib/_log.inc.php (tymos_log + shutdown handler).
                    subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                     start_new_session=True)
                except Exception as e:
                    db_log("ERROR", "akcja", "[%s] blad skryptu: %s" % (action_def["name"], e))
        elif atype == "tymos_sound":
            sound = act.get("sound", "ping")
            try:
                db_exec("INSERT INTO tymos_sounds (sound) VALUES (%s)", (sound,))
                print("[akcja] [%s] TymOS sound: %s" % (action_def["name"], sound))
            except Exception as e:
                db_log("ERROR", "akcja", "[%s] TymOS sound BLAD: %s" % (action_def["name"], e))

def process_actions_on_push(dev_id, field, value):
    """
    Wolane z glownego watku MQTT za kazdym razem gdy przyjdzie push z urzadzenia.
    Sprawdza czy ktorakolwiek akcja typu state/event/threshold reaguje na to urzadzenie+pole.

    Krytyczne: ta funkcja MUSI byc szybka — blokuje kolejke MQTT.
    Dlatego wykonuje tylko szybkie sprawdzenia (match_trigger = O(1) porownanie) i delegauje
    eval_conditions + wykonanie do puli tla przez _fire_action(). Zadne DB queries tutaj.

    _action_index to pre-zbudowany slownik {dev_id: [akcje_reagujace_na_to_urzadzenie]}
    — szybki lookup zamiast iterowania po wszystkich akcjach.
    """
    candidates = _action_index.get(str(dev_id), [])
    for action in candidates:
        try:
            if not action["enabled"]:
                continue
            triggered = False
            for trigger in action["triggers"]:
                if trigger.get("type") in ("state", "event", "threshold"):
                    if match_trigger(trigger, dev_id, field, value):
                        triggered = True
                        break
            if not triggered:
                continue
            # Guard: nie odpalaj czesciej niz min_interval (domyslnie 5s; 0 = bez limitu, np. multiclick)
            last_exec = action.get("_last_exec_epoch", 0)
            min_interval = action.get("min_interval")
            if min_interval is None:
                min_interval = 5
            if (time.time() - last_exec) < min_interval:
                continue
            # Eval warunkow przeniesiony do _fire_action (robi sie w tle, nie blokuje watku MQTT)
            _fire_action(action)
        except Exception as e:
            db_log("ERROR", "push_action", "[%s] %s" % (action.get("name", "?"), e))

def process_cron_actions():
    """
    Wolane z glownej petli co ~55s. Sprawdza triggery CZASOWE (cron + sun) wszystkich akcji.

    Triggery cron: dopasowanie wyrazenia cron (minute, godzina, dzien...) do biezacej chwili.
    Triggery sun: wschod/zachod slonca + offset (np. "30 min przed zachodem" przy SUN_LAT/LON).

    Podobnie jak process_actions_on_push — eval warunkow jest DELEGOWANY do _fire_action
    (w tle, nie blokuje glownej petli daemona).
    """
    now = datetime.now()
    now_utc = utcnow_naive()
    sun = sun_times(now_utc)
    for action in actions_cache:
        try:
            if not action["enabled"]:
                continue
            triggered = False
            for trigger in action["triggers"]:
                ttype = trigger.get("type", "")

                if ttype == "cron":
                    cron_expr = trigger.get("cron", "")
                    if not cron_expr:
                        continue
                    if not cron_match(cron_expr, now):
                        continue
                    last_exec = action.get("_last_exec_epoch", 0)
                    if (time.time() - last_exec) < 55:
                        continue
                    triggered = True
                    break

                if ttype == "sun":
                    sun_type = trigger.get("sun_type", "sunset")
                    offset_min = int(trigger.get("sun_offset", 0) or 0)
                    target_utc = sun.get(sun_type)
                    if not target_utc:
                        continue
                    target_utc = target_utc + timedelta(minutes=offset_min)
                    diff = abs((now_utc - target_utc).total_seconds())
                    if diff > 30:
                        continue
                    last_exec = action.get("_last_exec_epoch", 0)
                    if (time.time() - last_exec) < 300:
                        continue
                    triggered = True
                    break

            if not triggered:
                continue
            # Eval warunkow przeniesiony do _fire_action (w tle)
            _fire_action(action)
        except Exception as e:
            db_log("ERROR", "cron_action", "[%s] %s" % (action.get("name", "?"), e))

def _cron_part_match(val, part):
    """Sprawdza czy val pasuje do pojedynczego elementu cron (obsluguje *, range, step, comma)"""
    if part == "*":
        return True
    if "," in part:
        return any(_cron_part_match(val, sub) for sub in part.split(","))
    if "/" in part:
        base_part, step = part.split("/", 1)
        step = int(step)
        if step <= 0:
            return False
        if base_part == "*":
            return val % step == 0
        if "-" in base_part:
            lo, hi = base_part.split("-", 1)
            lo, hi = int(lo), int(hi)
            return lo <= val <= hi and (val - lo) % step == 0
        base = int(base_part)
        return (val - base) % step == 0
    if "-" in part:
        lo, hi = part.split("-", 1)
        return int(lo) <= val <= int(hi)
    return val == int(part)

def cron_match(expr, dt):
    parts = expr.strip().split()
    if len(parts) != 5:
        return False
    fields = [dt.minute, dt.hour, dt.day, dt.month, dt.weekday()]
    fields[4] = (fields[4] + 1) % 7
    try:
        for i, part in enumerate(parts):
            if not _cron_part_match(fields[i], part):
                return False
    except (ValueError, TypeError, ZeroDivisionError):
        return False
    return True

# --- Reload cache ---

# Co ile sekund sprawdzic flage `tymos_reload` w DB (ujednolicone z bridge'ami)
RELOAD_CHECK_INTERVAL = 30
# Force full reload raz na 30 min — obrona przed rozjechaniem cache nawet gdy flaga nie zmieniona
FORCE_RELOAD_INTERVAL = 1800
last_reload_ts = "0"
last_force_reload = time.time()

def check_reload_flag():
    """
    Wolane co 10s z glownej petli. Sprawdza 2 rzeczy:
      1. Czy GUI ustawilo flage reload (settings.tymos_reload zmienilo sie) — wtedy przeladuj cache.
      2. Czy user wlaczyl/wylaczyl debug payload (zapis surowych wiadomosci MQTT do DB).
      3. Co 30 min force reload (obrona przed rozjechaniem cache).

    Oba flagi pobierane JEDNYM zapytaniem zamiast dwoch — tabela settings i tak ma tylko 3 wiersze.
    """
    global last_reload_ts, last_force_reload, _debug_payload
    now = time.time()
    force = (now - last_force_reload) >= FORCE_RELOAD_INTERVAL

    # Jedno query zamiast dwoch — obie flagi na raz
    rows = db_exec("SELECT k, v FROM settings WHERE k IN ('tymos_reload', 'debug_payload')")
    flags = {row[0]: row[1] for row in rows} if rows else {}
    reload_ts = flags.get('tymos_reload', '0')
    new_debug = (flags.get('debug_payload') == '1')

    if force:
        last_force_reload = now
        print("Force reload cache (co 30 min)")
        do_full_reload()
    elif reload_ts != last_reload_ts:
        last_reload_ts = reload_ts
        print("Reload cache (flaga zmieniona: %s)" % last_reload_ts)
        do_full_reload()

    if new_debug != _debug_payload:
        _debug_payload = new_debug
        print("Debug payload: %s" % ("ON" if _debug_payload else "OFF"))

def do_full_reload():
    """
    Pelny reload cache urzadzen + akcji z DB. Wolane co 30 min (force) lub na flage GUI.

    Po reload — czysci pomocnicze slowniki (_last_state_cache, prev_values, last_seen_update,
    last_db_write, _last_state_write) z wpisow dla urzadzen ktore juz nie sa w cache
    (np. soft-deleted w GUI przez deleted=1). Hygiene pamieci — slowniki nie rosna bez granic.
    """
    global _tables_created, _last_state_cache, prev_values
    global last_seen_update, last_db_write, _last_state_write
    _tables_created = set()
    load_device_cache()
    load_actions_cache()

    # Czyszczenie slownikow pomocniczych z wpisow niezwiazanych z aktywnymi urzadzeniami
    valid_ieees = set(device_cache.keys())
    valid_ids = {info["id"] for info in device_cache.values()}
    _last_state_cache = {k: v for k, v in _last_state_cache.items() if k in valid_ieees}
    _last_state_write = {k: v for k, v in _last_state_write.items() if k in valid_ieees}
    last_seen_update = {k: v for k, v in last_seen_update.items() if k in valid_ieees}
    last_db_write = {k: v for k, v in last_db_write.items() if k in valid_ieees}
    # prev_values kluczowane "dev_id:field" — wyluskaj dev_id i sprawdz czy nadal w cache
    prev_values = {k: v for k, v in prev_values.items()
                   if k.split(":", 1)[0].isdigit() and int(k.split(":", 1)[0]) in valid_ids}

    for ieee, info in device_cache.items():
        if info["enabled"]:
            create_device_table(ieee)
            _tables_created.add(ieee)

# --- Signal handling ---

def handle_signal(signum, frame):
    print("Sygnal %d — zamykam" % signum)
    shutdown_event.set()

signal.signal(signal.SIGTERM, handle_signal)
signal.signal(signal.SIGINT, handle_signal)

# --- Start ---

for _attempt in range(30):
    try:
        db_connect()
        break
    except Exception as e:
        print("DB connect failed: %s — retry za 5s (%d/30)" % (e, _attempt+1), file=sys.stderr)
        time.sleep(5)
else:
    print("FATAL: nie udalo sie polaczyc z DB po 30 probach", file=sys.stderr)
    sys.exit(1)
load_device_cache()
_init_last_state_cache()
load_actions_cache()

for ieee, info in device_cache.items():
    if info["enabled"]:
        create_device_table(ieee)
        _tables_created.add(ieee)

db_exec("INSERT IGNORE INTO settings (k, v) VALUES ('tymos_reload', '0')")
rows = db_exec("SELECT v FROM settings WHERE k='tymos_reload' LIMIT 1")
if rows:
    last_reload_ts = rows[0][0]

db_exec("INSERT IGNORE INTO settings (k, v) VALUES ('debug_payload', '0')")
db_exec("""CREATE TABLE IF NOT EXISTS debug_payload (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    topic VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    ts DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device_ts (device_id, ts)
) ENGINE=InnoDB""")
rows = db_exec("SELECT v FROM settings WHERE k='debug_payload' LIMIT 1")
_debug_payload = (rows and rows[0][0] == "1")
print("Debug payload: %s" % ("ON" if _debug_payload else "OFF"))

# MQTT client
mqtt_client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id="tymos-%d" % os.getpid(), protocol=mqtt.MQTTv311, clean_session=True)
if MQTT_USER: mqtt_client.username_pw_set(MQTT_USER, MQTT_PASS)
mqtt_client.reconnect_delay_set(min_delay=5, max_delay=120)
mqtt_client.on_connect = on_connect
mqtt_client.on_disconnect = on_disconnect
mqtt_client.on_message = on_message

for _attempt in range(30):
    try:
        mqtt_client.connect(BROKER, PORT, keepalive=60)
        break
    except Exception as e:
        print("MQTT connect failed: %s — retry za 5s (%d/30)" % (e, _attempt+1), file=sys.stderr)
        time.sleep(5)
else:
    print("FATAL: nie udalo sie polaczyc z MQTT po 30 probach", file=sys.stderr)
    sys.exit(1)
mqtt_client.loop_start()

# Worker thread — przetwarza kolejke MQTT
worker = threading.Thread(target=worker_loop, daemon=True, name="mqtt-worker")
worker.start()

print("TymOS listener v3 uruchomiony (PID %d)" % os.getpid())

# Glowna petla — periodic tasks + czeka na shutdown
_last_cron_run = 0
try:
    while not shutdown_event.is_set():
        shutdown_event.wait(timeout=RELOAD_CHECK_INTERVAL)
        if shutdown_event.is_set():
            break
        try:
            db_ping()
            if _drop_count > 0:
                db_log("WARN", "queue", "Dropped %d wiadomosci (queue full)" % _drop_count)
                _drop_count = 0
            check_reload_flag()
            now_t = time.time()
            if (now_t - _last_cron_run) >= 55:
                _last_cron_run = now_t
                process_cron_actions()
        except Exception as e:
            db_log("ERROR", "petla", "Blad: %s" % e)
            try:
                db_connect()
            except Exception as e2:
                db_log("ERROR", "db_reconnect", "w main loop: %s" % e2)
except Exception as e:
    print("FATAL: %s" % e, file=sys.stderr)
    sys.exit(1)

# Clean shutdown
print("Zamykam — czekam na workery (max 10s)...")
mqtt_client.loop_stop()
mqtt_client.disconnect()
_action_pool.shutdown(wait=True, cancel_futures=False)
# Polaczenie DB zostaje w thread-local lib.db._db_local — systemd zamknie proces, kernel
# posprzata otwarte gniazdko. Nie musimy recznie zamykac (pymysql zamyka przy GC procesu).
print("Zamknieto.")
