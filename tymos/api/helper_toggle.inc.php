<?php // POST
$name = $_POST['name'] ?? '';
$allowed = ['piec_wlaczony', 'podlogowka_wlaczona', 'alarm_zazbrojony'];
if (!in_array($name, $allowed, true)) { echo '{"ok":false}'; exit; }
$res = $db->query("SELECT value FROM helpers WHERE name='" . $db->real_escape_string($name) . "'");
$cur = ($res && $row = $res->fetch_assoc()) ? (int)$row['value'] : 0;
$new = $cur ? 0 : 1;
$ok = $db->query("UPDATE helpers SET value='{$new}' WHERE name='" . $db->real_escape_string($name) . "'");
echo json_encode(['ok' => (bool)$ok, 'value' => $new]);
