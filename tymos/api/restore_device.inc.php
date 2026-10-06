<?php // POST — przywroc soft-deleted urzadzenie (deleted=0)
require_once __DIR__ . '/../inc/helpers.inc.php';

$id = (int)($_POST['dev_id'] ?? 0);
$db->query("UPDATE devices SET deleted=0 WHERE id={$id}");
tymos_notify_reload($db);
echo json_encode(['reload' => true]);
