#!/bin/bash
# TymOS disaster recovery — fresh Debian (RPi OS) -> working tymos.
#
# Uzycie:
#   1. Swiezy Debian na RPi (lub VM). Zmien hostname na "tymos".
#   2. apt update && apt install -y rsync
#   3. Skopiuj backup z Maca:
#        scp ~/Documents/Backup/Tymos/tymos-LATEST.tar.gz root@NEW_PI:/tmp/
#   4. Na nowym Pi:
#        cd /tmp && tar xzf tymos-LATEST.tar.gz
#        bash restore.sh
#   5. Reboot.
#
# Skrypt jest IDEMPOTENTNY — mozna re-runowac jak cos sie nie powiedzie.

set -e

if [ "$EUID" -ne 0 ]; then echo "Uruchom jako root"; exit 1; fi

WORK=$(pwd)
echo "=== TymOS restore z $WORK ==="

# 1. Pakiety APT — instaluj kluczowe (lista pelna w dpkg-list.txt do referencji)
echo "[1/9] apt install paczek..."
apt update
apt install -y \
    apache2 mariadb-server mosquitto mosquitto-clients \
    php php-cli php-mysqli php-curl php-mbstring php-gd \
    libapache2-mod-php \
    python3 python3-venv python3-pip \
    nodejs npm \
    git rsync curl wget \
    systemd cron

# 2. Restore /etc (selektywnie — tymos-specific, zeby nie nadpisywac fresh Debian)
echo "[2/9] restore /etc (selektywnie)..."
tar xzf etc.tar.gz -C /tmp/restore-etc 2>/dev/null || mkdir -p /tmp/restore-etc && tar xzf etc.tar.gz -C /tmp/restore-etc
for f in \
    apache2/sites-available/tymos.conf \
    apache2/ssl/tymos.crt apache2/ssl/tymos.key \
    systemd/system/tymos-mqtt-listener.service \
    systemd/system/tymos-onvif-bridge.service \
    systemd/system/tymos-blebox-bridge.service \
    systemd/system/tymos-esphome-bridge.service \
    sudoers.d/tymos-daemons \
    mosquitto/conf.d/tymos.conf \
    tmpfiles.d/tymos.conf
do
    [ -f "/tmp/restore-etc/etc/$f" ] && install -D "/tmp/restore-etc/etc/$f" "/etc/$f"
done

# Cron
[ -f /tmp/restore-etc/etc/cron.d/tymos ] && cp /tmp/restore-etc/etc/cron.d/tymos /etc/cron.d/tymos
mkdir -p /etc/apache2/sites-enabled
ln -sf /etc/apache2/sites-available/tymos.conf /etc/apache2/sites-enabled/tymos.conf

# 3. /opt/tymos (kod aplikacji)
echo "[3/9] restore /opt/tymos..."
mkdir -p /opt
tar xzf opt-tymos.tar.gz -C /opt

# 4. /root (klucze SSH, skrypty)
echo "[4/9] restore /root..."
tar xzf root-home.tar.gz -C /
chmod 700 /root/.ssh 2>/dev/null || true
chmod 600 /root/.ssh/authorized_keys /root/.ssh/id_* 2>/dev/null || true

# 5. MySQL DB
echo "[5/9] mysql import..."
systemctl start mariadb
mysql -e "CREATE DATABASE IF NOT EXISTS tymos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
# User tymos (haslo z /opt/tymos/tymos/config.inc.php — tu hardcode bo restore)
DB_PASS=$(grep "DB_PASS" /opt/tymos/tymos/config.inc.php | cut -d"'" -f4)
mysql -e "CREATE USER IF NOT EXISTS 'tymos'@'localhost' IDENTIFIED BY '$DB_PASS'"
mysql -e "GRANT ALL ON tymos.* TO 'tymos'@'localhost'; FLUSH PRIVILEGES"
zcat db.sql.gz | mysql tymos

# 6. Z2M data
echo "[6/9] zigbee2mqtt data..."
mkdir -p /opt/zigbee2mqtt/data
[ -f z2m_configuration.yaml ]      && cp z2m_configuration.yaml      /opt/zigbee2mqtt/data/configuration.yaml
[ -f z2m_database.db ]             && cp z2m_database.db             /opt/zigbee2mqtt/data/database.db
[ -f z2m_coordinator_backup.json ] && cp z2m_coordinator_backup.json /opt/zigbee2mqtt/data/coordinator_backup.json

# 7. ESPHome yamls
if [ -f esphome-yaml.tar.gz ]; then
    echo "[7/9] esphome yamls..."
    mkdir -p /opt/esphome
    tar xzf esphome-yaml.tar.gz -C /
fi

# 8. Python venv (re-create)
if [ -f pip-freeze.txt ] && [ -d /opt/tymos ]; then
    echo "[8/9] python venv..."
    python3 -m venv /opt/tymos/venv
    /opt/tymos/venv/bin/pip install --upgrade pip
    /opt/tymos/venv/bin/pip install -r pip-freeze.txt || echo "  UWAGA: niektore pakiety pip moga wymagac recznej instalacji"
fi

# 9. Crontab + uslugi
echo "[9/9] crontab + services..."
[ -f crontab-root.txt ] && crontab crontab-root.txt

a2enmod ssl rewrite proxy proxy_http headers 2>/dev/null || true

systemctl daemon-reload
systemctl enable apache2 mariadb mosquitto cron
systemctl enable tymos-mqtt-listener tymos-onvif-bridge tymos-blebox-bridge tymos-esphome-bridge 2>/dev/null || true

systemctl restart apache2 mariadb mosquitto

echo ""
echo "=== Restore zakonczony ==="
echo "Reboot: systemctl reboot"
echo ""
echo "Po reboocie sprawdz:"
echo "  systemctl status tymos-mqtt-listener"
echo "  curl -k https://localhost/api.php?action=devices_list"
