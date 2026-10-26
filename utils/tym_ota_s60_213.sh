#!/bin/bash
# tym_ota_s60_213.sh — kampania OTA gniazdek Sonoff S60ZBTPF 2.0.3 -> 2.1.3 (2026-10-25).
#
# Sekwencyjnie, JEDNO gniazdko naraz (~15 min/szt). Test na "Chłodnica Salon Wentylatory" OK.
# Zabezpieczenia:
#   - pobor > POWER_LIMIT W = urzadzenie pracuje (AGD, grzalka, pompa) -> pomijamy, do powtorki,
#   - stan sprzed flasha jest przywracany po restarcie gniazdka (power_on_behavior=off zostawilby
#     np. Rekuperator wylaczony).
# Log: /root/utils/tym_ota_s60_213.log   Postep: tail -f
# Przerwany flash wznawia sie ponownym uruchomieniem (gniazdka juz na 2.1.3 sa pomijane).

LOG=/root/utils/tym_ota_s60_213.log
TARGET="2.1.3"
TIMEOUT_MIN=25
POWER_LIMIT=${POWER_LIMIT:-15}   # POWER_LIMIT=99999 = flashuj takze pracujace (za zgoda usera)

DEVICES=(
  # --- 1. nieszkodliwe ---
  "Logo"
  "Nagrzewnica"
  "Liwia Grzejnik"
  "Front Światło Drzwi"
  "Kuchnia Przewiew"
  "Basen Oświetlenie"
  "Gofrownica"
  "Łazienka podłogówka"
  "Ogród Światło"
  "Garaż Światło"
  # --- 2. AGD i pompy (kontrola poboru) ---
  "Zmywarka"
  "Pralka"
  "Suszarka"
  "Nawilżacz"
  "Ogród Pompa"
  # --- 3. grzalki bufora (kontrola poboru) ---
  "Bufor Grzałka 1"
  "Bufor Grzałka 2"
  # --- 4. infrastruktura ---
  "Rekuperator"
  # --- 5. outdoor, slabszy link ---
  "Basen Pompa"
  "Parking Światło"
)

# Argumenty = wlasna lista gniazdek (np. powtorka pominietych), zamiast pelnej listy.
[ $# -gt 0 ] && DEVICES=("$@")

log() { echo "$(date '+%Y-%m-%d %H:%M:%S')  $*" >> "$LOG"; }

ver_of() {
  mosquitto_sub -h localhost -t zigbee2mqtt/bridge/devices -C 1 -W 6 2>/dev/null | python3 -c '
import sys, json
name = sys.argv[1]
try:
    for x in json.load(sys.stdin):
        if x.get("friendly_name") == name:
            print(x.get("software_build_id") or "?")
            break
except Exception:
    pass
' "$1"
}

field_of() { mysql tymos -N -B -e "SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(last_state,'\$.$2')),'') FROM devices WHERE device='$1' AND deleted=0 LIMIT 1" 2>/dev/null; }

log "=========== START OTA -> $TARGET (${#DEVICES[@]} gniazdek) ==========="
OK=0; SKIP=0; FAIL=0
for DEV in "${DEVICES[@]}"; do
  CUR=$(ver_of "$DEV")
  if [ "$CUR" = "$TARGET" ]; then log "[POMIN] $DEV — juz $TARGET"; SKIP=$((SKIP+1)); continue; fi

  P=$(field_of "$DEV" power)
  if [ -n "$P" ] && [ "$(printf '%.0f' "$P" 2>/dev/null || echo 0)" -gt "$POWER_LIMIT" ]; then
    log "[POMIN] $DEV — pobiera ${P} W, pracuje. Do powtorki."; SKIP=$((SKIP+1)); continue
  fi
  STATE=$(field_of "$DEV" state)

  log "[START] $DEV — $CUR -> $TARGET (stan $STATE)"
  mosquitto_pub -h localhost -t zigbee2mqtt/bridge/request/device/ota_update/update -m "{\"id\":\"$DEV\"}" 2>/dev/null

  DONE=0
  for i in $(seq 1 "$TIMEOUT_MIN"); do
    sleep 60
    if [ "$(ver_of "$DEV")" = "$TARGET" ]; then
      log "[OK]    $DEV — $TARGET po ${i} min"; OK=$((OK+1)); DONE=1; break
    fi
  done
  [ "$DONE" = "0" ] && { log "[BRAK]  $DEV — nadal $(ver_of "$DEV") po ${TIMEOUT_MIN} min (offline albo slaby link)."; FAIL=$((FAIL+1)); }

  # przywroc stan sprzed flasha (gniazdko restartuje sie wg power_on_behavior).
  # Grzalek nie ruszamy — akcje 28/29 co minute wymuszaja stan wg decyzji ai_bufor (a 133 inching).
  case "$DEV" in "Bufor Grzałka"*) DONE_RESTORE=0 ;; *) DONE_RESTORE=$DONE ;; esac
  if [ "$DONE_RESTORE" = "1" ] && { [ "$STATE" = "ON" ] || [ "$STATE" = "OFF" ]; }; then
    sleep 20
    mosquitto_pub -h localhost -t "zigbee2mqtt/$DEV/set" -m "{\"state\":\"$STATE\"}" 2>/dev/null
    log "        $DEV — przywrocony stan $STATE"
  fi
  sleep 30
done
log "=========== KONIEC: OK=$OK POMINIETE=$SKIP BEZ_ZMIANY=$FAIL ==========="
