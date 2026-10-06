<?php // POST
$entity = $_POST['entity'] ?? '';
$value  = round((float)($_POST['value'] ?? 0), 1);
$map = ['liwia' => 'liwia_termostat', 'salon' => 'salon_termostat'];
if (!isset($map[$entity])) { echo '{"ok":false}'; exit; }
$hname = $db->real_escape_string($map[$entity]);
$ok = $db->query("UPDATE helpers SET value={$value} WHERE name='{$hname}'");
echo json_encode(['ok' => (bool)$ok]);
