<?php // GET
$r = $db->query("SELECT bypass_state, bypass_activation_mode FROM device527 WHERE bypass_state IS NOT NULL AND bypass_activation_mode IS NOT NULL ORDER BY ts DESC LIMIT 1");
$row = $r ? $r->fetch_assoc() : null;
echo json_encode([
    'ok' => true,
    'bypass_state' => $row['bypass_state'] ?? '0',
    'bypass_activation_mode' => $row['bypass_activation_mode'] ?? 'Auto',
]);
