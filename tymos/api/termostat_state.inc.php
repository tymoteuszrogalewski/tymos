<?php // GET
$entity = $_GET['entity'] ?? '';
$map = [
    'liwia' => ['dev' => 849, 'helper' => 'liwia_termostat'],
    'salon' => ['dev' => 684, 'helper' => 'salon_termostat'],
];
if (!isset($map[$entity])) { echo '{"ok":false}'; exit; }
$did = $map[$entity]['dev'];
$res = $db->query("SELECT temperature FROM device{$did} ORDER BY ts DESC LIMIT 1");
$temp = ($res && $row = $res->fetch_assoc()) ? round((float)$row['temperature'], 1) : null;
$hname = $map[$entity]['helper'];
$res = $db->query("SELECT value FROM helpers WHERE name='{$hname}'");
$target = ($res && $row = $res->fetch_assoc()) ? round((float)$row['value'], 1) : null;
echo json_encode(['ok' => true, 'temp' => $temp, 'target' => $target]);
