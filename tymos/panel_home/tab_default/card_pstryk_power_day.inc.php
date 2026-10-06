<?php
require_once __DIR__ . '/../../inc/chart.inc.php';

$view      = $_GET['view'] ?? 'daily';
$today     = date('Y-m-d');

// ml* = MAX (solid, main series), al* = AVG (dotted, only monthly/yearly)
$cats = []; $ml1=[]; $ml2=[]; $ml3=[]; $al1=[]; $al2=[]; $al3=[];
$ann  = [];
$hasAvg = false;
$title = 'Moc · L1/L2/L3';

if ($view === 'daily') {
    $dateParam = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']))
                 ? $_GET['date'] : $today;
    if ($dateParam > $today) $dateParam = $today;
    $isToday = ($dateParam === $today);
    $curHour = $isToday ? (int)date('G') : -1;

    if (!$isToday) {
        $diff = (int)round((strtotime($today) - strtotime($dateParam)) / 86400);
        if ($diff === 1)      $rel = 'Wczoraj';
        elseif ($diff === 2)  $rel = 'Przedwczoraj';
        else                  $rel = $diff . ' dni temu';
        $title .= ' · ' . htmlspecialchars($rel) . ' ' . date('d.m', strtotime($dateParam));
    }

    $hasAvg = true;
    // pre-fill 288 slots (24h * 12 per hour, every 5 min)
    $cats = []; $ml1 = []; $ml2 = []; $ml3 = []; $al1 = []; $al2 = []; $al3 = [];
    for ($s = 0; $s < 288; $s++) {
        $hh = intdiv($s, 12);
        $mm = ($s % 12) * 5;
        $cats[] = ($mm === 0) ? (string)$hh : '';
        $ml1[]  = null; $ml2[] = null; $ml3[] = null;
        $al1[]  = null; $al2[] = null; $al3[] = null;
    }

    if ($db) {
        $db->select_db('tymos');
        // MAX/AVG per 5-min slot: BleBox is polled every 10s, so ~30 samples land in one slot.
        // Without grouping the last sample would win and short peaks would never show up.
        $res = $db->query("
            SELECT HOUR(d300.ts) AS h,
                   FLOOR(MINUTE(d300.ts) / 5) AS mslot,
                   ROUND(MAX(d300.power), 1) AS ml1, ROUND(AVG(d300.power), 1) AS al1,
                   ROUND(MAX(d301.power), 1) AS ml2, ROUND(AVG(d301.power), 1) AS al2,
                   ROUND(MAX(d302.power), 1) AS ml3, ROUND(AVG(d302.power), 1) AS al3
            FROM device300 d300 LEFT JOIN device301 d301 ON d301.ts=d300.ts LEFT JOIN device302 d302 ON d302.ts=d300.ts
            WHERE DATE(d300.ts) = '$dateParam'
            GROUP BY HOUR(d300.ts), FLOOR(MINUTE(d300.ts) / 5)
            ORDER BY HOUR(d300.ts), FLOOR(MINUTE(d300.ts) / 5)
        ");
        while ($r = $res->fetch_assoc()) {
            $idx = (int)$r['h'] * 12 + (int)$r['mslot'];
            $ml1[$idx] = $r['ml1'] !== null ? (float)$r['ml1'] : null;
            $ml2[$idx] = $r['ml2'] !== null ? (float)$r['ml2'] : null;
            $ml3[$idx] = $r['ml3'] !== null ? (float)$r['ml3'] : null;
            $al1[$idx] = $r['al1'] !== null ? (float)$r['al1'] : null;
            $al2[$idx] = $r['al2'] !== null ? (float)$r['al2'] : null;
            $al3[$idx] = $r['al3'] !== null ? (float)$r['al3'] : null;
        }

    }

} elseif ($view === 'monthly') {
    $title = 'Moc · miesiąc · L1/L2/L3';
    $daysInMonth = (int)date('t');
    // pre-fill: 24 buckets per day (hourly)
    $totalBuckets = $daysInMonth * 24;
    $cats = []; $ml1 = []; $ml2 = []; $ml3 = [];
    for ($b = 0; $b < $totalBuckets; $b++) {
        $day = intdiv($b, 24) + 1;
        $cats[] = ($b % 24 === 0) ? (string)$day : '';
        $ml1[] = null; $ml2[] = null; $ml3[] = null;
    }
    if ($db) {
        $db->select_db('tymos');
        $res = $db->query("
            SELECT DAY(d300.ts) AS d, HOUR(d300.ts) AS h,
                   ROUND(MAX(d300.power), 1) AS ml1,
                   ROUND(MAX(d301.power), 1) AS ml2,
                   ROUND(MAX(d302.power), 1) AS ml3
            FROM device300 d300 LEFT JOIN device301 d301 ON d301.ts=d300.ts LEFT JOIN device302 d302 ON d302.ts=d300.ts
            WHERE DATE_FORMAT(d300.ts, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
            GROUP BY DAY(d300.ts), HOUR(d300.ts)
            ORDER BY DAY(d300.ts), HOUR(d300.ts)
        ");
        while ($r = $res->fetch_assoc()) {
            $idx = ((int)$r['d'] - 1) * 24 + (int)$r['h'];
            $ml1[$idx] = (float)$r['ml1'];
            $ml2[$idx] = (float)$r['ml2'];
            $ml3[$idx] = (float)$r['ml3'];
        }

    }

} else { // yearly
    $title = 'Moc · rok · L1/L2/L3';
    $months_pl = ['01'=>'sty','02'=>'lut','03'=>'mar','04'=>'kwi','05'=>'maj','06'=>'cze',
                  '07'=>'lip','08'=>'sie','09'=>'wrz','10'=>'paź','11'=>'lis','12'=>'gru'];
    if ($db) {
        $db->select_db('tymos');
        $res = $db->query("
            SELECT DATE(d300.ts) AS bucket,
                   DATE_FORMAT(d300.ts, '%m') AS mo,
                   ROUND(MAX(d300.power), 1) AS ml1,
                   ROUND(MAX(d301.power), 1) AS ml2,
                   ROUND(MAX(d302.power), 1) AS ml3
            FROM device300 d300 LEFT JOIN device301 d301 ON d301.ts=d300.ts LEFT JOIN device302 d302 ON d302.ts=d300.ts
            WHERE d300.ts >= NOW() - INTERVAL 365 DAY
            GROUP BY DATE(d300.ts)
            ORDER BY bucket ASC
        ");
        $prevMo = null;
        while ($r = $res->fetch_assoc()) {
            $mo = $r['mo'];
            $c  = $months_pl[$mo] ?? $mo;
            $cats[] = $c;
            $ml1[]  = (float)$r['ml1'];
            $ml2[]  = (float)$r['ml2'];
            $ml3[]  = (float)$r['ml3'];
            if ($mo !== $prevMo && $prevMo !== null) $ann[] = ['x' => $c, 'color' => '#444455'];
            $prevMo = $mo;
        }

    }
}

// MAX = solid in the original colors; AVG = same colors, dotted, width 1 (daily only)
$series = [
    ['type'=>'line','data'=>$ml1,'color'=>'#e74c3c','null_gap'=>true,'line_width'=>1],
    ['type'=>'line','data'=>$ml2,'color'=>'#27ae60','null_gap'=>true,'line_width'=>1],
    ['type'=>'line','data'=>$ml3,'color'=>'#3498db','null_gap'=>true,'line_width'=>1],
];
$legend = [
    ['type'=>'line','color'=>'#e74c3c','line_width'=>1,'label'=>$hasAvg ? 'L1 max' : 'L1'],
    ['type'=>'line','color'=>'#27ae60','line_width'=>1,'label'=>$hasAvg ? 'L2 max' : 'L2'],
    ['type'=>'line','color'=>'#3498db','line_width'=>1,'label'=>$hasAvg ? 'L3 max' : 'L3'],
];
if ($hasAvg) {
    $series[] = ['type'=>'line','data'=>$al1,'color'=>'#e74c3c','null_gap'=>true,'line_width'=>1,'dash'=>2,'gap'=>3];
    $series[] = ['type'=>'line','data'=>$al2,'color'=>'#27ae60','null_gap'=>true,'line_width'=>1,'dash'=>2,'gap'=>3];
    $series[] = ['type'=>'line','data'=>$al3,'color'=>'#3498db','null_gap'=>true,'line_width'=>1,'dash'=>2,'gap'=>3];
    $legend[] = ['type'=>'line','color'=>'#e74c3c','line_width'=>1,'dash'=>2,'gap'=>3,'label'=>'L1 śr'];
    $legend[] = ['type'=>'line','color'=>'#27ae60','line_width'=>1,'dash'=>2,'gap'=>3,'label'=>'L2 śr'];
    $legend[] = ['type'=>'line','color'=>'#3498db','line_width'=>1,'dash'=>2,'gap'=>3,'label'=>'L3 śr'];
}
?>
<div class="card-title"><?= $title ?></div>
<?= chart_render([
    'width'       => 800,
    'categories'  => $cats,
    'annotations' => $ann,
    'yaxis'       => [['color' => '#9a9a9a', 'decimals' => 0, 'oy_label_below' => ['text' => 'W', 'color' => '#9a9a9a']]],
    'series'      => $series,
    'legend'      => $legend,
]) ?>
