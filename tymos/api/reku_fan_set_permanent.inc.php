<?php // POST option=Low|Medium|High — Manual permanent przez dedykowany ESPHome button
// Yaml button woła send_command_set_timer(..., 0xffffffff) = ~136 lat = realny permanent
// (bypassuje bug yoziru gdzie default duration_secs=1 → wewnetrzny timer ~10min)
$option = $_POST['option'] ?? '';
$valid  = ['Low', 'Medium', 'High'];
if (!in_array($option, $valid, true)) { echo '{"ok":false}'; exit; }
$espIp = REKU_IP;
$ctx = stream_context_create(['http' => ['method' => 'POST', 'content' => '', 'timeout' => 3, 'header' => "Content-Length: 0\r\n"]]);
$url = "http://{$espIp}/button/" . rawurlencode("Manual Permanent {$option}") . "/press";
$ok = @file_get_contents($url, false, $ctx);
echo json_encode(['ok' => $ok !== false]);
