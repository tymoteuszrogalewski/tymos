<?php // POST
require_once __DIR__ . '/../inc/mqtt.inc.php';
$dir = $_POST['dir'] ?? '';
if ($dir === 'zamknij') $payload = '{"state":"CLOSE"}';
elseif ($dir === 'otworz') $payload = '{"state":"OPEN"}';
else { echo '{"ok":false}'; exit; }
$ok = mqtt_publish('zigbee2mqtt/Roleta/set', $payload);

// Manual lock: blokada wszystkich akcji Roleta* na 3h (poza roleta_salon_otworz_po_sloncu — immunny)
// roleta_cmd_ts — anti-detection dla detect_manual (rozroznienie GUI klik vs scienny przycisk)
// roleta_manual_until — timestamp konca blokady (do skryptow PHP)
// roleta_manual_lock — flaga 0/1 (do warunkow akcji w GUI; czyszczone przez roleta_lock_clear.php)
$ts = time();
$until = $ts + 10800;
$db->query("INSERT INTO helpers (name, value) VALUES ('roleta_cmd_ts', '{$ts}') ON DUPLICATE KEY UPDATE value='{$ts}'");
$db->query("INSERT INTO helpers (name, value) VALUES ('roleta_manual_until', '{$until}') ON DUPLICATE KEY UPDATE value='{$until}'");
$db->query("INSERT INTO helpers (name, value) VALUES ('roleta_manual_lock', '1') ON DUPLICATE KEY UPDATE value='1'");
$db->query("UPDATE settings SET v=UNIX_TIMESTAMP() WHERE k='tymos_reload'");

echo json_encode(['ok' => $ok]);
