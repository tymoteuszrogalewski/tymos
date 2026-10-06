<?php // POST
$option = $_POST['option'] ?? '';
$espIp = REKU_IP;
$endpoints = [
    'auto'  => '/button/bypass_auto/press',
    'on1h'  => '/button/bypass_on__1h_/press',
    'on12h' => '/button/bypass_on__12h_/press',
];
if (!isset($endpoints[$option])) { echo '{"ok":false}'; exit; }
$ctx = stream_context_create(['http' => ['method' => 'POST', 'content' => '', 'timeout' => 3, 'header' => "Content-Length: 0\r\n"]]);
$ok = @file_get_contents("http://{$espIp}" . $endpoints[$option], false, $ctx);
echo json_encode(['ok' => $ok !== false]);
