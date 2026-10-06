#!/bin/bash
# TymOS daily backup — pelny snapshot dla disaster recovery (fresh Debian -> working tymos).
# Wywolanie: przez TymOS Akcje (admin -> Akcje -> "+ Nowa akcja"):
#   Kiedy: Cykliczne (CRON) -> "0 15 * * *" (codziennie 15:00; do 2026-10-01 bylo 3:00)
#   Wykonaj: Uruchom skrypt -> "/opt/tymos/scripts/backup.sh"
#
# Tworzy /opt/tymos/backup/tymos-YYYY-MM-DD.tar.gz zawierajacy:
#   db.sql.gz                  - MySQL dump (single-transaction, --quick)
#   etc.tar.gz                 - /etc (system configs)
#   opt-tymos.tar.gz           - /opt/tymos (kod, configi)
#   root-home.tar.gz           - /root (klucze SSH, skrypty)
#   esphome-yaml.tar.gz        - /opt/esphome/**.yaml
#   z2m_*                      - configuration.yaml, database.db, coordinator_backup.json
#   dpkg-list.txt              - lista pakietow apt
#   pip-freeze.txt             - lista pakietow Python venv
#   crontab-root.txt           - root crontab
#   debian-version.txt         - wersja systemu
#   hostname.txt               - nazwa hosta
#   restore.sh                 - skrypt odtwarzajacy fresh Debian -> tymos

set -e

DATE=$(date +%F)
DEST=/opt/tymos/backup
WORK="/tmp/tymos-backup-$$"
ARCHIVE="$DEST/tymos-$DATE.tar.gz"

mkdir -p "$DEST" "$WORK"
trap "rm -rf '$WORK'" EXIT

cd "$WORK"

# 1. DB dump — --defaults-file jawnie (nie zalezne od $HOME, daemon odpala bez HOME=/root)
# Stderr do db.sql.err — zeby pad nie schowal sie pod gzip pustym
mysqldump --defaults-file=/root/.my.cnf --single-transaction --quick --routines --triggers tymos 2> db.sql.err | gzip > db.sql.gz
if [ -s db.sql.err ]; then echo "[$(date '+%F %T')] mysqldump stderr:" >&2; cat db.sql.err >&2; fi
rm -f db.sql.err
# Sanity check: dump musi byc co najmniej 1KB (rzeczywisty dump tymos to ~30MB)
if [ $(stat -c%s db.sql.gz) -lt 1024 ]; then echo "[$(date '+%F %T')] BLAD: db.sql.gz < 1KB - mysqldump padl" >&2; exit 1; fi

# 2. /etc (pelne)
tar czf etc.tar.gz -C / etc 2>/dev/null

# 3. /opt/tymos (z excludami na grube/regenerowalne)
tar czf opt-tymos.tar.gz \
  --exclude='tymos/backup' \
  --exclude='tymos/log' \
  --exclude='tymos/venv' \
  --exclude='tymos/.esphome/build' \
  --exclude='tymos/.esphome/external_components' \
  --exclude='tymos/.esphome/packages' \
  --exclude='*_raw*.json' \
  --exclude='*_raw*.html' \
  --exclude='*_raw*.json_full' \
  -C /opt tymos 2>/dev/null

# 4. /root (klucze, skrypty) — bez cache i toolchainow (odtwarzalne; .platformio sam = 3,5 GB)
tar czf root-home.tar.gz --exclude='.cache' --exclude='.local' --exclude='.platformio' --exclude='.npm' --exclude='*venv' -C / root 2>/dev/null

# 5. ESPHome yamls
if [ -d /opt/esphome ]; then
    find /opt/esphome -name '*.yaml' -print0 2>/dev/null | tar czf esphome-yaml.tar.gz --null -T - 2>/dev/null || true
fi

# 6. Z2M data
[ -f /opt/zigbee2mqtt/data/configuration.yaml ]      && cp /opt/zigbee2mqtt/data/configuration.yaml      z2m_configuration.yaml
[ -f /opt/zigbee2mqtt/data/database.db ]             && cp /opt/zigbee2mqtt/data/database.db             z2m_database.db
[ -f /opt/zigbee2mqtt/data/coordinator_backup.json ] && cp /opt/zigbee2mqtt/data/coordinator_backup.json z2m_coordinator_backup.json

# 7. Meta — wersje pakietow, system info
dpkg -l > dpkg-list.txt
[ -x /opt/tymos/venv/bin/pip ] && /opt/tymos/venv/bin/pip freeze > pip-freeze.txt
crontab -l > crontab-root.txt 2>/dev/null || true
cat /etc/debian_version > debian-version.txt
hostname > hostname.txt

# 8. Skrypt restore.sh (osadzony w archiwum)
cp /opt/tymos/scripts/restore.sh restore.sh

# Pakuj wszystko do jednego archiwum
PARTS=$(du -m * | sort -rn | head -4 | awk '{print $2" "$1" MB"}')
tar czf "$ARCHIVE" *

# Rotacja: usun > 14 dni
find "$DEST" -name 'tymos-*.tar.gz' -mtime +14 -delete

SIZE=$(du -h "$ARCHIVE" | cut -f1)
echo "[$(date '+%F %T')] backup OK: $ARCHIVE ($SIZE)"

# Alarm rozmiaru (2026-10-01): normalnie ~200 MB. Powyzej 500 MB cos nowego wpada do backupu -> Telegram Wazne.
# Backup przestawiony na 15:00 (akcja 78), zeby alarm nie przychodzil w nocy.
LIMIT_MB=500
SIZE_MB=$(( $(stat -c%s "$ARCHIVE") / 1024 / 1024 ))
if [ "$SIZE_MB" -gt "$LIMIT_MB" ]; then
    MSG="Backup TymOS urósł: spodziewane ~200 MB, jest ${SIZE_MB} MB ($(basename "$ARCHIVE")). Największe części:
${PARTS}
Sprawdź, co nowego wpada do backupu."
    php -r 'echo json_encode(["type"=>"text","chat"=>"alert","msg"=>$argv[1]]);' "$MSG" > "$DEST/.size_alert.json"
    php /opt/tymos/actions/telegram_send.php < "$DEST/.size_alert.json" > /dev/null 2>&1 || true
    echo "[$(date '+%F %T')] UWAGA: backup ${SIZE_MB} MB > ${LIMIT_MB} MB, wyslano Telegram"
fi
