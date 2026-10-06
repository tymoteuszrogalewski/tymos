#!/bin/bash
# TymOS backup — sciaga z Pi do lokalnego os-tymos/backup/
set -euo pipefail

CONF="$(dirname "$0")/tymos/config.inc.php"
cfg() { sed -n "s/^define('$1', *'\([^']*\)'.*/\1/p" "$CONF"; }
PI=$(cfg TYMOS_SSH)
LOCAL=$(cfg BACKUP_LOCAL_DIR)
DATE=$(date +%Y-%m-%d)
DIR="$LOCAL/$DATE"

mkdir -p "$DIR"

echo "=== TymOS backup $DATE ==="

# 1. DB dump
echo "[1/3] mysqldump tymos..."
ssh "$PI" "mysqldump tymos | gzip > /tmp/tymos_db.sql.gz"
scp "$PI:/tmp/tymos_db.sql.gz" "$DIR/tymos_db.sql.gz"
ssh "$PI" "rm /tmp/tymos_db.sql.gz"

# 2. Lista pakietow + pip freeze + crontab + /root/
echo "[2/6] dpkg -l, pip freeze, crontab, /root/..."
ssh "$PI" "dpkg -l" > "$DIR/dpkg_list.txt"
ssh "$PI" "/opt/tymos/venv/bin/pip freeze" > "$DIR/pip_freeze.txt"
ssh "$PI" "crontab -l 2>/dev/null || true" > "$DIR/crontab_root.txt"
ssh "$PI" "tar czf /tmp/tymos_root_home.tar.gz --exclude='.cache' --exclude='.local' -C / root"
scp "$PI:/tmp/tymos_root_home.tar.gz" "$DIR/root_home.tar.gz"
ssh "$PI" "rm /tmp/tymos_root_home.tar.gz"

# 3. /etc systemowe
echo "[3/4] /etc..."
ssh "$PI" "tar czf /tmp/tymos_etc.tar.gz -C / etc"
scp "$PI:/tmp/tymos_etc.tar.gz" "$DIR/etc.tar.gz"
ssh "$PI" "rm /tmp/tymos_etc.tar.gz"

# 4. Pliki /opt/tymos/ (tar z excludami na Pi, scp, rozpakuj)
echo "[4/4] pliki /opt/tymos/..."
ssh "$PI" "tar czf /tmp/tymos_files.tar.gz \
  --exclude='venv' \
  --exclude='log' \
  --exclude='.esphome/build' \
  --exclude='.esphome/external_components' \
  --exclude='.esphome/packages' \
  --exclude='*_raw*.json' \
  --exclude='*_raw*.html' \
  --exclude='*_raw*.json_full' \
  -C /opt tymos"
scp "$PI:/tmp/tymos_files.tar.gz" "$DIR/tymos_files.tar.gz"
ssh "$PI" "rm /tmp/tymos_files.tar.gz"

# 5. ESPHome yaml
echo "[5/6] esphome yaml..."
ssh "$PI" "find /opt/esphome -name '*.yaml' | tar czf /tmp/esphome_yaml.tar.gz -T -"
scp "$PI:/tmp/esphome_yaml.tar.gz" "$DIR/esphome_yaml.tar.gz"
ssh "$PI" "rm /tmp/esphome_yaml.tar.gz"

# 6. Z2M data (configuration + database + coordinator_backup)
echo "[6/6] zigbee2mqtt data..."
scp "$PI:/opt/zigbee2mqtt/data/configuration.yaml" "$DIR/z2m_configuration.yaml"
scp "$PI:/opt/zigbee2mqtt/data/database.db" "$DIR/z2m_database.db"
scp "$PI:/opt/zigbee2mqtt/data/coordinator_backup.json" "$DIR/z2m_coordinator_backup.json"

echo ""
echo "=== Gotowe ==="
ls -lh "$DIR"/
echo "Backup: $DIR"
