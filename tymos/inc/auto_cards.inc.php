<?php
/**
 * Dynamiczne karty generowane z bazy (bez osobnych plików PHP per czujnik).
 * auto_temp_<slug> — wykres temperatury
 * auto_battery — tabela baterii
 */

$card = $_GET['card'] ?? '';

// --- auto_temp_* ---
if (strpos($card, 'auto_temp_') === 0) {
    $slug = substr($card, 10); // po "auto_temp_"
    require_once __DIR__ . '/temp_chart.inc.php';
    $devInfo = _temp_dev($slug);
    $title = $devInfo['name'] ?? $slug;
    echo temp_chart_dispatch($db, $slug, $title);
    exit;
}

// --- auto_battery ---
if ($card === 'auto_battery') {
    require_once __DIR__ . '/../config.inc.php';
    if (!isset($db) || !$db) {
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
        $db->set_charset('utf8mb4');
    }
    $db->select_db('tymos');

    $rows = $db->query("SELECT id, device, ieee, fields, enabled_fields FROM devices WHERE deleted=0 AND enabled_fields IS NOT NULL ORDER BY device");
    $items = [];
    while ($r = $rows->fetch_assoc()) {
        $ef = json_decode($r['enabled_fields'] ?? '[]', true) ?: [];
        if (!in_array('battery', $ef)) continue;
        $fields = json_decode($r['fields'] ?? '{}', true) ?: [];

        // Pobierz ostatnią wartość battery
        $tbl = "device{$r['id']}";
        $chk = $db->query("SHOW TABLES LIKE '{$tbl}'");
        $bat = null;
        if ($chk && $chk->num_rows > 0) {
            $lr = $db->query("SELECT battery FROM `{$tbl}` WHERE battery IS NOT NULL ORDER BY ts DESC LIMIT 1");
            if ($lr && $row = $lr->fetch_assoc()) $bat = (int)$row['battery'];
        }

        // Integracja
        $ieee = $r['ieee'] ?? '';
        if (strpos($ieee, '0x') === 0) $int = 'Zigbee';
        elseif (strpos($ieee, 'cam_') === 0) $int = 'ONVIF';
        else $int = 'Inne';

        // Typ ikony
        $hasTemp = isset($fields['temperature']);
        $hasOccupancy = isset($fields['occupancy']);
        $hasWaterLeak = isset($fields['water_leak']);

        if ($hasWaterLeak) $icon = '💧';
        elseif ($hasOccupancy) $icon = '👁';
        elseif ($hasTemp) $icon = '🌡';
        else $icon = '🔋';

        $items[] = ['name' => $r['device'], 'int' => $int, 'icon' => $icon, 'bat' => $bat];
    }

    // Kolorowanie baterii
    function _batColor($bat) {
        if ($bat === null) return 'var(--text-dim)';
        if ($bat <= 10) return 'var(--red)';
        if ($bat <= 30) return '#f9a825';
        return 'var(--green)';
    }

    echo '<div class="card-title">Baterie</div>';
    echo '<table style="width:100%;font-size:13px">';
    echo '<tr><th style="width:30px"></th><th>Czujnik</th><th style="text-align:right">Bateria</th></tr>';
    foreach ($items as $it) {
        $batText = $it['bat'] !== null ? $it['bat'] . '%' : '---';
        $batCol = _batColor($it['bat']);
        echo '<tr>';
        echo '<td style="text-align:center">' . $it['icon'] . '</td>';
        echo '<td>' . htmlspecialchars($it['name']) . '</td>';
        echo '<td style="text-align:right;color:' . $batCol . ';font-weight:600">' . $batText . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}
