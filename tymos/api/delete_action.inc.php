<?php // POST — usun akcje + reload daemona
require_once __DIR__ . '/../inc/helpers.inc.php';

$id = (int)($_POST['action_id'] ?? 0);
$db->query("DELETE FROM actions WHERE id={$id}");
tymos_notify_reload($db);
echo json_encode(['reload' => true]);
