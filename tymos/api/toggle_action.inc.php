<?php // POST — enable/disable akcja (toggle z listy)
require_once __DIR__ . '/../inc/helpers.inc.php';

$id = (int)($_POST['action_id'] ?? 0);
$enabled = (int)($_POST['enabled'] ?? 0);
$db->query("UPDATE actions SET enabled={$enabled} WHERE id={$id}");
tymos_notify_reload($db);
echo json_encode(['reload' => true]);
