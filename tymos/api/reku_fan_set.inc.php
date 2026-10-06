<?php // POST option=Away|Low|Medium|High — ustawia bieg reku BEZTERMINOWO
// Bylo: select „Fan Speed", ktory w komponencie wola set_ventilation_level() z domyslnym
// duration_secs=1, czyli „Manual (limited)" — jednostka zdejmowala to po kilkunastu minutach.
// Teraz to samo co robi ai_rekuperator.php (2026-08-01): Away przez switch „Away Mode"
// (set_away z 0xffffffff), biegi przez przyciski „Manual Permanent X" (tez 0xffffffff).
$option = $_POST['option'] ?? '';
$valid  = ['Away', 'Low', 'Medium', 'High'];
if (!in_array($option, $valid, true)) { echo '{"ok":false}'; exit; }
$espIp = REKU_IP;
$ctx = stream_context_create(['http' => ['method' => 'POST', 'content' => '', 'timeout' => 3, 'header' => "Content-Length: 0\r\n"]]);
$url = ($option === 'Away')
     ? "http://{$espIp}/switch/" . rawurlencode('Away Mode') . '/turn_on'
     : "http://{$espIp}/button/" . rawurlencode("Manual Permanent {$option}") . '/press';
$ok = @file_get_contents($url, false, $ctx);
echo json_encode(['ok' => $ok !== false]);
