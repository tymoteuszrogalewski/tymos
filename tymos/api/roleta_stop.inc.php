<?php // POST
require_once __DIR__ . '/../inc/mqtt.inc.php';
$ok = mqtt_publish('zigbee2mqtt/Roleta/set', '{"state":"STOP"}');

// Manual lock — patrz roleta_move.inc.php
$ts = time();
$until = $ts + 10800;
$db->query("INSERT INTO helpers (name, value) VALUES ('roleta_cmd_ts', '{$ts}') ON DUPLICATE KEY UPDATE value='{$ts}'");
$db->query("INSERT INTO helpers (name, value) VALUES ('roleta_manual_until', '{$until}') ON DUPLICATE KEY UPDATE value='{$until}'");
$db->query("INSERT INTO helpers (name, value) VALUES ('roleta_manual_lock', '1') ON DUPLICATE KEY UPDATE value='1'");
$db->query("UPDATE settings SET v=UNIX_TIMESTAMP() WHERE k='tymos_reload'");

echo json_encode(['ok' => $ok]);
