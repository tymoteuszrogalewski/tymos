<?php
require_once __DIR__ . '/../../inc/chart.inc.php';

$view      = $_GET['view'] ?? 'daily';
$today     = date('Y-m-d');
$months_pl = ['01'=>'sty','02'=>'lut','03'=>'mar','04'=>'kwi','05'=>'maj','06'=>'cze',
               '07'=>'lip','08'=>'sie','09'=>'wrz','10'=>'paź','11'=>'lis','12'=>'gru'];

$cats    = [];
$pstryk  = [];
$energa  = [];
$title   = 'Odczyty Pstryk vs Energa-Operator';

if ($view === 'daily') {
    $dateParam = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']))
                 ? $_GET['date'] : $today;
    if ($dateParam > $today) $dateParam = $today;
    $isToday = ($dateParam === $today);
    $curHour = $isToday ? (int)date('G') : -1;

    $cats   = array_map(fn($h) => (string)$h, range(0, 23));
    $pstryk = array_fill(0, 24, null);
    $energa = array_fill(0, 24, null);

    if (!$isToday) {
        $diff = (int)round((strtotime($today) - strtotime($dateParam)) / 86400);
        if ($diff === 1)      $rel = 'Wczoraj';
        elseif ($diff === 2)  $rel = 'Przedwczoraj';
        else                  $rel = $diff . ' dni temu';
        $title .= ' · ' . htmlspecialchars($rel) . ' ' . date('d.m', strtotime($dateParam));
    }

    if ($db) {
        $db->select_db('tymos');
        $res = $db->query("
            SELECT HOUR(ts) AS h,
                   ROUND(kwh_pstryk,          6) AS kwh_p,
                   ROUND(kwh_energa_operator, 6) AS kwh_e
            FROM energa
            WHERE DATE(ts) = '$dateParam'
            ORDER BY ts ASC
        ");
        while ($r = $res->fetch_assoc()) {
            $h = (int)$r['h'];
            $last = ($h === $curHour);
            $pstryk[$h] = ($r['kwh_p'] === null || ($last && (float)$r['kwh_p'] == 0)) ? null : (float)$r['kwh_p'];
            $energa[$h] = ($r['kwh_e'] === null) ? null
                        : (($last && (float)$r['kwh_e'] == 0) ? null : (float)$r['kwh_e']);
        }
        $db->select_db('tymos');
    }

} elseif ($view === 'monthly') {
    $title = 'Odczyty Pstryk vs Energa-Operator · miesiąc';
    $daysInMonth = (int)date('t');
    $cats   = array_map('strval', range(1, $daysInMonth));
    $pstryk = array_fill(0, $daysInMonth, null);
    $energa = array_fill(0, $daysInMonth, null);
    if ($db) {
        $db->select_db('tymos');
        $res = $db->query("
            SELECT DAY(ts) AS d,
                   ROUND(SUM(kwh_pstryk),          3) AS kwh_p,
                   ROUND(SUM(kwh_energa_operator), 3) AS kwh_e
            FROM energa
            WHERE DATE_FORMAT(ts, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
              AND ts < DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00')
            GROUP BY DATE(ts)
            ORDER BY DATE(ts) ASC
        ");
        while ($r = $res->fetch_assoc()) {
            $d = (int)$r['d'] - 1;
            $pstryk[$d] = (float)$r['kwh_p'] == 0 ? null : (float)$r['kwh_p'];
            $energa[$d] = ($r['kwh_e'] === null || (float)$r['kwh_e'] == 0) ? null : (float)$r['kwh_e'];
        }
        $db->select_db('tymos');
    }

} elseif ($view === 'peryear') {
    // Sumy kWh per ROK. Lata bywaja niepelne (dane od 11.2025) — liczba miesiecy w podpisie slupka.
    $title = 'Odczyty Pstryk vs Energa-Operator · per rok';
    if ($db) {
        $db->select_db('tymos');
        $res = $db->query("
            SELECT YEAR(ts) AS y,
                   ROUND(SUM(kwh_pstryk),          2) AS kwh_p,
                   ROUND(SUM(kwh_energa_operator), 2) AS kwh_e,
                   COUNT(DISTINCT CASE WHEN kwh_pstryk > 0 THEN DATE_FORMAT(ts, '%Y-%m') END) AS mies
            FROM energa
            GROUP BY YEAR(ts)
            HAVING kwh_p > 0
            ORDER BY y ASC
        ");
        while ($r = $res->fetch_assoc()) {
            $cats[]   = $r['y'] . ' (' . (int)$r['mies'] . ' mc)';
            $pstryk[] = (float)$r['kwh_p'] == 0 ? null : (float)$r['kwh_p'];
            $energa[] = ($r['kwh_e'] === null || (float)$r['kwh_e'] == 0) ? null : (float)$r['kwh_e'];
        }
        $db->select_db('tymos');
    }

} else { // yearly
    $title = 'Odczyty Pstryk vs Energa-Operator · rok';
    if ($db) {
        $db->select_db('tymos');
        $res = $db->query("
            SELECT DATE_FORMAT(ts, '%Y-%m') AS m,
                   ROUND(SUM(kwh_pstryk),          2) AS kwh_p,
                   ROUND(SUM(kwh_energa_operator), 2) AS kwh_e
            FROM energa
            WHERE ts >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01 00:00:00')
            GROUP BY DATE_FORMAT(ts, '%Y-%m')
            ORDER BY m ASC
        ");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        foreach ($rows as $i => $r) {
            $last     = ($i === count($rows) - 1);
            $mo       = substr($r['m'], 5, 2);
            $cats[]   = $months_pl[$mo] ?? $mo;
            $pstryk[] = ($last && (float)$r['kwh_p'] == 0) ? null : (float)$r['kwh_p'];
            $energa[] = ($r['kwh_e'] === null) ? null
                      : (($last && (float)$r['kwh_e'] == 0) ? null : (float)$r['kwh_e']);
        }
        $db->select_db('tymos');
    }
}
?>
<div class="card-title"><?= $title ?></div>
<?= chart_render([
    'width'      => 800,
    'categories' => $cats,
    'yaxis'      => [['color' => '#9a9a9a', 'oy_label_below' => ['text' => 'kWh', 'color' => '#9a9a9a']]],
    'legend'     => [
        ['color' => '#42a5f5', 'label' => 'Pstryk',  'type' => 'line'],
        ['color' => '#ffffff', 'label' => 'Energa-Operator',  'type' => 'line'],
    ],
    'series'     => [
        ['type' => 'line', 'data' => $pstryk, 'color' => '#42a5f5', 'null_gap' => true],
        ['type' => 'line', 'data' => $energa, 'color' => '#ffffff', 'null_gap' => true],
    ],
]) ?>
