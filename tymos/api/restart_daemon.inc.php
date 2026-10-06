<?php // POST — restart wybranego demona tymos via sudo systemctl
$daemon = $_POST['daemon'] ?? '';
$allowed = [
    'tymos'   => 'tymos-mqtt-listener',
    'onvif'   => 'tymos-onvif-bridge',
    'blebox'  => 'tymos-blebox-bridge',
    'esphome' => 'tymos-esphome-bridge',
];
if (!isset($allowed[$daemon])) {
    echo json_encode(['error' => 'Nieznany daemon']); exit;
}
$service = $allowed[$daemon];
exec("sudo systemctl restart {$service} 2>&1", $out, $rc);
echo json_encode(['ok' => $rc === 0, 'msg' => $rc === 0 ? "Restart {$service} OK" : implode("\n", $out)]);
