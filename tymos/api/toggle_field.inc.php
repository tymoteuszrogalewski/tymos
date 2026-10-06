<?php // POST — wlacz/wylacz pole w enabled_fields urzadzenia (do wykresow/zapisu historii)
require_once __DIR__ . '/../inc/helpers.inc.php';

$id = (int)($_POST['dev_id'] ?? 0);
$field = $_POST['field'] ?? '';
$enabled = (int)($_POST['enabled'] ?? 0);

$row = $db->query("SELECT fields, enabled_fields FROM devices WHERE id={$id}")->fetch_assoc();
if (!$row) { echo json_encode(['error' => 'not found']); exit; }

$fields = json_decode($row['fields'], true) ?: [];
$ef = $row['enabled_fields'] ? json_decode($row['enabled_fields'], true) : [];

if ($enabled && !in_array($field, $ef)) {
    $ef[] = $field;
} elseif (!$enabled) {
    $ef = array_values(array_diff($ef, [$field]));
}

$efJson = $db->real_escape_string(json_encode(array_values($ef)));
$db->query("UPDATE devices SET enabled_fields='{$efJson}' WHERE id={$id}");
tymos_notify_reload($db);
echo json_encode(['reload' => true]);
