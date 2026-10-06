<?php // GET
$name = $_GET['name'] ?? '';
if (!preg_match('/^[a-z_]+$/', $name)) { echo '{"ok":false}'; exit; }
$res = $db->query("SELECT value FROM helpers WHERE name='" . $db->real_escape_string($name) . "'");
$val = ($res && $row = $res->fetch_assoc()) ? $row['value'] : '0';
echo json_encode(['ok' => true, 'value' => $val]);
