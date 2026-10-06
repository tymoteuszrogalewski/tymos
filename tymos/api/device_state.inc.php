<?php // GET ?dev_id=N — zwraca state urzadzenia z devices.last_state
$id = (int)($_GET['dev_id'] ?? 0);
$res = $db->query("SELECT last_state FROM devices WHERE id={$id}");
$ls  = ($res && $row = $res->fetch_assoc()) ? (json_decode($row['last_state'] ?? '{}', true) ?: []) : [];
$state = $ls['state'] ?? 'unknown';
echo json_encode(['ok' => true, 'state' => $state]);
