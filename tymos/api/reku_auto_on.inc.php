<?php // POST — wlacza Auto Ventilation (entity name URL — nowy format ESPHome 2026.3+)
$espIp = REKU_IP;
$ctx = stream_context_create(['http' => ['method' => 'POST', 'content' => '', 'timeout' => 3, 'header' => "Content-Length: 0\r\n"]]);
$ok = @file_get_contents("http://{$espIp}/switch/" . rawurlencode('Auto Ventilation') . '/turn_on', false, $ctx);
echo json_encode(['ok' => $ok !== false]);
