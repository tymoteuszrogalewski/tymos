#!/bin/bash
# Trzyma streamy HD w go2rtc "na cieplo".
#
# DLACZEGO: go2rtc 1.9.14 NIE ZNA klucza `autostart` — sprawdzone, w binarce nie ma
# nawet takiego napisu (`grep -ac autostart /usr/local/bin/go2rtc` = 0). Blok
# `autostart:` w go2rtc.yaml byl przez caly czas martwa konfiguracja, ignorowana bez
# ostrzezenia. Efekt: producer RTSP startuje dopiero, gdy stream dostanie odbiorce,
# i gasnie razem z ostatnim. Dlatego `parking_hd`/`basen_hd` staly, a `face_scan.py`
# przy zimnym starcie HD czekal na pierwsza klatke ~3,7 s — czyli caly czas, w ktorym
# czlowiek jest w kadrze.
#
# JAK: ten skrypt JEST tym odbiorca — ciagnie stream do /dev/null. `#video=copy` w
# go2rtc.yaml oznacza zero przekodowania, wiec koszt to tylko muxowanie mp4.
#
# ODPORNOSC NA AWARIE: kazdy stream ma wlasna petle — zerwany RTSP, restart kamery
# czy restart samego go2rtc konczy `curl`, petla wstaje po RETRY_SEC. Padniecie jednego
# streamu nie rusza drugiego. Do tego systemd `Restart=always` na calej usludze.

STREAMS="parking_hd basen_hd"
RETRY_SEC=5

for s in $STREAMS; do
    (
        while true; do
            curl -s -N -o /dev/null "http://127.0.0.1:1984/api/stream.mp4?src=${s}"
            sleep "$RETRY_SEC"
        done
    ) &
done

wait
