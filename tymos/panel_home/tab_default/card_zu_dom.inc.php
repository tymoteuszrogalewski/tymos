<?php
require_once __DIR__ . '/../../inc/chart.inc.php';

$view  = $_GET['view'] ?? 'daily';
$today = date('Y-m-d');
$title = '🏠 Dom';
$ann   = [];

$cats    = [];
$kwh_d   = [];
$pln_d   = [];

if ($view === 'daily') {
    $dateStr = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) ? $_GET['date'] : $today;
    if ($dateStr > $today) $dateStr = $today;
    $isToday = ($dateStr === $today);
    $curHour = (int)date('H');

    if (!$isToday) {
        $diff = (int)round((strtotime($today) - strtotime($dateStr)) / 86400);
        if ($diff === 1)      $rel = 'Wczoraj';
        elseif ($diff === 2)  $rel = 'Przedwczoraj';
        else                  $rel = $diff . ' dni temu';
        $title .= ' · ' . $rel . ' ' . date('d.m', strtotime($dateStr));
    }

    $kwh_d = array_fill(0, 24, null);
    $pln_d = array_fill(0, 24, null);
    $cats  = array_map('strval', range(0, 23));
    $ann   = [['x' => '0', 'color' => '#888888']];

    if ($db) {
        $db->select_db('tymos');
        $res = $db->query("
            SELECT HOUR(ts) AS h,
                   ROUND(kwh_pstryk, 6) AS kwh,
                   ROUND(cost_full_pstryk, 4) AS pln
            FROM energa
            WHERE DATE(ts) = '$dateStr'
              AND kwh_pstryk IS NOT NULL
            ORDER BY ts ASC
        ");
        while ($r = $res->fetch_assoc()) {
            $h = (int)$r['h'];
            $isCur = $isToday && $h === $curHour;
            $kwh_d[$h] = (float)$r['kwh'];
            $pln_d[$h] = ($isCur && (float)$r['pln'] == 0) ? null : (float)$r['pln'];
        }
        $db->select_db('tymos');
    }

} elseif ($view === 'monthly') {
    $title = '🏠 Dom';
    $daysInMonth = (int)date('t');
    $cats  = array_map('strval', range(1, $daysInMonth));
    $kwh_d = array_fill(0, $daysInMonth, null);
    $pln_d = array_fill(0, $daysInMonth, null);

    if ($db) {
        $db->select_db('tymos');
        $res = $db->query("
            SELECT DAY(ts) AS d,
                   ROUND(SUM(kwh_pstryk), 3) AS kwh,
                   ROUND(SUM(cost_full_pstryk), 2) AS pln
            FROM energa
            WHERE DATE_FORMAT(ts, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
              AND ts < DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00')
              AND kwh_pstryk IS NOT NULL
            GROUP BY DATE(ts)
            ORDER BY DATE(ts) ASC
        ");
        while ($r = $res->fetch_assoc()) {
            $d = (int)$r['d'] - 1;
            $kwh_d[$d] = (float)$r['kwh'];
            $pln_d[$d] = (float)$r['pln'];
        }
        $db->select_db('tymos');
    }

} else { // yearly
    $months_pl = ['01'=>'sty','02'=>'lut','03'=>'mar','04'=>'kwi','05'=>'maj','06'=>'cze',
                  '07'=>'lip','08'=>'sie','09'=>'wrz','10'=>'paź','11'=>'lis','12'=>'gru'];
    $title = '🏠 Dom';

    if ($db) {
        $db->select_db('tymos');
        $res = $db->query("
            SELECT DATE_FORMAT(ts, '%Y-%m') AS m,
                   ROUND(SUM(kwh_pstryk), 2) AS kwh,
                   ROUND(SUM(cost_full_pstryk), 2) AS pln
            FROM energa
            WHERE ts >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01 00:00:00')
              AND kwh_pstryk IS NOT NULL
            GROUP BY DATE_FORMAT(ts, '%Y-%m')
            ORDER BY m ASC
        ");
        while ($r = $res->fetch_assoc()) {
            $mo      = substr($r['m'], 5, 2);
            $cats[]  = $months_pl[$mo] ?? $mo;
            $kwh_d[] = (float)$r['kwh'];
            $pln_d[] = (float)$r['pln'];
        }
        $db->select_db('tymos');
    }
}

$yDec = ($view === 'yearly') ? 0 : 2;
$chart = chart_render([
    'width'       => 800,
    'categories'  => $cats,
    'annotations' => $ann,
    'yaxis' => [
        ['color' => '#42a5f5', 'decimals' => $yDec, 'oy_label_below' => ['text' => 'kWh', 'color' => '#42a5f5']],
        ['color' => '#ff4444', 'decimals' => $yDec, 'side' => 'right', 'oy_label_below' => ['text' => 'PLN', 'color' => '#ff4444']],
    ],
    'legend' => [
        ['color' => '#42a5f5', 'label' => 'kWh',  'type' => 'area'],
        ['color' => '#ff4444', 'label' => 'PLN',   'type' => 'line'],
    ],
    'series' => [
        ['type' => 'bar',  'data' => $kwh_d, 'color' => '#42a5f5'],
        ['type' => 'line', 'data' => $pln_d, 'color' => '#ff4444', 'yaxis' => 1, 'null_gap' => true],
    ],
]);
?>
<div class="card-title"><?= $title ?></div>
<?= $chart ?>
