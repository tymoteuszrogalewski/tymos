<?php // POST — soft-delete urzadzenia (deleted=1) + lista akcji ktore przestana dzialac
require_once __DIR__ . '/../inc/helpers.inc.php';

$id = (int)($_POST['dev_id'] ?? 0);
$usedIn = [];
$actRows = $db->query("SELECT id, name, trigger_config, conditions, actions FROM actions");
while ($actRows && $ar = $actRows->fetch_assoc()) {
    $json = ($ar['trigger_config'] ?? '') . ($ar['conditions'] ?? '') . ($ar['actions'] ?? '');
    if (strpos($json, '"dev_id":"' . $id . '"') !== false || strpos($json, '"dev_id":' . $id . ',') !== false || strpos($json, '"dev_id":' . $id . '}') !== false) {
        $usedIn[] = $ar['name'];
    }
}
$db->query("UPDATE devices SET deleted=1 WHERE id={$id}");
tymos_notify_reload($db);
$resp = ['reload' => true];
if ($usedIn) $resp['warning'] = 'Akcje ktore przestana dzialac: ' . implode(', ', $usedIn);
echo json_encode($resp);
