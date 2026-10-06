<?php
require_once __DIR__ . '/../../config.inc.php';
require_once __DIR__ . '/../../inc/db.inc.php';
require_once __DIR__ . '/../../inc/chart.inc.php';
$db->select_db('tymos');

$res = $db->query("
    SELECT
        DATE_FORMAT(ts, '%Y-%m')        AS ym,
        DATE_FORMAT(MIN(ts), '%m/%y')   AS label,
        SUM(bags)                       AS bags,
        SUM(kg)                         AS kg,
        SUM(cost)                       AS cost
    FROM pellet
    WHERE ts >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(ts, '%Y-%m')
    ORDER BY ym ASC
");

$cats = []; $cost_d = []; $kg_d = []; $labels = []; $ym_list = [];
while ($r = $res->fetch_assoc()) {
    $cats[]    = $r['label'];
    $cost_d[]  = (float)$r['cost'];
    $kg_d[]    = (float)$r['kg'];
    $ym_list[] = $r['ym'];
    $bags      = (int)$r['bags'];
    $lbl       = number_format((float)$r['cost'], 0, '.', ' ') . "\npln";
    if ($bags > 0) $lbl .= "\n" . $bags . "\nw";
    $labels[] = $lbl;
}

// Koszt i kWh grzalek (Pstryk) per miesiac + doklejka premium G11f proporcjonalnie do udzialu w domu
$abo_g11f_premium = (55.51 - 11.70) * 1.23;  // brutto/mc

$dom_kwh_by_month = [];
$res = $db->query("
    SELECT DATE_FORMAT(ts, '%Y-%m') AS m, SUM(kwh_pstryk) AS dom_kwh
    FROM energa
    WHERE ts >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(ts, '%Y-%m')
");
while ($r = $res->fetch_assoc()) $dom_kwh_by_month[$r['m']] = (float)$r['dom_kwh'];
// Biezacy miesiac: ekstrapolacja dom_kwh na pelny miesiac (inaczej abo_share zawyzony)
$cur_m = date('Y-m');
if (isset($dom_kwh_by_month[$cur_m])) {
    $dp = (int)date('j'); $dim = (int)date('t');
    if ($dp > 0) $dom_kwh_by_month[$cur_m] = $dom_kwh_by_month[$cur_m] * $dim / $dp;
}

$gz_cost = []; $gz_kwh = [];
$res = $db->query("
    SELECT DATE_FORMAT(ts, '%Y-%m') AS m, ROUND(SUM(cost_electric), 1) AS ce, ROUND(SUM(kwh), 0) AS kwh
    FROM energa_vs_pellet
    WHERE ts >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(ts, '%Y-%m')
");
while ($r = $res->fetch_assoc()) {
    $kwh = (float)$r['kwh'];
    $dom = $dom_kwh_by_month[$r['m']] ?? 0;
    $abo_share = $dom > 0 ? $abo_g11f_premium * ($kwh / $dom) : 0;
    $gz_cost[$r['m']] = (float)$r['ce'] + $abo_share;
    $gz_kwh[$r['m']]  = $kwh;
}

// Dodaj miesiace z grzalek ktorych nie ma w pellet
foreach ($gz_cost as $m => $v) {
    if (!in_array($m, $ym_list)) {
        $ym_list[] = $m;
        $mo = substr($m, 5, 2); $yr = substr($m, 2, 2);
        $cats[] = $mo . '/' . $yr;
        $cost_d[] = null;
        $kg_d[] = null;
        $labels[] = '';
    }
}

$gz_d = []; $gz_labels = [];
foreach ($ym_list as $ym) {
    $v = $gz_cost[$ym] ?? 0;
    $gz_d[] = $v > 0 ? $v : null;
    $kwh = $gz_kwh[$ym] ?? 0;
    $avg = $kwh > 0 ? $v / $kwh : 0;
    $gz_labels[] = $v > 0 ? ((int)round($v) . "\npln\n" . number_format($avg, 2) . "\n/kWh") : '';
}
?>
<div class="card-title">Koszt grzania bufora · miesiące <span style="opacity:.6;font-size:.75em">(zawiera aboG11f)</span></div>
<?= chart_render([
    'width'      => 800,
    'categories' => $cats,
    'yaxis'      => [
        ['color' => '#ff6600', 'decimals' => 0, 'oy_label_below' => ['text' => 'PLN', 'color' => '#ff6600']],
        ['color' => '#ffffff',  'decimals' => 0, 'side' => 'right', 'oy_label_below' => ['text' => 'kg', 'color' => '#ffffff']],
    ],
    'series' => [
        ['type' => 'bar',  'data' => $cost_d, 'color' => '#ff6600', 'yaxis' => 0, 'grouped' => true, 'labels' => $labels, 'label_color' => '#cccccc'],
        ['type' => 'line', 'data' => $kg_d,   'color' => '#ffffff',  'yaxis' => 1, 'line_width' => 2, 'color_alpha' => 51],
        ['type' => 'bar',  'data' => $gz_d,   'color' => '#1565c0',  'yaxis' => 0, 'grouped' => true, 'labels' => $gz_labels, 'label_color' => '#42a5f5'],
    ],
    'legend' => [
        ['color' => '#ff6600', 'label' => 'Pellet (faktyczny)', 'type' => 'bar'],
        ['color' => '#1565c0', 'label' => 'Grzałki (Pstryk)',   'type' => 'bar'],
        ['color' => '#ffffff', 'label' => 'kg',                 'type' => 'line'],
    ],
]) ?>
