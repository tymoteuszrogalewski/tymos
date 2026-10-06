<?php // POST — toggle Auto Ventilation (entity name URL)
$espIp = REKU_IP;
$ctx = stream_context_create(['http' => ['method' => 'POST', 'content' => '', 'timeout' => 3, 'header' => "Content-Length: 0\r\n"]]);
$ok = @file_get_contents("http://{$espIp}/switch/" . rawurlencode('Auto Ventilation') . '/toggle', false, $ctx);
echo json_encode(['ok' => $ok !== false]);
