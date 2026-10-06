<?php // POST
// Ustawienie poziomu alarmu: 0 rozbrojony / 1 NOC / 2 NIKOGO (helper alarm_zazbrojony).
// Osobny endpoint, bo helper_toggle umie tylko 0/1.
$v = (string)($_POST['v'] ?? '');
if (!in_array($v, ['0', '1', '2'], true)) { echo '{"ok":false}'; exit; }
$ok = $db->query("UPDATE helpers SET value='{$v}' WHERE name='alarm_zazbrojony'");
echo json_encode(['ok' => (bool)$ok, 'value' => (int)$v]);
