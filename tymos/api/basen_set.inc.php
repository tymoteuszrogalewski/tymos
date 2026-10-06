<?php // POST — ustaw force_basen (auto|on|off). ai_basen.php czyta na swiezo co minute (bez reloadu).
$value = $_POST['value'] ?? '';
if (!in_array($value, ['auto', 'on', 'off'], true)) { echo '{"ok":false}'; exit; }
$ok = $db->query("UPDATE helpers SET value='" . $db->real_escape_string($value) . "' WHERE name='force_basen'");
echo json_encode(['ok' => (bool)$ok, 'value' => $value]);
