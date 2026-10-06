<?php
require_once __DIR__ . '/../../inc/chart.inc.php';

$DEV = 527;
$view  = $_GET['view'] ?? 'daily';
$today = date('Y-m-d');
$ann   = [];
$title = 'Rekuperator Wentylatory';
$tbl   = "device{$DEV}";

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
    $supply  = array_fill(0, 24, null);
    $exhaust = array_fill(0, 24, null);
    $power   = array_fill(0, 24, null);

    $res = $db->query("SELECT HOUR(ts) AS h,
        ROUND(AVG(supply_fan_flow),1) AS sf,
        ROUND(AVG(exhaust_fan_flow),1) AS ef,
        ROUND(AVG(power),0) AS pw
        FROM `{$tbl}` WHERE DATE(ts) = '{$dateParam}'
        AND supply_fan_flow IS NOT NULL
        GROUP BY HOUR(ts) ORDER BY h");
    if ($res) while ($r = $res->fetch_assoc()) {
        $h = (int)$r['h'];
        $supply[$h]  = $r['sf'] !== null ? (float)$r['sf'] : null;
        $exhaust[$h] = $r['ef'] !== null ? (float)$r['ef'] : null;
        $power[$h]   = $r['pw'] !== null ? (float)$r['pw'] : null;
    }

} elseif ($view === 'monthly') {
    $title = 'Rekuperator Wentylatory · Miesiąc';
    $daysInMonth = (int)date('t');
    $totalBuckets = $daysInMonth * 24;
    $cats = [];
    for ($b = 0; $b < $totalBuckets; $b++) {
        $cats[] = ($b % 24 === 0) ? (string)(intdiv($b, 24) + 1) : '';
    }
    $supply  = array_fill(0, $totalBuckets, null);
    $exhaust = array_fill(0, $totalBuckets, null);
    $power   = array_fill(0, $totalBuckets, null);
    for ($d = 1; $d < $daysInMonth; $d++) $ann[] = ['x' => (string)($d + 1), 'color' => '#444455'];

    $res = $db->query("SELECT DAY(ts) AS d, HOUR(ts) AS h,
        ROUND(AVG(supply_fan_flow),1) AS sf,
        ROUND(AVG(exhaust_fan_flow),1) AS ef,
        ROUND(AVG(power),0) AS pw
        FROM `{$tbl}` WHERE DATE_FORMAT(ts,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')
        AND supply_fan_flow IS NOT NULL
        GROUP BY DAY(ts),HOUR(ts) ORDER BY d,h");
    if ($res) while ($r = $res->fetch_assoc()) {
        $idx = ((int)$r['d'] - 1) * 24 + (int)$r['h'];
        if ($idx < $totalBuckets) {
            $supply[$idx]  = $r['sf'] !== null ? (float)$r['sf'] : null;
            $exhaust[$idx] = $r['ef'] !== null ? (float)$r['ef'] : null;
            $power[$idx]   = $r['pw'] !== null ? (float)$r['pw'] : null;
        }
    }

} else { // yearly
    $title = 'Rekuperator Wentylatory · Rok';
    $months_pl = ['01'=>'sty','02'=>'lut','03'=>'mar','04'=>'kwi','05'=>'maj','06'=>'cze',
                  '07'=>'lip','08'=>'sie','09'=>'wrz','10'=>'paź','11'=>'lis','12'=>'gru'];

    $dates = [];
    $res = $db->query("SELECT DATE(ts) AS d, DATE_FORMAT(ts,'%m') AS mo FROM `{$tbl}` WHERE ts >= NOW() - INTERVAL 365 DAY AND supply_fan_flow IS NOT NULL GROUP BY DATE(ts) ORDER BY d ASC");
    if ($res) while ($row = $res->fetch_assoc()) $dates[] = ['d'=>$row['d'],'mo'=>$row['mo']];

    $cats = []; $prevMo = null;
    $supply = []; $exhaust = []; $power = [];
    foreach ($dates as $dd) {
        $label = $months_pl[$dd['mo']] ?? $dd['mo'];
        $cats[] = $label;
        if ($dd['mo'] !== $prevMo && $prevMo !== null) $ann[] = ['x' => $label, 'color' => '#444455'];
        $prevMo = $dd['mo'];
        $supply[] = null; $exhaust[] = null; $power[] = null;
    }
    $dateIdx = array_flip(array_column($dates, 'd'));

    $res = $db->query("SELECT DATE(ts) AS d,
        ROUND(AVG(supply_fan_flow),1) AS sf,
        ROUND(AVG(exhaust_fan_flow),1) AS ef,
        ROUND(AVG(power),0) AS pw
        FROM `{$tbl}` WHERE ts >= NOW() - INTERVAL 365 DAY AND supply_fan_flow IS NOT NULL
        GROUP BY DATE(ts)");
    if ($res) while ($r = $res->fetch_assoc()) {
        $idx = $dateIdx[$r['d']] ?? null;
        if ($idx !== null) {
            $supply[$idx]  = $r['sf'] !== null ? (float)$r['sf'] : null;
            $exhaust[$idx] = $r['ef'] !== null ? (float)$r['ef'] : null;
            $power[$idx]   = $r['pw'] !== null ? (float)$r['pw'] : null;
        }
    }
}
?>
<div class="card-title"><?= $title ?></div>
<?= chart_render([
    'width'       => 800,
    'categories'  => $cats,
    'annotations' => $ann,
    'yaxis'       => [
        ['color' => '#9a9a9a', 'decimals' => 0, 'grids' => 6, 'tight' => true, 'oy_label_below' => ['text' => 'm³/h', 'color' => '#9a9a9a']],
        ['color' => '#ff6600', 'decimals' => 0, 'side' => 'right', 'tight' => true, 'oy_label_below' => ['text' => 'W', 'color' => '#ff6600']],
    ],
    'series' => [
        ['type' => 'line', 'data' => $supply,  'color' => '#42a5f5', 'null_gap' => true, 'line_width' => 2],
        ['type' => 'line', 'data' => $exhaust, 'color' => '#ef5350', 'null_gap' => true, 'line_width' => 2],
        ['type' => 'line', 'data' => $power,   'color' => '#ff6600', 'yaxis' => 1, 'null_gap' => true, 'line_width' => 1, 'dash' => 6],
    ],
    'legend' => [
        ['color' => '#42a5f5', 'label' => 'Nawiew (supply)',   'type' => 'line'],
        ['color' => '#ef5350', 'label' => 'Wywiew (exhaust)',  'type' => 'line'],
        ['color' => '#ff6600', 'label' => 'Moc (W)',           'type' => 'line', 'dash' => 6],
    ],
]) ?>
