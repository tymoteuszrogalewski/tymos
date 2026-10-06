<?php // GET — stan Fan Speed + Auto Ventilation z ESPHome (entity name, nowy format)
$espIp = REKU_IP;
$ctx = stream_context_create(['http' => ['timeout' => 2]]);
$speed = null; $auto = null;
$j = @file_get_contents("http://{$espIp}/select/" . rawurlencode('Fan Speed'), false, $ctx);
if ($j) { $d = json_decode($j, true); $speed = $d['value'] ?? null; }
$j = @file_get_contents("http://{$espIp}/switch/" . rawurlencode('Auto Ventilation'), false, $ctx);
if ($j) { $d = json_decode($j, true); $auto = !empty($d['value']); }
if ($speed === null) {
    $r = $db->query("SELECT fan_speed, auto_ventilation FROM device527 WHERE fan_speed IS NOT NULL AND auto_ventilation IS NOT NULL ORDER BY ts DESC LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) {
        $speed = $row['fan_speed'] ?? null;
        $auto  = !empty($row['auto_ventilation']);
    }
}
echo json_encode(['ok' => true, 'speed' => $speed, 'auto' => $auto]);
