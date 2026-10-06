<?php // POST — usun helper (trigger daemon reload)
require_once __DIR__ . '/../inc/helpers.inc.php';

$id = (int)($_POST['id'] ?? 0);
$db->query("DELETE FROM helpers WHERE id={$id}");
tymos_notify_reload($db);
echo json_encode(['reload' => true]);
