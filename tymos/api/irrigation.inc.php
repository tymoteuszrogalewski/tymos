<?php
// GET: lista stref, helperow, dostepnych kanalow
// POST: sub_action = add_zone, update_zone, delete_zone, update_helper, toggle_enabled

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Strefy
    $zones = [];
    $r = $db->query("SELECT * FROM irrigation_zones ORDER BY sort_order, id");
    if ($r) while ($row = $r->fetch_assoc()) $zones[] = $row;

    // Helpery
    $helpers = [];
    $r = $db->query("SELECT name, value FROM helpers WHERE name LIKE 'irrigation_%'");
    if ($r) while ($row = $r->fetch_assoc()) $helpers[$row['name']] = $row['value'];

    // Dostepne kanaly (z 4ch switchy — urzadzenia z state_l1..l4)
    $channels = [];
    $r = $db->query("SELECT id, device, fields, channel_labels FROM devices WHERE deleted=0 AND fields LIKE '%state_l1%' AND fields LIKE '%state_l3%'");
    if ($r) while ($row = $r->fetch_assoc()) {
        $flds = json_decode($row['fields'], true) ?: [];
        $chLabels = json_decode($row['channel_labels'] ?? 'null', true) ?: [];
        for ($ch = 1; $ch <= 4; $ch++) {
            $key = "state_l{$ch}";
            if (isset($flds[$key])) {
                $label = $chLabels[(string)$ch] ?? "Kanal {$ch}";
                $channels[] = [
                    'value' => $row['id'] . ':' . $key,
                    'label' => $label,
                ];
            }
        }
    }

    // Status: aktualnie podlewa?
    $status = null;
    $r = $db->query("SELECT value FROM helpers WHERE name='irrigation_running_zone'");
    if ($r && $row = $r->fetch_assoc()) $status = $row['value'];

    echo json_encode(['ok' => true, 'zones' => $zones, 'helpers' => $helpers, 'channels' => $channels, 'running_zone' => $status]);
    exit;
}

// POST
$sub = $_POST['sub_action'] ?? '';

if ($sub === 'add_zone') {
    $maxSort = 0;
    $r = $db->query("SELECT MAX(sort_order) AS m FROM irrigation_zones");
    if ($r && $row = $r->fetch_assoc()) $maxSort = (int)($row['m'] ?? 0);
    $db->query("INSERT INTO irrigation_zones (name, sort_order) VALUES ('Nowa strefa', " . ($maxSort + 1) . ")");
    echo json_encode(['ok' => true, 'id' => $db->insert_id]);
    exit;
}

if ($sub === 'update_zone') {
    $id = (int)($_POST['zone_id'] ?? 0);
    if (!$id) { echo '{"ok":false}'; exit; }
    $sets = [];
    if (isset($_POST['name'])) $sets[] = "name='" . $db->real_escape_string($_POST['name']) . "'";
    if (isset($_POST['dev_channel'])) {
        $dc = $_POST['dev_channel'];
        if ($dc === '') $sets[] = "dev_channel=NULL";
        else $sets[] = "dev_channel='" . $db->real_escape_string($dc) . "'";
    }
    if (isset($_POST['duration_min'])) $sets[] = "duration_min=" . max(5, (int)$_POST['duration_min']);
    if (isset($_POST['mode'])) {
        $m = $_POST['mode'];
        if (in_array($m, ['rano', 'wieczor', 'oba', 'none'])) $sets[] = "mode='" . $m . "'";
    }
    if (isset($_POST['enabled'])) $sets[] = "enabled=" . ((int)$_POST['enabled'] ? 1 : 0);
    if (isset($_POST['days']) && preg_match('/^[01]{7}$/', $_POST['days'])) $sets[] = "days='" . $_POST['days'] . "'";
    if ($sets) $db->query("UPDATE irrigation_zones SET " . implode(', ', $sets) . " WHERE id={$id}");
    echo json_encode(['ok' => true]);
    exit;
}

if ($sub === 'delete_zone') {
    $id = (int)($_POST['zone_id'] ?? 0);
    if ($id) $db->query("DELETE FROM irrigation_zones WHERE id={$id}");
    echo json_encode(['ok' => true]);
    exit;
}

if ($sub === 'move_zone') {
    $id = (int)($_POST['zone_id'] ?? 0);
    $dir = $_POST['direction'] ?? '';
    if (!$id || !in_array($dir, ['up', 'down'])) { echo '{"ok":false}'; exit; }
    // Normalizuj sort_order (compact 0..N-1 wg aktualnej kolejnosci)
    $rows = [];
    $r = $db->query("SELECT id FROM irrigation_zones ORDER BY sort_order, id");
    while ($row = $r->fetch_assoc()) $rows[] = (int)$row['id'];
    $idx = array_search($id, $rows, true);
    if ($idx === false) { echo '{"ok":false}'; exit; }
    $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
    if ($swap < 0 || $swap >= count($rows)) { echo '{"ok":true}'; exit; }
    // Zamien pozycje w tablicy
    $tmp = $rows[$idx]; $rows[$idx] = $rows[$swap]; $rows[$swap] = $tmp;
    // Zapis: sort_order = pozycja
    foreach ($rows as $i => $zid) {
        $db->query("UPDATE irrigation_zones SET sort_order={$i} WHERE id={$zid}");
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($sub === 'update_helper') {
    $name = $_POST['helper_name'] ?? '';
    $value = $_POST['helper_value'] ?? '';
    $allowed = ['irrigation_enabled', 'irrigation_morning_hour', 'irrigation_evening_hour', 'irrigation_frost_threshold'];
    if (!in_array($name, $allowed)) { echo '{"ok":false}'; exit; }
    $db->query("UPDATE helpers SET value='" . $db->real_escape_string($value) . "' WHERE name='" . $db->real_escape_string($name) . "'");
    // Panel podlewania steruje Auto/OFF przez irrigation_enabled -> lustro do irrigation_mode + natychmiast zastosuj.
    if ($name === 'irrigation_enabled') {
        $db->query("UPDATE helpers SET value='" . (($value === '1') ? 'auto' : 'off') . "' WHERE name='irrigation_mode'");
        exec('php /opt/tymos/actions/irrigation.php > /dev/null 2>&1 &');
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo '{"ok":false,"error":"unknown sub_action"}';
