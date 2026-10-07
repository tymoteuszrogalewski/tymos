# TymOS — installation from scratch

A step-by-step guide for a fresh **Raspberry Pi 5** (or any arm64 / x86-64 machine) with **Debian 13 / Raspberry Pi OS (trixie)**. All commands run as **root**.

TymOS is built for one specific house. This guide gives you a working base system: web panel, database, MQTT, Zigbee and the background services. Adapting the automations to your devices is the next step — ideally together with an AI agent (see [Use it with your AI agent](../README.md#use-it-with-your-ai-agent)).

Optional parts are marked **(optional)** — skip them if you do not have that hardware.

## 1. System packages

```bash
apt update && apt full-upgrade -y
apt install -y apache2 libapache2-mod-php mariadb-server mosquitto mosquitto-clients \
    php php-cli php-mysql php-curl php-mbstring php-gd \
    python3 python3-venv python3-pip git curl openssl cron
```

Node.js 22 or newer (needed by Zigbee2MQTT; Debian ships an older version):

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt install -y nodejs
npm install -g pnpm
```

## 2. Get the code

```bash
git clone https://github.com/tymoteuszrogalewski/tymos.git /opt/tymos
cd /opt/tymos
mkdir -p log && chown www-data:www-data log
```

## 3. Configuration

All private data (passwords, tokens, IP addresses, location) lives in one file:

```bash
cp tymos/config.example.inc.php tymos/config.inc.php
nano tymos/config.inc.php
```

At minimum set: `DB_PASS`, `TYMOS_IP_LAN`, `LAN_GATEWAY`, `MQTT_HOST` (the Pi's own IP), `HOME_LAT` / `HOME_LON` and the timezone. Leave the rest as it is until you add that hardware.

Then generate the system files (Apache, go2rtc, ESPHome) from the `etc/**/*.tpl` templates:

```bash
php scripts/render_etc.php
```

## 4. Database

```bash
DB_PASS=$(sed -n "s/^define('DB_PASS', *'\([^']*\)'.*/\1/p" tymos/config.inc.php)
mysql -e "CREATE DATABASE tymos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -e "CREATE USER 'tymos'@'localhost' IDENTIFIED BY '$DB_PASS'"
mysql -e "GRANT ALL ON tymos.* TO 'tymos'@'localhost'"
mysql tymos < schema.sql

# Tuned for an SD card / SSD: fewer disk writes
cp etc/mysql/mariadb.conf.d/99-tymos.cnf /etc/mysql/mariadb.conf.d/
systemctl restart mariadb
```

Devices do not need to be added by hand — a new Zigbee device appears in the database automatically after its first message.

## 5. MQTT broker

```bash
cat > /etc/mosquitto/conf.d/tymos.conf <<'EOF'
listener 1883
allow_anonymous true
EOF
systemctl restart mosquitto
```

This is an open broker for your local network. If you want a password, create one with `mosquitto_passwd`, set `allow_anonymous false` and fill in `MQTT_USER` / `MQTT_PASS` in the config.

## 6. Web panel (Apache + HTTPS)

A self-signed certificate for the local address:

```bash
mkdir -p etc/apache2/ssl
openssl req -x509 -nodes -days 3650 -newkey rsa:2048 -subj "/CN=tymos.local" \
    -keyout etc/apache2/ssl/tymos.key -out etc/apache2/ssl/tymos.crt
# Placeholder for the VPN certificate (replaced in step 10)
cp etc/apache2/ssl/tymos.crt etc/apache2/ssl/tymos-ts.crt
cp etc/apache2/ssl/tymos.key etc/apache2/ssl/tymos-ts.key

mkdir -p /etc/apache2/ssl
ln -sf /opt/tymos/etc/apache2/ssl/tymos.crt /etc/apache2/ssl/tymos.crt
ln -sf /opt/tymos/etc/apache2/ssl/tymos.key /etc/apache2/ssl/tymos.key
ln -sf /opt/tymos/etc/apache2/tymos.conf /etc/apache2/sites-enabled/tymos.conf
rm -f /etc/apache2/sites-enabled/000-default.conf

a2enmod ssl rewrite headers proxy proxy_http alias
cp -r etc/systemd/system/apache2.service.d /etc/systemd/system/
cp etc/tmpfiles.d/tymos.conf /etc/tmpfiles.d/ && systemd-tmpfiles --create
systemctl daemon-reload && systemctl restart apache2
```

The admin panel can restart the daemons — allow it:

```bash
cat > /etc/sudoers.d/tymos-daemons <<'EOF'
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart tymos-mqtt-listener, /usr/bin/systemctl restart tymos-onvif-bridge, /usr/bin/systemctl restart tymos-blebox-bridge, /usr/bin/systemctl restart tymos-esphome-bridge
EOF
chmod 440 /etc/sudoers.d/tymos-daemons
```

Open `https://<Pi IP>/` in a browser and accept the certificate warning — you should see the TymOS panel (still empty).

## 7. TymOS daemons

```bash
python3 -m venv /opt/tymos/venv
/opt/tymos/venv/bin/pip install -r requirements.txt

cp etc/systemd/system/tymos-mqtt-listener.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now tymos-mqtt-listener
```

`tymos-mqtt-listener` is the heart of the system: it saves every reading and runs the rules engine (including scheduled actions — no system cron needed).

Bridges for non-Zigbee devices — enable only the ones you use:

| Service | For |
|---|---|
| `tymos-esphome-bridge` | ESPHome devices, e.g. the [Zehnder ventilation module](https://github.com/tymoteuszrogalewski/zehnder-comfoair-q-esp32) |
| `tymos-blebox-bridge` | BleBox devices, e.g. the Pstryk energy meter |
| `tymos-onvif-bridge` | camera and doorbell events (motion, person, vehicle, ring) |

```bash
cp etc/systemd/system/tymos-esphome-bridge.service /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now tymos-esphome-bridge
```

Less wear on the SD card — keep system logs in RAM:

```bash
mkdir -p /etc/systemd/journald.conf.d
cp etc/systemd/journald.conf.d/99-tymos.conf /etc/systemd/journald.conf.d/
systemctl restart systemd-journald
```

## 8. Zigbee2MQTT

```bash
git clone --depth 1 https://github.com/Koenkk/zigbee2mqtt.git /opt/zigbee2mqtt
cd /opt/zigbee2mqtt && pnpm install --frozen-lockfile && cd /opt/tymos
```

Create `/opt/zigbee2mqtt/data/configuration.yaml`:

```yaml
mqtt:
  base_topic: zigbee2mqtt
  server: mqtt://localhost:1883
serial:
  # network coordinator (e.g. SMLIGHT SLZB-06): tcp://<coordinator IP>:6638
  # USB stick: /dev/ttyUSB0 (or /dev/serial/by-id/...)
  port: tcp://192.168.1.20:6638
  adapter: ember   # depends on your coordinator: ember / zstack / ...
frontend:
  port: 8080
advanced:
  network_key: GENERATE
  pan_id: GENERATE
  ext_pan_id: GENERATE
```

```bash
cp etc/systemd/system/zigbee2mqtt.service /etc/systemd/system/
cp -r etc/systemd/system/zigbee2mqtt.service.d /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now zigbee2mqtt
```

The drop-in (`zigbee2mqtt.service.d`) waits until a network coordinator answers before Zigbee2MQTT starts — useful after a power cut, when the Pi boots faster than the PoE switch.

Pair devices in the Zigbee2MQTT frontend (`http://<Pi IP>:8080`) — they show up in TymOS on their own.

## 9. Cameras and doorbell — go2rtc (optional)

```bash
curl -L -o /usr/local/bin/go2rtc \
    https://github.com/AlexxIT/go2rtc/releases/latest/download/go2rtc_linux_arm64
chmod +x /usr/local/bin/go2rtc
mkdir -p /etc/go2rtc && ln -sf /opt/tymos/etc/go2rtc/go2rtc.yaml /etc/go2rtc/go2rtc.yaml

cp etc/systemd/system/go2rtc.service etc/systemd/system/tymos-go2rtc-keepalive.service /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now go2rtc tymos-go2rtc-keepalive
```

Use `go2rtc_linux_amd64` on an x86-64 machine. Camera streams are defined in `etc/go2rtc/go2rtc.yaml.tpl` (IPs and passwords come from the config). Enable `tymos-onvif-bridge` from step 7 for camera events.

**Face recognition (optional):** `bash utils/face_setup.sh` (separate venv with OpenCV), then enable `tymos-face.service` the same way.

## 10. Remote access — Tailscale VPN (optional, recommended)

The panel is never exposed to the internet. From outside you reach it through Tailscale:

```bash
curl -fsSL https://tailscale.com/install.sh | sh
tailscale up
```

In the Tailscale admin console turn on **MagicDNS** and **HTTPS Certificates**, put the full name (e.g. `tymos.your-tailnet.ts.net`) and the Tailscale IP into `TYMOS_HOST_VPN` / `TYMOS_IP_VPN` in the config, then:

```bash
php scripts/render_etc.php
utils/tscert_renew.sh          # real Let's Encrypt certificate for the VPN name
( crontab -l 2>/dev/null; echo "0 4 1 * * /opt/tymos/utils/tscert_renew.sh" ) | crontab -
```

The real certificate matters for Telegram: links in notifications open the panel straight to the doorbell call.

## 11. ESPHome dashboard (optional)

Only needed if you want to build and flash ESP devices from the Pi (you can also do it from any computer):

```bash
apt install -y pipx
PIPX_HOME=/opt/pipx PIPX_BIN_DIR=/usr/local/bin pipx install esphome
mkdir -p /opt/esphome
cp etc/systemd/system/esphome.service /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now esphome
```

## 12. Check

```bash
systemctl --failed
systemctl status tymos-mqtt-listener zigbee2mqtt
journalctl -u tymos-mqtt-listener -n 30
mosquitto_sub -t 'zigbee2mqtt/#' -C 5 -v     # do Zigbee messages arrive?
```

Then reboot once (`systemctl reboot`) and check that everything comes back on its own.

## Backup

- `scripts/backup.sh` — runs on the Pi and creates a full snapshot in `/opt/tymos/backup/` (database, `/etc`, code and config, Zigbee2MQTT data, ESPHome files). Schedule it as a TymOS action: admin → Actions → new action → *Cron* `0 15 * * *` → *Run script* `/opt/tymos/scripts/backup.sh`.
- `backup.sh` (in the repository root) — runs on another computer (e.g. a Mac) and pulls a backup from the Pi over SSH (database, `/etc`, `/root`, code and config) to a local folder — an off-site copy. Set `TYMOS_SSH` and `BACKUP_LOCAL_DIR` in the config.
- `scripts/restore.sh` — restores such a snapshot on a fresh system.

## Updating

```bash
cd /opt/tymos && git pull
php scripts/render_etc.php
systemctl restart tymos-mqtt-listener
```
