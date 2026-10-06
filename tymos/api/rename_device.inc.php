<?php // POST — rename urzadzenia (Zigbee: takze MQTT rename w Z2M) + reload daemona
require_once __DIR__ . '/../inc/helpers.inc.php';
require_once __DIR__ . '/../inc/mqtt.inc.php';

$id = (int)($_POST['dev_id'] ?? 0);
$newName = trim($_POST['new_name'] ?? '');
if (!$newName) { echo json_encode(['error' => 'Pusta nazwa']); exit; }
$row = $db->query("SELECT device, ieee FROM devices WHERE id={$id}")->fetch_assoc();
if (!$row) { echo json_encode(['error' => 'Nie znaleziono']); exit; }
$oldName = $row['device'];
$isZigbee = strpos($row['ieee'], '0x') === 0;

$ok = $db->query("UPDATE devices SET device='" . $db->real_escape_string($newName) . "' WHERE id={$id}");
if (!$ok) { echo json_encode(['error' => $db->error]); exit; }

$z2mOk = true;
if ($isZigbee) {
    $payload = json_encode(['from' => $oldName, 'to' => $newName]);
    $z2mOk = mqtt_publish('zigbee2mqtt/bridge/request/device/rename', $payload);
}
tymos_notify_reload($db);
$resp = ['reload' => true, 'detail' => $id];
if (!$z2mOk) $resp['warning'] = 'Zmieniono w bazie, ale Z2M rename nie powiodl sie';
echo json_encode($resp);
