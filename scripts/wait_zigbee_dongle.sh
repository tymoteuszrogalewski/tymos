#!/bin/bash
# Czeka, az dongle Zigbee pojawi sie w sieci — uzywane jako ExecStartPre zigbee2mqtt.service.
# Adres czytany z configuration.yaml, zeby nie dublowac go w dwoch miejscach.
#
# PINGUJEMY, NIE SPRAWDZAMY PORTU. Dongle serial-over-TCP przyjmuje tylko JEDNO polaczenie na
# 6638 — `nc -z` podbiera je Z2M i start konczy sie „Port Error: read ECONNRESET" (sprawdzone
# na zywym systemie 2026-08-21). Ping wystarczy: chodzi o to, czy dongle w ogole wstal.
#
# Po TIMEOUT wychodzi z 0 (nie blokujemy startu na zawsze — niech Z2M sprobuje sam).
CFG=/opt/zigbee2mqtt/data/configuration.yaml
TIMEOUT=180

TARGET=$(grep -m1 -oE 'tcp://[0-9.]+:[0-9]+' "$CFG" | sed 's#tcp://##')
[ -z "$TARGET" ] && exit 0          # dongle po USB albo inny format — nie ma na co czekac

HOST=${TARGET%%:*}
PORT=${TARGET##*:}

for i in $(seq 1 "$TIMEOUT"); do
    if ping -c 1 -W 1 "$HOST" > /dev/null 2>&1; then
        [ "$i" -gt 1 ] && logger -t wait_zigbee_dongle "dongle ${HOST} odpowiada po ${i}s"
        # Pauza BEZWARUNKOWA (2026-08-27). Wczesniej byla tylko gdy dongle nie odpowiadal od razu
        # ($i > 1). Ale po `process.exit` Z2M restartuje go systemd, dongle pinguje juz w 1. probie
        # -> pauza byla pomijana i Z2M dobijal sie do portu chwile po tym, jak stary socket zniknal,
        # zanim mostek serial byl gotowy przyjac nowe polaczenie. 5 s nic nie kosztuje.
        sleep 5
        exit 0
    fi
    sleep 1
done

logger -t wait_zigbee_dongle "dongle ${HOST} nie odpowiada po ${TIMEOUT}s - startuje Z2M mimo to"
exit 0
