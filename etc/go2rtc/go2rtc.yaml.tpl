api:
  listen: :1984
rtsp:
  listen: :8554
webrtc:
  listen: :8555
  candidates:
  - {{TYMOS_IP_VPN}}:8555
  - stun:8555
streams:
  # Plik generowany: scripts/render_etc.php (wartosci {{...}} z tymos/config.inc.php).
  # parking = Tapo C325WB, basen = Tapo C320WS, ogrod = Tapo C325WB (przyszly maszt, DZIS OFFLINE),
  # doorbell = Reolink Video Doorbell PoE. Konto RTSP/ONVIF na kazdej kamerze Tapo jednakowe.
  parking_hd:
  - rtsp://{{CAM_TAPO_USER}}:{{CAM_TAPO_PASS}}@{{CAM_PARKING_IP}}:554/stream1#transport=udp#video=copy#audio=none
  - ffmpeg:parking_hd#video=copy#audio=none
  basen_hd:
  - rtsp://{{CAM_TAPO_USER}}:{{CAM_TAPO_PASS}}@{{CAM_BASEN_IP}}:554/stream1#transport=udp#video=copy#audio=none
  - ffmpeg:basen_hd#video=copy#audio=none
  basen_sd:
  - rtsp://{{CAM_TAPO_USER}}:{{CAM_TAPO_PASS}}@{{CAM_BASEN_IP}}:554/stream2#transport=udp#video=copy
  - ffmpeg:basen_sd#video=copy
  # ogrod_* = kamera na przyszlym maszcie (dzis odlaczona). Stream bez odbiorcy nic nie kosztuje;
  # do przelacznika w card_camera_parking i do snapshot_refresh dopisac DOPIERO gdy kamera wisi.
  ogrod_hd:
  - rtsp://{{CAM_TAPO_USER}}:{{CAM_TAPO_PASS}}@{{CAM_OGROD_IP}}:554/stream1#transport=udp#video=copy#audio=none
  - ffmpeg:ogrod_hd#video=copy#audio=none
  ogrod_sd:
  - rtsp://{{CAM_TAPO_USER}}:{{CAM_TAPO_PASS}}@{{CAM_OGROD_IP}}:554/stream2#transport=udp#video=copy
  - ffmpeg:ogrod_sd#video=copy
  doorbell_hd:
  - rtsp://{{DOORBELL_USER}}:{{DOORBELL_PASS}}@{{DOORBELL_IP}}:554/h264Preview_01_main#transport=udp#video=copy#audio=none
  - ffmpeg:doorbell_hd#video=copy#audio=none
  doorbell_sd:
  - rtsp://{{DOORBELL_USER}}:{{DOORBELL_PASS}}@{{DOORBELL_IP}}:554/h264Preview_01_sub#transport=udp#video=copy#audio=none
  - ffmpeg:doorbell_sd#video=copy#audio=none
  doorbell_talk:
  - rtsp://{{DOORBELL_USER}}:{{DOORBELL_PASS}}@{{DOORBELL_IP}}:554/h264Preview_01_sub#transport=udp#video=copy#backchannel=1
  - ffmpeg:doorbell_talk#audio=opus
  parking_sd:
  - rtsp://{{CAM_TAPO_USER}}:{{CAM_TAPO_PASS}}@{{CAM_PARKING_IP}}:554/stream2#transport=udp#video=copy
  - ffmpeg:parking_sd#video=copy
# UWAGA: go2rtc 1.9.14 NIE OBSLUGUJE klucza `autostart` — w binarce nie ma nawet takiego
# napisu (`grep -ac autostart /usr/local/bin/go2rtc` = 0). Blok, ktory tu byl (parking_sd,
# garden_sd, doorbell_sd, doorbell_talk, parking_hd, garden_hd), byl martwa konfiguracja:
# ignorowany bez ostrzezenia, wiec zadnego autostartu nigdy nie bylo. Sprawdzone 2026-09-02:
# strumienie bez odbiorcy (basen_sd, parking_hd, basen_hd, doorbell_talk) po prostu staly.
#
# Streamy startuja WYLACZNIE gdy maja odbiorce i gasna z ostatnim:
#   - parking_sd / doorbell_sd — trzyma je iPad (WHEP) i snapshot_refresh co 10 s,
#   - parking_hd / basen_hd    — trzyma je usluga `tymos-go2rtc-keepalive`
#                                (utils/go2rtc_keepalive.sh, curl do /dev/null),
#   - basen_sd / doorbell_talk — na zadanie, i tak ma byc.
#
# parking_tiny i doorbell_tiny USUNIETE 2026-09-02 — byly wylacznie dla camera_render_hls(),
# ktore nie bylo wolane z zadnej karty. Oba TRANSKODOWALY (#video=h264#width=480), wiec gdyby
# ktos je kiedys odpalil, zaplacilby CPU na Pi za podglad, ktorego nikt nie oglada.
#
# doorbell_hd SWIADOMIE nie jest trzymany na cieplo — dzwonek Reolink obsluguje tylko dwa
# rownoczesne strumienie glowne i juz przy dwoch mial klopoty.
