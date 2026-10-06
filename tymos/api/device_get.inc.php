<?php // POST — wyslij Z2M GET dla pol writable (access & 2) enabled w urzadzeniu
$id = (int)($_POST['dev_id'] ?? 0);
$row = $db->query("SELECT device, ieee, fields, enabled_fields FROM devices WHERE id={$id}")->fetch_assoc();
if (!$row || strpos($row['ieee'], '0x') !== 0) { echo json_encode(['ok' => false]); exit; }
$fields = json_decode($row['fields'] ?? '{}', true) ?: [];
$ef = json_decode($row['enabled_fields'] ?? '[]', true) ?: [];
$getPayload = [];
foreach ($fields as $fname => $finfo) {
    if (!is_array($finfo)) continue;
    if (($finfo['access'] ?? 0) & 2 && in_array($fname, $ef)) $getPayload[$fname] = '';
}
if (!$getPayload) { echo json_encode(['ok' => true, 'skipped' => true]); exit; }
$topic = "zigbee2mqtt/{$row['device']}/get";
$payload = json_encode((object)$getPayload);
$mqtt_host = MQTT_HOST;
$sock = @fsockopen($mqtt_host, 1883, $errno, $errstr, 2);
if (!$sock) { echo json_encode(['ok' => false, 'error' => 'MQTT']); exit; }
$clientId = "tymos_get_" . getmypid();
$user = MQTT_USER;
$pass = MQTT_PASS;
$cp = chr(0x00).chr(0x04)."MQTT".chr(0x04).chr(0xC2).chr(0x00).chr(0x3C);
$cp .= chr(0x00).chr(strlen($clientId)).$clientId;
$cp .= chr(0x00).chr(strlen($user)).$user;
$cp .= chr(0x00).chr(strlen($pass)).$pass;
fwrite($sock, chr(0x10).chr(strlen($cp)).$cp);
$resp = fread($sock, 4);
if (strlen($resp) < 4 || ord($resp[3]) !== 0) { fclose($sock); echo json_encode(['ok' => false]); exit; }
$tl = strlen($topic);
$pp = chr(($tl>>8)&0xFF).chr($tl&0xFF).$topic.$payload;
$rl = strlen($pp); $enc = '';
do { $b = $rl % 128; $rl = (int)($rl/128); if ($rl > 0) $b |= 0x80; $enc .= chr($b); } while ($rl > 0);
fwrite($sock, chr(0x30).$enc.$pp);
fwrite($sock, chr(0xE0).chr(0x00));
fclose($sock);
echo json_encode(['ok' => true]);
