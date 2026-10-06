<?php
// TymOS admin → Urządzenia → wykres pola (lazy loaded przez field-chart-toggle).
// Query: ?id=X&field=Y&hours=24 — SVG 24h (5min buckets) + 30 dni (1h) z stat<ID> jesli jest.

require_once __DIR__ . '/../../config.inc.php';
require_once __DIR__ . '/../../inc/chart.inc.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
$db->set_charset('utf8mb4');

$id = (int)($_GET['id'] ?? 0);
$field = $_GET['field'] ?? '';
$hours = (int)($_GET['hours'] ?? 24);

$tbl = "device{$id}";
$check = $db->query("SHOW TABLES LIKE '{$tbl}'");
if (!$check || $check->num_rows === 0) { echo '<div style="color:var(--text-dim)">Brak tabeli</div>'; exit; }

$colCheck = $db->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$db->real_escape_string($field)}'");
if (!$colCheck || $colCheck->num_rows === 0) { echo '<div style="color:var(--text-dim)">Brak kolumny</div>'; exit; }

$colInfo = $colCheck->fetch_assoc();
$colType = strtoupper($colInfo['Type'] ?? '');
$isText = (strpos($colType, 'VARCHAR') !== false || strpos($colType, 'TEXT') !== false);
$fe = $db->real_escape_string($field);

// 24h (5min buckets) — bucket truncated to 5-min slot so PHP grid keys match
$bucket_expr = "CONCAT(DATE_FORMAT(ts, '%Y-%m-%d %H:'), LPAD(FLOOR(MINUTE(ts)/5)*5, 2, '0'))";
if ($isText) {
    $sql = "SELECT {$bucket_expr} AS bucket, MAX(CASE WHEN `{$fe}` = 'ON' THEN 1 ELSE 0 END) AS val
            FROM `{$tbl}` WHERE ts >= NOW() - INTERVAL {$hours} HOUR AND `{$fe}` IS NOT NULL
            GROUP BY bucket ORDER BY bucket";
} else {
    $sql = "SELECT {$bucket_expr} AS bucket, ROUND(AVG(`{$fe}`), 2) AS val
            FROM `{$tbl}` WHERE ts >= NOW() - INTERVAL {$hours} HOUR AND `{$fe}` IS NOT NULL
            GROUP BY bucket ORDER BY bucket";
}
$rows = $db->query($sql);
$data_map = [];
if ($rows) {
    while ($r = $rows->fetch_assoc()) {
        $data_map[$r['bucket']] = $r['val'] !== null ? (float)$r['val'] : null;
    }
}
if (empty($data_map)) {
    echo '<div style="color:var(--text-dim);padding:10px">Brak danych za ostatnie ' . $hours . 'h</div>';
    exit;
}
$categories = [];
$values = [];
$start = new DateTime();
$start->modify("-{$hours} hours");
$sm = (int)$start->format('i');
$start->setTime((int)$start->format('H'), $sm - ($sm % 5), 0);
$end = new DateTime();
$cursor = clone $start;
while ($cursor <= $end) {
    $full_key = $cursor->format('Y-m-d H:i');
    $categories[] = substr($full_key, 11, 5);
    $values[] = $data_map[$full_key] ?? null;
    $cursor->modify('+5 minutes');
}
$cats = [];
$lastHour = '';
foreach ($categories as $c) {
    $hh = substr($c, 0, 2);
    $mm = substr($c, 3, 2);
    if ($mm === '00' && $hh !== $lastHour) {
        $cats[] = $hh;
        $lastHour = $hh;
    } else {
        $cats[] = '';
    }
}

$img = chart_render([
    'width' => 760, 'height' => 180, 'bg' => '#252525', 'grid_color' => '#2a2a3a', 'label_color' => '#9a9a9a',
    'font_size' => 6, 'margin' => ['top' => 8, 'right' => 10, 'bottom' => 24, 'left' => 50],
    'categories' => $cats, 'yaxis' => [['color' => '#9a9a9a', 'decimals' => 1, 'tight' => true]],
    'series' => [['data' => $values, 'type' => 'line', 'color' => '#03a9f4', 'line_width' => 1, 'null_gap' => false]],
    'legend' => [['type' => 'line', 'color' => '#03a9f4', 'label' => 'avg']],
    'annotations' => [['x' => '00', 'color' => '#555555', 'dash' => 3, 'gap' => 3]],
]);
echo '<div style="padding:4px 0">';
echo '<div style="font-size:11px;color:var(--text-dim);margin-bottom:4px">' . htmlspecialchars($field) . ' — ostatnie ' . $hours . 'h (5min)</div>';
echo $img;
echo '</div>';

// 30 dni z stat<ID>
$stat_tbl = "stat{$id}";
$stat_chk = $db->query("SHOW TABLES LIKE '{$stat_tbl}'");
if (!$stat_chk || $stat_chk->num_rows === 0) exit;

$stat_col = $isText ? "{$fe}_last" : "{$fe}_avg";
$stat_col_chk = $db->query("SHOW COLUMNS FROM `{$stat_tbl}` LIKE '{$db->real_escape_string($stat_col)}'");
if (!$stat_col_chk || $stat_col_chk->num_rows === 0) exit;

if ($isText) {
    $stat_sql = "SELECT DATE_FORMAT(ts, '%Y-%m-%d %H') AS bucket,
            MAX(CASE WHEN `{$db->real_escape_string($stat_col)}` = 'ON' THEN 1 ELSE 0 END) AS val_avg,
            MAX(CASE WHEN `{$db->real_escape_string($stat_col)}` = 'ON' THEN 1 ELSE 0 END) AS val_min,
            MAX(CASE WHEN `{$db->real_escape_string($stat_col)}` = 'ON' THEN 1 ELSE 0 END) AS val_max
        FROM `{$stat_tbl}` WHERE ts >= NOW() - INTERVAL 30 DAY AND `{$db->real_escape_string($stat_col)}` IS NOT NULL
        GROUP BY DATE(ts), HOUR(ts) ORDER BY bucket";
} else {
    $col_min = "{$fe}_min";
    $col_max = "{$fe}_max";
    $stat_sql = "SELECT DATE_FORMAT(ts, '%Y-%m-%d %H') AS bucket,
            ROUND(AVG(`{$db->real_escape_string($stat_col)}`), 2) AS val_avg,
            ROUND(MIN(`{$db->real_escape_string($col_min)}`), 2) AS val_min,
            ROUND(MAX(`{$db->real_escape_string($col_max)}`), 2) AS val_max
        FROM `{$stat_tbl}` WHERE ts >= NOW() - INTERVAL 30 DAY AND `{$db->real_escape_string($stat_col)}` IS NOT NULL
        GROUP BY DATE(ts), HOUR(ts) ORDER BY bucket";
}
$stat_rows = $db->query($stat_sql);
$data_map = [];
if ($stat_rows) {
    while ($sr = $stat_rows->fetch_assoc()) {
        $data_map[$sr['bucket']] = [
            'avg' => $sr['val_avg'] !== null ? (float)$sr['val_avg'] : null,
            'min' => $sr['val_min'] !== null ? (float)$sr['val_min'] : null,
            'max' => $sr['val_max'] !== null ? (float)$sr['val_max'] : null,
        ];
    }
}
if (empty($data_map)) exit;

$stat_cats = []; $stat_avg = []; $stat_min = []; $stat_max = [];
$start = new DateTime(); $start->modify('-30 days'); $start->setTime(0, 0, 0);
$end = new DateTime();
$cursor = clone $start;
while ($cursor <= $end) {
    $key = $cursor->format('Y-m-d H');
    $stat_cats[] = $key;
    if (isset($data_map[$key])) {
        $stat_avg[] = $data_map[$key]['avg'];
        $stat_min[] = $data_map[$key]['min'];
        $stat_max[] = $data_map[$key]['max'];
    } else {
        $stat_avg[] = null; $stat_min[] = null; $stat_max[] = null;
    }
    $cursor->modify('+1 hour');
}
$m_cats = []; $lastDay = '';
foreach ($stat_cats as $sc) {
    $day = substr($sc, 0, 10);
    if ($day !== $lastDay) { $m_cats[] = (int)substr($day, 8, 2); $lastDay = $day; }
    else { $m_cats[] = ''; }
}

$img2 = chart_render([
    'width' => 760, 'height' => 180, 'bg' => '#252525', 'grid_color' => '#2a2a3a', 'label_color' => '#9a9a9a',
    'font_size' => 6, 'margin' => ['top' => 8, 'right' => 10, 'bottom' => 24, 'left' => 50],
    'categories' => $m_cats, 'yaxis' => [['color' => '#9a9a9a', 'decimals' => 1, 'tight' => true]],
    'series' => [
        ['data' => $stat_max, 'type' => 'line', 'color' => '#c62828', 'line_width' => 1, 'null_gap' => true],
        ['data' => $stat_avg, 'type' => 'line', 'color' => '#4caf50', 'line_width' => 1, 'null_gap' => true],
        ['data' => $stat_min, 'type' => 'line', 'color' => '#1565c0', 'line_width' => 1, 'null_gap' => true],
    ],
    'legend' => [
        ['type' => 'line', 'color' => '#c62828', 'label' => 'max'],
        ['type' => 'line', 'color' => '#4caf50', 'label' => 'avg'],
        ['type' => 'line', 'color' => '#1565c0', 'label' => 'min'],
    ],
    'annotations' => [['x' => '1', 'color' => '#555555', 'dash' => 3, 'gap' => 3]],
]);
echo '<div style="padding:4px 0;margin-top:8px">';
echo '<div style="font-size:11px;color:var(--text-dim);margin-bottom:4px">' . htmlspecialchars($field) . ' — ostatnie 30 dni (1h)</div>';
echo $img2;
echo '</div>';
