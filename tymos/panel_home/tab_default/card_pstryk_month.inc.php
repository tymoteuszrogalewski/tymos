<?php
require_once __DIR__ . '/../../inc/chart.inc.php';

$months_pl = ['01'=>'Styczeń','02'=>'Luty','03'=>'Marzec','04'=>'Kwiecień','05'=>'Maj','06'=>'Czerwiec',
              '07'=>'Lipiec','08'=>'Sierpień','09'=>'Wrzesień','10'=>'Październik','11'=>'Listopad','12'=>'Grudzień'];

$PELLET_PRICE_KWH = 0.80;

// --- parametr: ile miesiecy wstecz (0 = biezacy) ---
$monthsBack = max(0, min(12, (int)($_GET['months_back'] ?? 0)));

$refDate = new DateTime('first day of this month');
if ($monthsBack > 0) $refDate->modify("-{$monthsBack} months");
$monthStart = $refDate->format('Y-m-01');
$monthEnd   = (clone $refDate)->modify('+1 month')->format('Y-m-01');
$monthNum   = $refDate->format('m');
$monthYear  = $refDate->format('Y');
$monthLabel = ($months_pl[$monthNum] ?? $monthNum) . ' ' . $monthYear;

$cats = []; $avg_price = [];
$sum_cost = 0; $sum_kwh = 0; $days_count = 0;
// dane grzalek per dzien (klucz = numer dnia)
$heat_electric = []; $heat_pellet = [];

if ($db) {
    $db->select_db('tymos');

    // 1. Srednia cena pradu per dzien (caly dom)
    $res = $db->query("
        SELECT DATE_FORMAT(ts, '%e') AS d,
               DATE(ts) AS full_d,
               SUM(COALESCE(cost_full_pstryk, 0)) AS cost,
               SUM(COALESCE(kwh_pstryk, 0)) AS kwh
        FROM energa
        WHERE ts >= '$monthStart'
          AND ts <  '$monthEnd'
        GROUP BY DATE(ts)
        HAVING kwh > 0
        ORDER BY DATE(ts) ASC
    ");
    while ($r = $res->fetch_assoc()) {
        $cost = (float)$r['cost'];
        $kwh  = (float)$r['kwh'];
        $avg  = $kwh > 0 ? round($cost / $kwh, 2) : null;
        $cats[]      = $r['d'];
        $avg_price[] = $avg;
        $sum_cost += $cost;
        $sum_kwh  += $kwh;
        $days_count++;
    }

    // 2. Koszt grzalek per dzien (prad dynamiczny vs pellet) — z energa_vs_pellet
    $res2 = $db->query("
        SELECT DATE_FORMAT(ts, '%e') AS d,
               ROUND(SUM(cost_electric), 2) AS cost_e,
               ROUND(SUM(cost_pellet), 2) AS cost_p
        FROM energa_vs_pellet
        WHERE ts >= '$monthStart'
          AND ts <  '$monthEnd'
        GROUP BY DATE(ts)
        ORDER BY DATE(ts) ASC
    ");
    while ($r2 = $res2->fetch_assoc()) {
        $heat_electric[$r2['d']] = (float)$r2['cost_e'];
        $heat_pellet[$r2['d']]   = (float)$r2['cost_p'];
    }
}

// Mapuj dane grzalek na osie wykresu (te same cats co ceny)
$he_data = []; $hp_data = [];
$sum_he = 0; $sum_hp = 0;
foreach ($cats as $d) {
    $he = $heat_electric[$d] ?? null;
    $hp = $heat_pellet[$d]   ?? null;
    $he_data[] = $he;
    $hp_data[] = $hp;
    if ($he !== null) $sum_he += $he;
    if ($hp !== null) $sum_hp += $hp;
}
$heat_savings = $sum_hp - $sum_he;

$total_avg = $sum_kwh > 0 ? round($sum_cost / $sum_kwh, 2) : 0;
$savColor  = $heat_savings >= 0 ? '#4caf50' : '#f44336';
$savSign   = $heat_savings >= 0 ? '+' : '';
?>
<div class="card-title"><?= $monthLabel ?> · <?= $days_count ?> dni</div>
<div style="text-align:center; margin:-4px 0 8px; font-size:14px; color:#ccc;">
    Srednia: <b style="color:#81c784"><?= number_format($total_avg, 2) ?> PLN/kWh</b>
    &nbsp;|&nbsp;
    <?= number_format($sum_kwh, 1) ?> kWh = <b style="color:#ff6666"><?= number_format($sum_cost, 1) ?> zł</b>
<?php if ($sum_he > 0 || $sum_hp > 0): ?>
    <br>
    Grzanie: prąd <b style="color:#42a5f5"><?= number_format($sum_he, 1) ?> zł</b>
    vs pellet <b style="color:#ff6600"><?= number_format($sum_hp, 1) ?> zł</b>
    = <span style="color:<?= $savColor ?>"><b><?= $savSign . number_format($heat_savings, 1) ?> zł</b></span>
<?php endif; ?>
</div>
<?= chart_render([
    'width'       => 680,
    'height'      => 300,
    'bg'          => 'transparent',
    'grid_color'  => '#ffffff',
    'no_grid'     => true,
    'label_color' => '#d0d0d0',
    'font_size'   => 8,
    'margin'      => ['top' => 36, 'right' => 46, 'bottom' => 28, 'left' => 40],
    'categories'  => $cats,
    'yaxis'       => [
        ['color' => '#81c784', 'decimals' => 2, 'grids' => 10,
         'oy_label_above' => ['text' => 'PLN/kWh', 'color' => '#81c784', 'offset_x' => 30]],
        ['color' => '#ff6600', 'decimals' => 1, 'side' => 'right',
         'oy_label_above' => ['text' => 'PLN', 'color' => '#ff6600']],
    ],
    'legend' => [
        ['color' => '#81c784', 'label' => 'Śr. cena',       'type' => 'bar'],
        ['color' => '#42a5f5', 'label' => 'Grzałki (prąd)', 'type' => 'line'],
        ['color' => '#ff6600', 'label' => 'Pellet (ekw.)',   'type' => 'line'],
    ],
    'series' => [
        [
            'data'         => $avg_price,
            'type'         => 'bar',
            'yaxis'        => 0,
            'color'        => '#81c784',
            'color_ranges' => [
                ['max' => 0,    'color' => '#9c27b0'],
                ['max' => 0.40, 'color' => '#ffffff'],
                ['max' => 0.50, 'color' => '#b0e8b0'],
                ['max' => 1.10, 'color' => '#6abf6a'],
                ['max' => 1.40, 'color' => '#ff9800'],
                ['color' => '#f44336'],
            ],
        ],
        [
            'data'       => $he_data,
            'type'       => 'line',
            'yaxis'      => 1,
            'color'      => '#42a5f5',
            'line_width' => 2,
            'null_gap'   => true,
        ],
        [
            'data'       => $hp_data,
            'type'       => 'line',
            'yaxis'      => 1,
            'color'      => '#ff6600',
            'line_width' => 2,
            'null_gap'   => true,
        ],
    ],
    'h_annotations' => [
        ['y' => $total_avg, 'yaxis' => 0, 'color' => '#ffffff', 'dash' => 4, 'gap' => 6],
    ],
]) ?>
