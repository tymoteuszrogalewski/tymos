<?php
// Wzor konfiguracji. Skopiuj do config.inc.php (jest w .gitignore) i wpisz swoje wartosci.
// Wartosci ponizej sa PRZYKLADOWE. Kazdy plik kodu i szablony etc/**/*.tpl czytaja dane tylko stad.

date_default_timezone_set('Europe/Warsaw');

// --- Baza danych ---
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_USER', 'tymos');
define('DB_PASS', 'db_password');
define('DB_TYM',  'tymos');

// --- Kamery: tryb streamu w panelu glownym ('webrtc' lub 'hls') ---
define('CAM_MODE', 'webrtc');

// --- Pstryk ---
define('PSTRYK_API_KEY', 'sk-XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');

// --- Telegram (fire-and-forget przez actions/telegram_send.php) ---
define('TG_TOKEN', '123456789:AAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('TG_CHAT',  '123456789');            // prywatny czat z userem — WYCISZONY (zwykle detekcje)
// Kanal alertow — NIEwyciszony: wazne bledy TymOS + detekcje gdy alarm zazbrojony.
// Bot jest tam adminem. Wybor celu: {"chat":"alert"} w JSON dla telegram_send.php.
define('TG_CHAT_ALERT', '-1001234567890');

// --- Koordynator Zigbee (web UI / API restartu) ---
define('DONGLE_IP',   '192.168.1.20');   // SMLIGHT SLZB-06Mg26U (PoE)
define('DONGLE_USER', 'admin');
define('DONGLE_PASS', 'password');

// --- Energa Operator (MojLicznik) ---
define('ENERGA_OPERATOR_USER',  'user@example.com');
define('ENERGA_OPERATOR_PASS',  'password');
define('ENERGA_OPERATOR_METER', '12345678');

// --- Pellet ---
// Cena, dla ktorej skalibrowano stawki PLN/kWh ciepla w actions/heating_cost.php
// (komentarz w tamtym pliku: "pellet Barlinek 1700 zl/t, 4.9 kWh/kg netto").
// Szacunki na wykresach skaluja cost_pellet przez (settings.pellet_cena_rynek / ta stala).
// NIE zmieniac bez przeliczenia stawek w heating_cost.php.
define('PELLET_CENA_BAZOWA', 1700);

// --- Alarm: symulacja obecnosci (poziom 2 NIKOGO) — cykl swiatla w przedsionku ---
define('SIM_OBECNOSC_ON_MIN',  45);   // minut swiecenia
define('SIM_OBECNOSC_OFF_MIN', 20);   // minut przerwy

// --- IP hosta (LAN + Tailscale) ---
define('TYMOS_IP_LAN', '192.168.1.2');
define('TYMOS_IP_VPN', '100.64.0.1');
define('TYMOS_HOST_VPN', 'tymos.tailnet-name.ts.net');   // pelna nazwa MagicDNS (cert Let's Encrypt, linki w Telegramie)
define('LAN_GATEWAY', '192.168.1.1');
define('TYMOS_SSH', 'root@tymos');                       // z Maka: backup.sh, utils/zigbee_scan.py
define('BACKUP_LOCAL_DIR', '/Users/USER/tymos-backup');   // z Maka: backup.sh

// --- MQTT (mosquitto na Pi) ---
define('MQTT_HOST', '192.168.1.2');
define('MQTT_USER', 'mqtt_user');
define('MQTT_PASS', 'password');

// --- Lokalizacja domu (slonce: roleta, wschod/zachod; pogoda) ---
define('HOME_LAT', 52.23);
define('HOME_LON', 21.01);

// --- Urzadzenia w LAN ---
define('REKU_IP',    '192.168.1.30');    // ESPHome rekuperator (Zehnder ComfoAir Q)
define('PRINTER_IP', '192.168.1.40');   // drukarka HP (utils/printer_keepalive.sh)

// --- Kamery (go2rtc: etc/go2rtc/go2rtc.yaml renderowany przez scripts/render_etc.php) ---
define('CAM_TAPO_USER',  'user');   // konto RTSP/ONVIF jednakowe na kazdej kamerze Tapo
define('CAM_TAPO_PASS',  'password');
define('CAM_PARKING_IP', '192.168.1.51');   // Tapo C325WB
define('CAM_BASEN_IP',   '192.168.1.52');   // Tapo C320WS
define('CAM_OGROD_IP',   '192.168.1.53');   // Tapo C325WB (maszt)
define('DOORBELL_IP',    '192.168.1.50');   // Reolink Video Doorbell PoE
define('DOORBELL_USER',  'admin');
define('DOORBELL_PASS',  'password');

define('TYMOS_IP', isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] === TYMOS_IP_VPN ? TYMOS_IP_VPN : TYMOS_IP_LAN);
