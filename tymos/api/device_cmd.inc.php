<?php // POST — publish MQTT komende do urzadzenia (ON/OFF/TOGGLE/set pole). Auto-TOGGLE wg aktualnego stanu.
$id = (int)($_POST['dev_id'] ?? 0);
$payload = $_POST['payload'] ?? '{}';
$payloadArr = json_decode($payload, true) ?: [];
if (isset($payloadArr['state']) && strtoupper($payloadArr['state']) === 'TOGGLE') {
    // Aktualny stan z devices.last_state (JSON) — tabela device<ID> NIE zawsze ma kolumne `state`
    // (zalezy od enabled_fields; np. dev551 jej nie ma -> SELECT state fatalowal, komenda nie szla).
    $lsRow  = $db->query("SELECT last_state FROM devices WHERE id={$id}")->fetch_assoc();
    $ls0    = json_decode($lsRow['last_state'] ?? '{}', true) ?: [];
    $curState = $ls0['state'] ?? 'OFF';
    $payloadArr['state'] = (strtoupper((string)$curState) === 'ON') ? 'OFF' : 'ON';
    $payload = json_encode($payloadArr);
}
$row = $db->query("SELECT device, ieee FROM devices WHERE id={$id}")->fetch_assoc();
if (!$row) { echo json_encode(['error' => 'Brak urzadzenia']); exit; }
$ieee = $row['ieee'] ?? '';
$name = $row['device'] ?? '';
$topic = '';
if (strpos($ieee, '0x') === 0) {
    // Wybor topicu: jesli payload zawiera control-field (state/switch/position/brightness itp.)
    // → zigbee2mqtt/<name>/set (actuator command).
    // W przeciwnym razie → zigbee2mqtt/bridge/request/device/options (config jak no_occupancy_since).
    $controlPrefixes = ['state', 'switch_', 'position', 'tilt', 'brightness', 'color', 'on_off'];
    $isOption = !empty($payloadArr);
    foreach ($payloadArr as $k => $v) {
        foreach ($controlPrefixes as $p) {
            if ($k === $p || strpos($k, $p) === 0) { $isOption = false; break 2; }
        }
    }
    if ($isOption) {
        $topic = "zigbee2mqtt/bridge/request/device/options";
        $opts = $payloadArr;
        if (isset($opts['no_occupancy_since'])) {
            $v = $opts['no_occupancy_since'];
            $opts['no_occupancy_since'] = is_array($v) ? $v : [(int)$v];
        }
        $payload = json_encode(['id' => $name, 'options' => $opts]);
    } else {
        $topic = "zigbee2mqtt/{$name}/set";
    }
}
if (!$topic) { echo json_encode(['error' => 'Urzadzenie nie obsluguje sterowania']); exit; }

$mqtt_host = MQTT_HOST;
$sock = @fsockopen($mqtt_host, 1883, $errno, $errstr, 2);
if (!$sock) { echo json_encode(['error' => "MQTT connect failed: $errstr"]); exit; }
$clientId = "tymos_web_" . getmypid();
$user = MQTT_USER;
$pass = MQTT_PASS;
$connectPayload = chr(0x00) . chr(0x04) . "MQTT" . chr(0x04) . chr(0xC2) . chr(0x00) . chr(0x3C);
$connectPayload .= chr(0x00) . chr(strlen($clientId)) . $clientId;
$connectPayload .= chr(0x00) . chr(strlen($user)) . $user;
$connectPayload .= chr(0x00) . chr(strlen($pass)) . $pass;
fwrite($sock, chr(0x10) . chr(strlen($connectPayload)) . $connectPayload);
$resp = fread($sock, 4);
if (strlen($resp) < 4 || ord($resp[3]) !== 0) { fclose($sock); echo json_encode(['error' => 'MQTT auth failed']); exit; }
$topicLen = strlen($topic);
$pubPayload = chr(($topicLen >> 8) & 0xFF) . chr($topicLen & 0xFF) . $topic . $payload;
$pubLen = strlen($pubPayload);
$rl = '';
do {
    $byte = $pubLen % 128;
    $pubLen = (int)($pubLen / 128);
    if ($pubLen > 0) $byte |= 0x80;
    $rl .= chr($byte);
} while ($pubLen > 0);
fwrite($sock, chr(0x30) . $rl . $pubPayload);
fwrite($sock, chr(0xE0) . chr(0x00));
fclose($sock);
// Zapisz wyslane wartosci do last_state (zeby nie nadpisalo stara wartoscia z push)
$ls = json_decode($db->query("SELECT last_state FROM devices WHERE id={$id}")->fetch_assoc()['last_state'] ?? '{}', true) ?: [];
foreach ($payloadArr as $k => $v) { if ($v !== '' && $v !== null) $ls[$k] = $v; }
$db->query("UPDATE devices SET last_state='" . $db->real_escape_string(json_encode($ls)) . "' WHERE id={$id}");
echo json_encode(['ok' => true, 'topic' => $topic]);
