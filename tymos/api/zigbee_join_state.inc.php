<?php // GET
// Stan otwarcia sieci Zigbee dla ikony na kamerze. Zrodlo prawdy = retained `bridge/info` od Z2M
// (a nie nasze helpery), zeby ikona pokazywala RZECZYWISTOSC — takze gdy ktos otworzyl siec z panelu
// Z2M albo zrobil to watchdog rejoinu (ai_zigbee_rejoin.php).
// `force` mowi tylko, czy trzyma to nasze reczne „na stale" — do rozroznienia w tooltipie.
// NAJPIERW: czy Z2M w ogole zyje. `bridge/info` jest retained, wiec po padzie Z2M wisi tam STARA
// wartosc — bez tej kontroli ikona pokazywala „siec otwarta", choc koordynator lezal, a klikniecie
// nie robilo nic (zadanie szlo do martwego Z2M). Zglosil user 2026-08-21.
$st = json_decode((string)shell_exec('timeout 3 mosquitto_sub -h localhost -t zigbee2mqtt/bridge/state -C 1 2>/dev/null'), true) ?: [];
if (($st['state'] ?? '') !== 'online') {
    echo json_encode(['ok' => true, 'on' => false, 'force' => false, 'until' => 0, 'end' => null, 'offline' => true]);
    exit;
}

$json = shell_exec('timeout 3 mosquitto_sub -h localhost -t zigbee2mqtt/bridge/info -C 1 2>/dev/null');
$info = json_decode((string)$json, true) ?: [];
$on   = !empty($info['permit_join']);
$end  = isset($info['permit_join_end']) ? (int)round($info['permit_join_end'] / 1000) : null;

$r = $db->query("SELECT value FROM helpers WHERE name='zrejoin_force' LIMIT 1");
$force = ($r && $row = $r->fetch_row()) ? ((int)$row[0] === 1) : false;
$r = $db->query("SELECT value FROM helpers WHERE name='zrejoin_until' LIMIT 1");
$until = ($r && $row = $r->fetch_row()) ? (int)$row[0] : 0;

echo json_encode(['ok' => true, 'on' => $on, 'force' => $force, 'until' => $until, 'end' => $end, 'offline' => false]);
