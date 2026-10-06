<?php

// Mapowanie device name → device ID
// Slugi grzalek zostaja historyczne (siedza w nazwach plikow kart i w swipe), ale fizycznie:
$_TYMOS_DEV_MAP = [
    'grzalka_bufor'  => 3,   // "Bufor Grzałka 1" — DOLNA (L3, ta z wywalajacym STB)
    'grzalka_bufor2' => 1,   // "Bufor Grzałka 2" — GÓRNA (L1)
    'zmywarka'       => 8,
    'pralka'         => 19,
    'suszarka'       => 17,
    'nagrzewnica'    => 16,
    'rekuperator'    => 18,
    'pompa_ogrod'    => 906,
];

// Sprawdz czy tabela ma kolumne energy_max (stat) lub energy (raw)
function _has_energy_col($db, string $tbl, string $col): bool {
    $res = $db->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$col}'");
    return ($res && $res->num_rows > 0);
}

function _stat_power_col($db, string $stbl): string {
    $res = $db->query("SHOW COLUMNS FROM `{$stbl}` LIKE 'power_avg'");
    if ($res && $res->num_rows > 0) return 'power_avg';
    $res = $db->query("SHOW COLUMNS FROM `{$stbl}` LIKE 'power_last'");
    if ($res && $res->num_rows > 0) return 'CAST(power_last AS DECIMAL(10,2))';
    return 'NULL';
}

// ============ DAILY ============
function device_chart_render($db, string $device, string $title, string $color = '#42a5f5', ?string $date = null): string
{
    global $_TYMOS_DEV_MAP;
    $today   = date('Y-m-d');
    $dateStr = $date ?? $today;
    $isToday = ($dateStr === $today);
    $curHour = (int)date('H');

    if ($date !== null && !$isToday) {
        $diff = (int)round((strtotime($today) - strtotime($date)) / 86400);
        if ($diff === 1)      $rel = 'Wczoraj';
        elseif ($diff === 2)  $rel = 'Przedwczoraj';
        else                  $rel = $diff . ' dni temu';
        $title .= ' · ' . $rel . ' ' . date('d.m', strtotime($date));
    }

    $kwh_data = array_fill(0, 24, null);
    $pln_data = array_fill(0, 24, null);
    $cats     = array_map('strval', range(0, 23));

    if ($db) {
        $db->select_db('tymos');
        $devId = $_TYMOS_DEV_MAP[$device] ?? null;
        if ($devId) {
            $tbl = "device{$devId}";
            $hasEnergy = _has_energy_col($db, $tbl, 'energy');
            $hasPower  = _has_energy_col($db, $tbl, 'power');
            // kWh per hour from power integration (SUM power*dt, dt capped 60s); energy-only devices
            // fall back to counter delta. No AVG(power)/1000 hybrid: it is not time-weighted (overcounts
            // partial-activity hours ~2x) and double-counts against the counter in flat hours.
            if ($hasPower) {
                $hourly = "
                    SELECT HOUR(ts) AS h, ROUND(SUM(power * dt) / 3600000, 4) AS kwh
                    FROM (
                        SELECT ts, power,
                               LEAST(GREATEST(TIMESTAMPDIFF(SECOND, ts, LEAD(ts) OVER (ORDER BY ts)), 0), 60) AS dt
                        FROM `{$tbl}` WHERE DATE(ts) = '{$dateStr}' AND power IS NOT NULL
                    ) z GROUP BY HOUR(ts)";
            } elseif ($hasEnergy) {
                $hourly = "
                    SELECT HOUR(ts) AS h, ROUND(MAX(energy) - MIN(energy), 4) AS kwh
                    FROM `{$tbl}` WHERE DATE(ts) = '{$dateStr}' AND energy IS NOT NULL
                    GROUP BY HOUR(ts)";
            } else {
                $hourly = "SELECT NULL AS h, NULL AS kwh FROM DUAL WHERE FALSE";
            }
            $res = $db->query("
                SELECT h.h, h.kwh,
                       ROUND(h.kwh * COALESCE(ep.full_price_pstryk, 0), 4) AS pln
                FROM ( {$hourly} ) h
                LEFT JOIN energa ep ON ep.ts = CONCAT('{$dateStr}', ' ', LPAD(h.h, 2, '0'), ':00:00')
                ORDER BY h.h ASC
            ");
        }
        if (isset($res) && $res) {
            while ($row = $res->fetch_assoc()) {
                $h = (int)$row['h'];
                $mark = $isToday && $h === $curHour;
                $kwh_data[$h] = $mark ? ['v' => (float)$row['kwh'], 'mark' => true] : (float)$row['kwh'];
                $pln_data[$h] = $row['pln'] !== null ? (float)$row['pln'] : null;
            }
        }
    }

    $maxKwh = null; $maxKwhIdx = null;
    foreach ($kwh_data as $i => $raw) {
        $v = is_array($raw) ? ($raw['v'] ?? null) : $raw;
        if ($v !== null && ($maxKwh === null || $v > $maxKwh)) { $maxKwh = $v; $maxKwhIdx = $i; }
    }
    $kwhDecimals = ($maxKwh !== null && $maxKwh > 0 && $maxKwh < 0.005) ? 3 : 2;
    $kwhLabels = array_fill(0, 24, '');
    if ($maxKwh !== null && $maxKwhIdx !== null && $maxKwh > 0) {
        $kwhLabels[$maxKwhIdx] = (int)round($maxKwh * 1000) . 'W';
    }

    require_once __DIR__ . '/chart.inc.php';
    $chart = chart_render([
        'width' => 800, 'categories' => $cats,
        'annotations' => [['x' => '0', 'color' => '#888888']],
        'yaxis' => [
            ['color' => $color, 'decimals' => $kwhDecimals, 'oy_label_below' => ['text' => 'kWh', 'color' => $color]],
            ['color' => '#ff4444', 'side' => 'right', 'oy_label_below' => ['text' => 'PLN', 'color' => '#ff4444']],
        ],
        'series' => [
            ['type' => 'bar', 'data' => $kwh_data, 'color' => $color,
             'mark' => ['color' => '#1565c0', 'label' => true, 'label_color' => '#ffffff', 'decimals' => 3],
             'labels' => $kwhLabels, 'label_color' => '#aaaaaa'],
            ['type' => 'line', 'data' => $pln_data, 'color' => '#ff4444', 'yaxis' => 1, 'null_gap' => true],
        ],
    ]);
    return '<div class="card-title">' . $title . '</div>' . $chart;
}

// ============ MONTHLY ============
function device_chart_monthly_render($db, string $device, string $title, string $color = '#42a5f5'): string
{
    global $_TYMOS_DEV_MAP;
    $daysInMonth = (int)date('t');
    $cats     = array_map('strval', range(1, $daysInMonth));
    $kwh_data = array_fill(0, $daysInMonth, null);
    $pln_data = array_fill(0, $daysInMonth, null);

    if ($db) {
        $db->select_db('tymos');
        $devId = $_TYMOS_DEV_MAP[$device] ?? null;
        if ($devId) {
            $stbl = "stat{$devId}";
            $hasEnergyS = _has_energy_col($db, $stbl, 'energy_max');
            $pcol = _stat_power_col($db, $stbl);
            // 5-min stat bucket: kWh = power_avg[W] * 300s / 3_600_000 = power_avg / 12000.
            // No AVG(power)/1000 hybrid (untimed average, double-counts) — same fix as the daily view.
            $kwhExpr = $pcol !== 'NULL' ? "SUM({$pcol}) / 12000" : ($hasEnergyS ? "MAX(energy_max) - MIN(energy_min)" : "0");
            $res = $db->query("
                SELECT DAY(h.hour_ts) AS day_num,
                       ROUND(SUM(h.kwh), 4) AS kwh,
                       ROUND(SUM(h.kwh * COALESCE(ep.full_price_pstryk, 0)), 2) AS pln
                FROM (
                    SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:00:00') AS hour_ts,
                           ROUND({$kwhExpr}, 4) AS kwh
                    FROM `{$stbl}`
                    WHERE DATE_FORMAT(ts, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
                      AND ts < DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00')
                    GROUP BY DATE_FORMAT(ts, '%Y-%m-%d %H:00:00')
                ) h
                LEFT JOIN energa ep ON ep.ts = h.hour_ts
                GROUP BY DAY(h.hour_ts)
                ORDER BY day_num ASC
            ");
        }
        if (isset($res) && $res) {
            while ($row = $res->fetch_assoc()) {
                $d = (int)$row['day_num'] - 1;
                $kwh_data[$d] = (float)$row['kwh'];
                $pln_data[$d] = $row['pln'] !== null ? (float)$row['pln'] : null;
            }
        }
    }

    require_once __DIR__ . '/chart.inc.php';
    $chart = chart_render([
        'width' => 800, 'categories' => $cats,
        'yaxis' => [
            ['color' => $color, 'oy_label_below' => ['text' => 'kWh', 'color' => $color]],
            ['color' => '#ff4444', 'side' => 'right', 'oy_label_below' => ['text' => 'PLN', 'color' => '#ff4444']],
        ],
        'series' => [
            ['type' => 'bar', 'data' => $kwh_data, 'color' => $color],
            ['type' => 'line', 'data' => $pln_data, 'color' => '#ff4444', 'yaxis' => 1, 'null_gap' => true],
        ],
    ]);
    return '<div class="card-title">' . htmlspecialchars($title) . '</div>' . $chart;
}

// ============ YEARLY ============
function device_chart_yearly_render($db, string $device, string $title, string $color = '#42a5f5'): string
{
    global $_TYMOS_DEV_MAP;
    static $months_pl = ['01'=>'sty','02'=>'lut','03'=>'mar','04'=>'kwi','05'=>'maj','06'=>'cze',
                         '07'=>'lip','08'=>'sie','09'=>'wrz','10'=>'paź','11'=>'lis','12'=>'gru'];
    $kwh_data = []; $pln_data = []; $cats = [];

    if ($db) {
        $db->select_db('tymos');
        $devId = $_TYMOS_DEV_MAP[$device] ?? null;
        if ($devId) {
            $stbl = "stat{$devId}";
            $hasEnergyS = _has_energy_col($db, $stbl, 'energy_max');
            $pcol = _stat_power_col($db, $stbl);
            // 5-min stat bucket: kWh = power_avg / 12000 (W * 300s / 3.6e6). No AVG(power)/1000 hybrid.
            $kwhExpr = $pcol !== 'NULL' ? "SUM({$pcol}) / 12000" : ($hasEnergyS ? "MAX(energy_max) - MIN(energy_min)" : "0");
            $res = $db->query("
                SELECT DATE_FORMAT(h.hour_ts, '%Y-%m') AS m,
                       ROUND(SUM(h.kwh), 2) AS kwh,
                       ROUND(SUM(h.kwh * COALESCE(ep.full_price_pstryk, 0)), 2) AS pln
                FROM (
                    SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:00:00') AS hour_ts,
                           ROUND({$kwhExpr}, 4) AS kwh
                    FROM `{$stbl}`
                    WHERE ts >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01 00:00:00')
                    GROUP BY DATE_FORMAT(ts, '%Y-%m-%d %H:00:00')
                ) h
                LEFT JOIN energa ep ON ep.ts = h.hour_ts
                GROUP BY DATE_FORMAT(h.hour_ts, '%Y-%m')
                ORDER BY m ASC
            ");
        }
        if (isset($res) && $res) {
            while ($row = $res->fetch_assoc()) {
                $mo = substr($row['m'], 5, 2);
                $cats[] = $months_pl[$mo] ?? $mo;
                $kwh_data[] = (float)$row['kwh'];
                $pln_data[] = $row['pln'] !== null ? (float)$row['pln'] : null;
            }
        }
    }

    require_once __DIR__ . '/chart.inc.php';
    $chart = chart_render([
        'width' => 800, 'categories' => $cats,
        'yaxis' => [
            ['color' => $color, 'decimals' => 0, 'oy_label_below' => ['text' => 'kWh', 'color' => $color]],
            ['color' => '#ff4444', 'decimals' => 0, 'side' => 'right', 'oy_label_below' => ['text' => 'PLN', 'color' => '#ff4444']],
        ],
        'series' => [
            ['type' => 'bar', 'data' => $kwh_data, 'color' => $color],
            ['type' => 'line', 'data' => $pln_data, 'color' => '#ff4444', 'yaxis' => 1, 'null_gap' => true],
        ],
    ]);
    return '<div class="card-title">' . htmlspecialchars($title) . '</div>' . $chart;
}

function device_chart_dispatch($db, string $device, string $title, string $color = '#42a5f5'): string
{
    $title = preg_replace('/ kWh\/[a-zA-Z]+\s*\|\s*PLN/u', '', $title);
    $view = $_GET['view'] ?? 'daily';
    if ($view === 'monthly') return device_chart_monthly_render($db, $device, $title, $color);
    if ($view === 'yearly')  return device_chart_yearly_render($db, $device, $title, $color);
    $today = date('Y-m-d');
    $date  = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) ? $_GET['date'] : $today;
    if ($date > $today) $date = $today;
    return device_chart_render($db, $device, $title, $color, $date);
}
