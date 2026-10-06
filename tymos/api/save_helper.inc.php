<?php // POST — utworz lub zaktualizuj helper (trigger daemon reload)
require_once __DIR__ . '/../inc/helpers.inc.php';

$id = (int)($_POST['id'] ?? 0);
$name = $db->real_escape_string($_POST['name'] ?? '');
$label = $db->real_escape_string($_POST['label'] ?? '');
$type = $db->real_escape_string($_POST['type'] ?? 'text');
$value = $db->real_escape_string($_POST['value'] ?? '');
$min_val = $_POST['min_val'] !== '' ? (float)$_POST['min_val'] : 'NULL';
$max_val = $_POST['max_val'] !== '' ? (float)$_POST['max_val'] : 'NULL';
$step_val = $_POST['step_val'] !== '' ? (float)$_POST['step_val'] : 'NULL';
$unit = $db->real_escape_string($_POST['unit'] ?? '');
if ($id > 0) {
    $db->query("UPDATE helpers SET name='{$name}', label='{$label}', type='{$type}', value='{$value}', min_val={$min_val}, max_val={$max_val}, step_val={$step_val}, unit='{$unit}' WHERE id={$id}");
} else {
    $db->query("INSERT INTO helpers (name, label, type, value, min_val, max_val, step_val, unit) VALUES ('{$name}', '{$label}', '{$type}', '{$value}', {$min_val}, {$max_val}, {$step_val}, '{$unit}')");
    $id = $db->insert_id;
}
tymos_notify_reload($db);
echo json_encode(['reload' => true, 'helper_id' => $id]);
