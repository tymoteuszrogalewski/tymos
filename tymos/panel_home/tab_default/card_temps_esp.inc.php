<?php
require_once __DIR__ . '/../../inc/chart.inc.php';

$DEV = 527; // device527 = Rekuperator ESP32
$DEV_POSTHEATER = 851; // device851 = SNZB-02P czujnik za nagrzewnica (PostHeater)

// Kolumny ESP32
$tcols = [
    'supply'  => ['col' => 'supply_air_temperature',  'hum' => 'supply_air_humidity',  'color' => '#ff8c00'],
    'extract' => ['col' => 'extract_air_temperature',  'hum' => 'extract_air_humidity',  'color' => '#c4a060'],
    'outdoor' => ['col' => 'outdoor_air_temperature',  'hum' => 'outdoor_air_humidity',  'color' => '#a0e4ff'],
    'exhaust' => ['col' => 'exhaust_air_temperature',  'hum' => 'exhaust_air_humidity',  'color' => '#8899aa'],
];

function _esp_enthalpy($t, $rh) {
    if ($t === null || $rh === null) return null;
    $ps = 610.78 * exp($t * 17.27 / ($t + 237.3));
    $pv = ($rh / 100.0) * $ps;
    $w  = 0.622 * $pv / (101325 - $pv);
    return 1.006 * $t + $w * (2501 + 1.86 * $t);
}

function _esp_calc_eff(&$raw, $n) {
    $eff_t = array_fill(0, $n, null);
    $eff_h = array_fill(0, $n, null);
    for ($i = 0; $i < $n; $i++) {
        $ts = $raw['supply'][$i]; $te = $raw['extract'][$i]; $to = $raw['outdoor'][$i];
        if ($ts !== null && $te !== null && $to !== null && abs($te - $to) > 1) {
            $v = round(($ts - $to) / ($te - $to) * 100, 1);
            $eff_t[$i] = ($v >= 0 && $v <= 100) ? $v : null;
        }
        $hs = _esp_enthalpy($ts, $raw['supply_h'][$i]);
        $he = _esp_enthalpy($te, $raw['extract_h'][$i]);
        $ho = _esp_enthalpy($to, $raw['outdoor_h'][$i]);
        if ($hs !== null && $he !== null && $ho !== null && abs($he - $ho) > 0.5) {
            $v = round(($hs - $ho) / ($he - $ho) * 100, 1);
            $eff_h[$i] = ($v >= 0 && $v <= 100) ? $v : null;
        }
    }
    return [$eff_t, $eff_h];
}

function _esp_build_series(&$raw, $tcols) {
    $series = [];
    foreach ($tcols as $k => $cfg) {
        $series[] = ['type' => 'line', 'data' => $raw[$k], 'color' => $cfg['color'], 'null_gap' => true];
    }
    // PostHeater (device851)
    $series[] = ['type' => 'line', 'data' => $raw['postheater'], 'color' => '#ff0000', 'null_gap' => true];
    // Wilgotnosci
    foreach ($tcols as $k => $cfg) {
        $series[] = ['type' => 'line', 'data' => $raw["{$k}_h"], 'color' => $cfg['color'], 'yaxis' => 1, 'null_gap' => true, 'dash' => 6, 'line_width' => 1];
    }
    $series[] = ['type' => 'line', 'data' => $raw['postheater_h'], 'color' => '#ff0000', 'yaxis' => 1, 'null_gap' => true, 'dash' => 6, 'line_width' => 1];
    // Sprawnosci
    $n = count($raw['supply']);
    [$eff_t, $eff_h] = _esp_calc_eff($raw, $n);
    $series[] = ['type' => 'line', 'data' => $eff_t, 'color' => '#ab47bc', 'yaxis' => 1, 'null_gap' => true, 'line_width' => 1];
    $series[] = ['type' => 'line', 'data' => $eff_h, 'color' => '#e91e90', 'yaxis' => 1, 'null_gap' => true, 'line_width' => 1];
    return $series;
}

$view  = $_GET['view'] ?? 'daily';
$today = date('Y-m-d');
$ann   = [];
$title = 'Rekuperator Temperatura/Wilgotność/Sprawność';
$tbl   = "device{$DEV}";
$allKeys = ['supply','extract','outdoor','exhaust','supply_h','extract_h','outdoor_h','exhaust_h','postheater','postheater_h'];

$db->select_db('tymos');

if ($view === 'daily') {
    $dateParam = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']))
                 ? $_GET['date'] : $today;
    if ($dateParam > $today) $dateParam = $today;
    if ($dateParam !== $today) {
        $diff = (int)round((strtotime($today) - strtotime($dateParam)) / 86400);
        if ($diff === 1)      $rel = 'Wczoraj';
        elseif ($diff === 2)  $rel = 'Przedwczoraj';
        else                  $rel = $diff . ' dni temu';
        $title .= ' · ' . $rel . ' ' . date('d.m', strtotime($dateParam));
    }

    $cats = array_map(fn($h) => (string)$h, range(0, 23));
    $raw = array_fill_keys($allKeys, array_fill(0, 24, null));

    $res = $db->query("SELECT HOUR(ts) AS h,
        ROUND(AVG(supply_air_temperature),1) AS supply_t, ROUND(AVG(supply_air_humidity),0) AS supply_h,
        ROUND(AVG(extract_air_temperature),1) AS extract_t, ROUND(AVG(extract_air_humidity),0) AS extract_h,
        ROUND(AVG(outdoor_air_temperature),1) AS outdoor_t, ROUND(AVG(outdoor_air_humidity),0) AS outdoor_h,
        ROUND(AVG(exhaust_air_temperature),1) AS exhaust_t, ROUND(AVG(exhaust_air_humidity),0) AS exhaust_h
        FROM `{$tbl}` WHERE DATE(ts) = '{$dateParam}' GROUP BY HOUR(ts) ORDER BY h");
    if ($res) while ($r = $res->fetch_assoc()) {
        $h = (int)$r['h'];
        foreach (['supply','extract','outdoor','exhaust'] as $k) {
            $raw[$k][$h] = $r["{$k}_t"] !== null ? (float)$r["{$k}_t"] : null;
            $raw["{$k}_h"][$h] = $r["{$k}_h"] !== null ? (float)$r["{$k}_h"] : null;
        }
    }
    // PostHeater (device851)
    $res = $db->query("SELECT HOUR(ts) AS h, ROUND(AVG(temperature),1) AS t, ROUND(AVG(humidity),0) AS hu FROM device{$DEV_POSTHEATER} WHERE DATE(ts) = '{$dateParam}' GROUP BY HOUR(ts)");
    if ($res) while ($r = $res->fetch_assoc()) {
        $h = (int)$r['h'];
        $raw['postheater'][$h] = $r['t'] !== null ? (float)$r['t'] : null;
        $raw['postheater_h'][$h] = $r['hu'] !== null ? (float)$r['hu'] : null;
    }

    $series = _esp_build_series($raw, $tcols);

} elseif ($view === 'monthly') {
    $title = 'Rekuperator Temp/Wilg/Spr · Miesiąc';
    $daysInMonth = (int)date('t');
    $totalBuckets = $daysInMonth * 24;
    $cats = [];
    for ($b = 0; $b < $totalBuckets; $b++) {
        $cats[] = ($b % 24 === 0) ? (string)(intdiv($b, 24) + 1) : '';
    }
    $raw = array_fill_keys($allKeys, array_fill(0, $totalBuckets, null));
    $ann = [];
    for ($d = 1; $d < $daysInMonth; $d++) $ann[] = ['x' => (string)($d + 1), 'color' => '#444455'];

    $res = $db->query("SELECT DAY(ts) AS d, HOUR(ts) AS h,
        ROUND(AVG(supply_air_temperature),1) AS supply_t, ROUND(AVG(supply_air_humidity),0) AS supply_h,
        ROUND(AVG(extract_air_temperature),1) AS extract_t, ROUND(AVG(extract_air_humidity),0) AS extract_h,
        ROUND(AVG(outdoor_air_temperature),1) AS outdoor_t, ROUND(AVG(outdoor_air_humidity),0) AS outdoor_h,
        ROUND(AVG(exhaust_air_temperature),1) AS exhaust_t, ROUND(AVG(exhaust_air_humidity),0) AS exhaust_h
        FROM `{$tbl}` WHERE DATE_FORMAT(ts,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m') GROUP BY DAY(ts),HOUR(ts) ORDER BY d,h");
    if ($res) while ($r = $res->fetch_assoc()) {
        $idx = ((int)$r['d'] - 1) * 24 + (int)$r['h'];
        if ($idx < $totalBuckets) {
            foreach (['supply','extract','outdoor','exhaust'] as $k) {
                $raw[$k][$idx] = $r["{$k}_t"] !== null ? (float)$r["{$k}_t"] : null;
                $raw["{$k}_h"][$idx] = $r["{$k}_h"] !== null ? (float)$r["{$k}_h"] : null;
            }
        }
    }
    foreach (['postheater' => $DEV_POSTHEATER] as $_k => $_did) {
        $res = $db->query("SELECT DAY(ts) AS d, HOUR(ts) AS h, ROUND(AVG(temperature),1) AS t, ROUND(AVG(humidity),0) AS hu FROM device{$_did} WHERE DATE_FORMAT(ts,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m') GROUP BY DAY(ts),HOUR(ts)");
        if ($res) while ($r = $res->fetch_assoc()) {
            $idx = ((int)$r['d'] - 1) * 24 + (int)$r['h'];
            if ($idx < $totalBuckets) {
                $raw[$_k][$idx] = $r['t'] !== null ? (float)$r['t'] : null;
                $raw["{$_k}_h"][$idx] = $r['hu'] !== null ? (float)$r['hu'] : null;
            }
        }
    }

    $series = _esp_build_series($raw, $tcols);

} else { // yearly
    $title = 'Rekuperator Temp/Wilg/Spr · Rok';
    $months_pl = ['01'=>'sty','02'=>'lut','03'=>'mar','04'=>'kwi','05'=>'maj','06'=>'cze',
                  '07'=>'lip','08'=>'sie','09'=>'wrz','10'=>'paź','11'=>'lis','12'=>'gru'];

    $dates = [];
    $res = $db->query("SELECT DATE(ts) AS d, DATE_FORMAT(ts,'%m') AS mo FROM `{$tbl}` WHERE ts >= NOW() - INTERVAL 365 DAY GROUP BY DATE(ts) ORDER BY d ASC");
    if ($res) while ($row = $res->fetch_assoc()) $dates[] = ['d'=>$row['d'],'mo'=>$row['mo']];

    $cats = []; $prevMo = null;
    $raw = array_fill_keys($allKeys, []);
    foreach ($dates as $dd) {
        $label = $months_pl[$dd['mo']] ?? $dd['mo'];
        $cats[] = $label;
        if ($dd['mo'] !== $prevMo && $prevMo !== null) $ann[] = ['x' => $label, 'color' => '#444455'];
        $prevMo = $dd['mo'];
        foreach (array_keys($raw) as $col) $raw[$col][] = null;
    }
    $dateIdx = array_flip(array_column($dates, 'd'));

    $res = $db->query("SELECT DATE(ts) AS d,
        ROUND(AVG(supply_air_temperature),1) AS supply_t, ROUND(AVG(supply_air_humidity),0) AS supply_h,
        ROUND(AVG(extract_air_temperature),1) AS extract_t, ROUND(AVG(extract_air_humidity),0) AS extract_h,
        ROUND(AVG(outdoor_air_temperature),1) AS outdoor_t, ROUND(AVG(outdoor_air_humidity),0) AS outdoor_h,
        ROUND(AVG(exhaust_air_temperature),1) AS exhaust_t, ROUND(AVG(exhaust_air_humidity),0) AS exhaust_h
        FROM `{$tbl}` WHERE ts >= NOW() - INTERVAL 365 DAY GROUP BY DATE(ts)");
    if ($res) while ($r = $res->fetch_assoc()) {
        $idx = $dateIdx[$r['d']] ?? null;
        if ($idx !== null) {
            foreach (['supply','extract','outdoor','exhaust'] as $k) {
                $raw[$k][$idx] = $r["{$k}_t"] !== null ? (float)$r["{$k}_t"] : null;
                $raw["{$k}_h"][$idx] = $r["{$k}_h"] !== null ? (float)$r["{$k}_h"] : null;
            }
        }
    }
    foreach (['postheater' => $DEV_POSTHEATER] as $_k => $_did) {
        $res = $db->query("SELECT DATE(ts) AS d, ROUND(AVG(temperature),1) AS t, ROUND(AVG(humidity),0) AS hu FROM device{$_did} WHERE ts >= NOW() - INTERVAL 365 DAY GROUP BY DATE(ts)");
        if ($res) while ($r = $res->fetch_assoc()) {
            $idx = $dateIdx[$r['d']] ?? null;
            if ($idx !== null) {
                $raw[$_k][$idx] = $r['t'] !== null ? (float)$r['t'] : null;
                $raw["{$_k}_h"][$idx] = $r['hu'] !== null ? (float)$r['hu'] : null;
            }
        }
    }

    $series = _esp_build_series($raw, $tcols);
}
?>
<div class="card-title"><?= $title ?></div>
<?= chart_render([
    'width'       => 800,
    'categories'  => $cats,
    'annotations' => $ann,
    'yaxis'       => [
        ['color' => '#9a9a9a', 'decimals' => 1, 'grids' => 10, 'tight' => true, 'oy_label_below' => ['text' => '°C', 'color' => '#9a9a9a']],
        ['color' => '#666666', 'decimals' => 1, 'side' => 'right', 'grids' => 10, 'tight' => true, 'oy_label_below' => ['text' => '%', 'color' => '#666666']],
    ],
    'series'      => $series,
    'legend'      => [
        ['color' => '#ff8c00', 'label' => 'Nawiew (supply)',           'type' => 'line'],
        ['color' => '#c4a060', 'label' => 'Wywiew (extract)',          'type' => 'line'],
        ['color' => '#ff0000', 'label' => 'PostHeater',               'type' => 'line'],
        ['color' => '#a0e4ff', 'label' => 'Czerpnia (outdoor)',        'type' => 'line'],
        ['color' => '#8899aa', 'label' => 'Wyrzutnia (exhaust)',       'type' => 'line'],
        ['color' => '#666666', 'label' => 'Wilgotność (---)',          'type' => 'line'],
        ['color' => '#ab47bc', 'label' => 'Sprawność temp.',           'type' => 'line'],
        ['color' => '#e91e90', 'label' => 'Sprawność entalp.',         'type' => 'line'],
    ],
]) ?>
