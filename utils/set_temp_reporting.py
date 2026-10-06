#!/usr/bin/env python3
"""
Jednorazowe ustawienie `max_report_interval` na czujkach temperatury (domyslnie 1800 s = 30 min).

PROBLEM: `configure_reporting` wymaga NIESPIACEJ czujki. Bateryjne SNZB-02* spia prawie caly czas
i kazda proba konczy sie `Bind ... failed (Delivery failed)`. Budzenie przyciskiem oznacza obejscie
calego domu, a czesc czujek jest niedostepna (PostHeater siedzi w rurze).

ROZWIAZANIE: czujka jest wybudzona przez chwile TUZ PO wyslaniu wlasnego raportu. Skrypt siedzi na
MQTT, czeka az dana czujka cos przysle i dopiero wtedy wysyla jej configure_reporting — trafia
w otwarte okno. Powtarza przy kazdym kolejnym raporcie, az do skutku albo do TIMEOUT_H.

Uruchomienie (w tle, bo czeka na raporty — nawet do godziny):
    nohup python3 /opt/tymos/utils/set_temp_reporting.py > /tmp/set_temp_reporting.log 2>&1 &
"""

import json
import time
import paho.mqtt.client as mqtt

MAX_INTERVAL = 1800     # sekund — raport ma przyjsc nie rzadziej niz co tyle
MIN_INTERVAL = 10       # bez zmian wzgledem dotychczasowej konfiguracji
REPORT_CHANGE = 100     # 100 = 1,00 C; progu swiadomie nie ruszamy (bateria)
TIMEOUT_H = 3           # po tylu godzinach konczymy niezaleznie od wyniku
RETRY_GAP = 60          # sekund miedzy kolejnymi probami tej samej czujki

# friendly_name w Z2M. Salon (dev684) pominiety — ma juz 1800 s.
CZUJKI = [
    "Patio Temperatura",
    "Bufor Temperatura Dół",
    "Bufor Temperatura Góra",
    "Łazienka Temperatura",
    "Liwia Temperatura",
    "Rekupetator PostHeater Temperatura",
    "Salon Temperatura Zapas",
    "Taras Temperatura",
]

pending = {n: 0.0 for n in CZUJKI}   # nazwa -> czas ostatniej proby
start = time.time()


def log(msg):
    print("%s  %s" % (time.strftime("%H:%M:%S"), msg), flush=True)


def on_connect(client, userdata, flags, reason_code, properties=None):
    client.subscribe("zigbee2mqtt/#")
    log("start, do ustawienia: %d czujek" % len(pending))


def on_message(client, userdata, msg):
    topic = msg.topic
    if topic == "zigbee2mqtt/bridge/response/device/configure_reporting":
        try:
            d = json.loads(msg.payload.decode())
        except Exception:
            return
        name = (d.get("data") or {}).get("id", "")
        if d.get("status") == "ok" and name in pending:
            del pending[name]
            log("OK  %s  (zostalo %d)" % (name, len(pending)))
        return

    name = topic[len("zigbee2mqtt/"):]
    if name not in pending:
        return
    now = time.time()
    if now - pending[name] < RETRY_GAP:
        return
    pending[name] = now
    client.publish("zigbee2mqtt/bridge/request/device/configure_reporting", json.dumps({
        "id": name,
        "endpoint": 1,
        "cluster": "msTemperatureMeasurement",
        "attribute": "measuredValue",
        "minimum_report_interval": MIN_INTERVAL,
        "maximum_report_interval": MAX_INTERVAL,
        "reportable_change": REPORT_CHANGE,
    }))
    log("proba: %s (odezwala sie wlasnie teraz)" % name)


client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
client.on_connect = on_connect
client.on_message = on_message
client.connect("localhost", 1883, 60)
client.loop_start()

while pending and (time.time() - start) < TIMEOUT_H * 3600:
    time.sleep(5)

client.loop_stop()
if pending:
    log("KONIEC po %d h, NIE USTAWIONE: %s" % (TIMEOUT_H, ", ".join(pending)))
else:
    log("KONIEC — wszystkie czujki ustawione")
