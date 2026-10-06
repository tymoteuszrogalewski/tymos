<?php // GET
// Log z przegladarki kiosku do tabeli `log` (source='js') — diagnostyka gdy nie ma dostepu do konsoli iPada.
$msg   = substr((string)($_GET['msg'] ?? ''), 0, 500);
$level = ($_GET['level'] ?? 'INFO');
if (!in_array($level, ['INFO', 'WARN', 'ERROR'], true)) $level = 'INFO';
if ($msg === '') { echo '{"ok":false}'; exit; }
$stmt = $db->prepare("INSERT INTO log (level, source, message) VALUES (?, 'js', ?)");
$stmt->bind_param('ss', $level, $msg);
$stmt->execute();
$stmt->close();
echo '{"ok":true}';
