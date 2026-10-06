<?php

/**
 * Auto-discovery czujników temperatury z bazy.
 * Szuka urządzeń z polem "temperature" w enabled_fields.
 */
function _temp_dev(string $device): array {
    global $_temp_dev_cache;
    if ($_temp_dev_cache === null) {
        $_temp_dev_cache = [];
        $db2 = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
        if (!$db2->connect_error) {
            $rows = $db2->query("SELECT id, device, fields, enabled_fields FROM devices WHERE deleted=0 AND enabled_fields IS NOT NULL");
            if ($rows) while ($r = $rows->fetch_assoc()) {
                $ef = json_decode($r['enabled_fields'] ?? '[]', true) ?: [];
                if (!in_array('temperature', $ef)) continue;
                $fields = json_decode($r['fields'] ?? '{}', true) ?: [];
                $hasHum = in_array('humidity', $ef) && isset($fields['humidity']);
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $r['device']));
                $slug = trim(preg_replace('/_+/', '_', $slug), '_');
                // Usun prefiks "?" jesli jest
                $slug = ltrim($slug, '_');
                $_temp_dev_cache[$slug] = ['id' => (int)$r['id'], 'hum' => $hasHum, 'name' => $r['device']];
            }
            $db2->close();
        }
    }
    return $_temp_dev_cache[$device] ?? ['id' => 0, 'hum' => true, 'name' => $device];
}

/**
 * Zwraca listę wszystkich czujników temp (slug => info).
 */
function temp_dev_list(): array {
    _temp_dev('_init'); // force cache load
    global $_temp_dev_cache;
    return $_temp_dev_cache ?? [];
}

function temp_chart_render($db, string $device, string $title, ?string $date = null): string
{
    $today   = date('Y-m-d');
    $dateStr = $date ?? $today;
    $isToday = ($dateStr === $today);

    if ($date !== null && !$isToday) {
        $diff = (int)round((strtotime($today) - strtotime($date)) / 86400);
        if ($diff === 1)      $rel = 'Wczoraj';
        elseif ($diff === 2)  $rel = 'Przedwczoraj';
        else                  $rel = $diff . ' dni temu';
        $title .= ' · ' . $rel . ' ' . date('d.m', strtotime($date));
    }

    $temp_data = array_fill(0, 24, null);
    $hum_data  = array_fill(0, 24, null);
    $cats      = array_map('strval', range(0, 23));
    $dev       = _temp_dev($device);

    if ($db && $dev['id']) {
        $db->select_db('tymos');
        $tbl = "device{$dev['id']}";
        $hSel = $dev['hum'] ? ", humidity" : ", NULL AS humidity";
        $res = $db->query("SELECT HOUR(ts) AS h, temperature AS temp{$hSel} FROM `{$tbl}` WHERE DATE(ts) = '{$dateStr}' ORDER BY ts ASC");
        if ($res) while ($row = $res->fetch_assoc()) {
            $h = (int)$row['h'];
            $temp_data[$h] = $row['temp'] !== null ? (float)$row['temp'] : null;
            $hum_data[$h]  = $row['humidity'] !== null ? (float)$row['humidity'] : null;
        }
    }

    require_once __DIR__ . '/chart.inc.php';
    return '<div class="card-title">' . $title . '</div>' . chart_render([
        'width' => 800, 'categories' => $cats,
        'yaxis' => [
            ['color' => '#ff4444', 'decimals' => 1, 'tight' => true, 'grids' => 10, 'oy_label_above' => ['text' => '°C', 'color' => '#ff4444']],
            ['color' => '#4488ff', 'decimals' => 0, 'side' => 'right', 'min' => 0, 'max' => 100, 'grids' => 10, 'oy_label_above' => ['text' => '%', 'color' => '#4488ff']],
        ],
        'legend' => [['color' => '#ff4444', 'label' => 'Temperatura', 'type' => 'line'], ['color' => '#4488ff', 'label' => 'Wilgotność', 'type' => 'line']],
        'series' => [
            ['type' => 'line', 'data' => $temp_data, 'color' => '#ff4444', 'null_gap' => true],
            ['type' => 'line', 'data' => $hum_data,  'color' => '#4488ff', 'yaxis' => 1, 'null_gap' => true],
        ],
    ]);
}

function temp_chart_monthly_render($db, string $device, string $title): string
{
    $daysInMonth = (int)date('t');
    $cats      = array_map('strval', range(1, $daysInMonth));
    $temp_data = array_fill(0, $daysInMonth, null);
    $hum_data  = array_fill(0, $daysInMonth, null);
    $dev       = _temp_dev($device);

    if ($db && $dev['id']) {
        $db->select_db('tymos');
        $tbl = "device{$dev['id']}";
        $hSel = $dev['hum'] ? ", ROUND(AVG(humidity), 1) AS humidity" : ", NULL AS humidity";
        $res = $db->query("SELECT DAY(ts) AS d, ROUND(AVG(temperature), 1) AS temp{$hSel} FROM `{$tbl}` WHERE DATE_FORMAT(ts, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') AND ts < DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') GROUP BY DATE(ts) ORDER BY DATE(ts) ASC");
        if ($res) while ($row = $res->fetch_assoc()) {
            $d = (int)$row['d'] - 1;
            $temp_data[$d] = $row['temp'] !== null ? (float)$row['temp'] : null;
            $hum_data[$d]  = $row['humidity'] !== null ? (float)$row['humidity'] : null;
        }
    }

    require_once __DIR__ . '/chart.inc.php';
    return '<div class="card-title">' . $title . '</div>' . chart_render([
        'width' => 800, 'categories' => $cats,
        'yaxis' => [
            ['color' => '#ff4444', 'decimals' => 1, 'tight' => true, 'grids' => 10, 'oy_label_above' => ['text' => '°C', 'color' => '#ff4444']],
            ['color' => '#4488ff', 'decimals' => 0, 'side' => 'right', 'min' => 0, 'max' => 100, 'grids' => 10, 'oy_label_above' => ['text' => '%', 'color' => '#4488ff']],
        ],
        'legend' => [['color' => '#ff4444', 'label' => 'Temperatura', 'type' => 'line'], ['color' => '#4488ff', 'label' => 'Wilgotność', 'type' => 'line']],
        'series' => [
            ['type' => 'line', 'data' => $temp_data, 'color' => '#ff4444', 'null_gap' => true],
            ['type' => 'line', 'data' => $hum_data,  'color' => '#4488ff', 'yaxis' => 1, 'null_gap' => true],
        ],
    ]);
}

function temp_chart_yearly_render($db, string $device, string $title): string
{
    static $months_pl = ['01'=>'sty','02'=>'lut','03'=>'mar','04'=>'kwi','05'=>'maj','06'=>'cze',
                         '07'=>'lip','08'=>'sie','09'=>'wrz','10'=>'paź','11'=>'lis','12'=>'gru'];
    $temp_data = []; $hum_data = []; $cats = [];
    $dev = _temp_dev($device);

    if ($db && $dev['id']) {
        $db->select_db('tymos');
        $tbl = "device{$dev['id']}";
        $hSel = $dev['hum'] ? ", ROUND(AVG(humidity), 1) AS humidity" : ", NULL AS humidity";
        $res = $db->query("SELECT DATE_FORMAT(ts, '%Y-%m') AS m, ROUND(AVG(temperature), 1) AS temp{$hSel} FROM `{$tbl}` WHERE ts >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01 00:00:00') GROUP BY DATE_FORMAT(ts, '%Y-%m') ORDER BY m ASC");
        if ($res) while ($row = $res->fetch_assoc()) {
            $mo = substr($row['m'], 5, 2);
            $cats[] = $months_pl[$mo] ?? $mo;
            $temp_data[] = $row['temp'] !== null ? (float)$row['temp'] : null;
            $hum_data[]  = $row['humidity'] !== null ? (float)$row['humidity'] : null;
        }
    }

    require_once __DIR__ . '/chart.inc.php';
    return '<div class="card-title">' . $title . '</div>' . chart_render([
        'width' => 800, 'categories' => $cats,
        'yaxis' => [
            ['color' => '#ff4444', 'decimals' => 0, 'tight' => true, 'grids' => 10, 'oy_label_above' => ['text' => '°C', 'color' => '#ff4444']],
            ['color' => '#4488ff', 'decimals' => 0, 'side' => 'right', 'min' => 0, 'max' => 100, 'grids' => 10, 'oy_label_above' => ['text' => '%', 'color' => '#4488ff']],
        ],
        'legend' => [['color' => '#ff4444', 'label' => 'Temperatura', 'type' => 'line'], ['color' => '#4488ff', 'label' => 'Wilgotność', 'type' => 'line']],
        'series' => [
            ['type' => 'line', 'data' => $temp_data, 'color' => '#ff4444', 'null_gap' => true],
            ['type' => 'line', 'data' => $hum_data,  'color' => '#4488ff', 'yaxis' => 1, 'null_gap' => true],
        ],
    ]);
}

function temp_chart_dispatch($db, string $device, string $title): string
{
    $view = $_GET['view'] ?? 'daily';
    if ($view === 'monthly') return temp_chart_monthly_render($db, $device, $title);
    if ($view === 'yearly')  return temp_chart_yearly_render($db, $device, $title);
    $today = date('Y-m-d');
    $date  = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) ? $_GET['date'] : $today;
    if ($date > $today) $date = $today;
    return temp_chart_render($db, $device, $title, $date);
}
