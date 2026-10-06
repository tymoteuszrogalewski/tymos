<?php // POST — reczne ON/OFF sekcji podlewania (tryb MANUAL). Jeden zawor naraz (enforceSingle w irrigation.php).
$channel = $_POST['channel'] ?? '';
$act     = $_POST['mact'] ?? '';   // 'on' | 'off'
if (!in_array($act, ['on', 'off'], true)) { echo '{"ok":false}'; exit; }

// tylko w trybie manual (inaczej auto/off i tak wyczyscilyby wybor)
$r = $db->query("SELECT value FROM helpers WHERE name='irrigation_mode'");
$mode = ($r && $x = $r->fetch_assoc()) ? $x['value'] : '';
if ($mode !== 'manual') { echo '{"ok":false,"error":"not manual"}'; exit; }

if ($act === 'on') {
    // kanal musi byc przypisany do wlaczonej strefy
    $r = $db->query("SELECT 1 FROM irrigation_zones WHERE dev_channel='" . $db->real_escape_string($channel) . "' AND enabled=1 LIMIT 1");
    if (!$r || $r->num_rows === 0) { echo '{"ok":false,"error":"unknown channel"}'; exit; }
    $zone = $channel;
} else {
    $zone = '';
}
$db->query("UPDATE helpers SET value='" . $db->real_escape_string($zone) . "' WHERE name='irrigation_manual_zone'");
// natychmiast zastosuj: enforceSingle w irrigation.php (OFF innych -> zwloka -> ON wybranej)
exec('php /opt/tymos/actions/irrigation.php > /dev/null 2>&1 &');
echo json_encode(['ok' => true, 'manual_zone' => $zone]);
