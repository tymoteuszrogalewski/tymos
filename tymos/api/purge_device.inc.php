<?php // POST — HARD delete urzadzenia: DROP tabeli device<ID> + DELETE z devices
require_once __DIR__ . '/../inc/helpers.inc.php';

$id = (int)($_POST['dev_id'] ?? 0);
$db->query("DROP TABLE IF EXISTS device{$id}");
$db->query("DELETE FROM devices WHERE id={$id}");
tymos_notify_reload($db);
echo json_encode(['reload' => true]);
