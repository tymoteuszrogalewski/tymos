<?php
require_once __DIR__ . '/../../inc/chart.inc.php';

$view      = $_GET['view'] ?? 'yearly';
$months_pl = ['01'=>'sty','02'=>'lut','03'=>'mar','04'=>'kwi','05'=>'maj','06'=>'cze',
               '07'=>'lip','08'=>'sie','09'=>'wrz','10'=>'paź','11'=>'lis','12'=>'gru'];

// Seria "srednia cena rynkowa" wypadla 2026-08-22: przy taryfie dynamicznej nic nie mowi,
// bo realny koszt zalezy od tego, W KTORYCH godzinach sie zuzywa. Zostaje uzyskany koszt.
// Druga seria (2026-10-01): sama energia z gieldy NETTO, wazona zuzyciem — bez dystrybucji, marzy Pstryka,
// akcyzy i VAT. Pod gwarancje Pstryka: srednia roczna 0,50 zl/kWh netto, nadwyzke zwracaja.
// Zrodlo: cost.energy_cost_net z API Pstryka (kolumna cost_energy_net_pstryk, dociagnieta od 2026-03-01 —
// prad z Pstryka jest od marca, wczesniejsze miesiace zostaja bez niebieskiego slupka).
$TGE_SQL = "ROUND(SUM(cost_energy_net_pstryk) / NULLIF(SUM(CASE WHEN cost_energy_net_pstryk IS NOT NULL THEN kwh_pstryk END), 0), 4) AS tge_price";
$GWARANCJA = 0.50;

$cats = []; $avg_my = [];
$avg_my_lbl = [];
$avg_tge = []; $avg_tge_lbl = [];
$title = 'Pstryk średnie ceny';

if ($view === 'monthly') {
    $monthParam = (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']))
                  ? $_GET['month'] : date('Y-m');
    $curMonth = date('Y-m');
    $isThisMonth = ($monthParam === $curMonth);
    $daysInMonth = (int)date('t', strtotime($monthParam . '-01'));

    $mo = substr($monthParam, 5, 2);
    $yr = substr($monthParam, 0, 4);
    $title = 'Pstryk średnie ceny · ' . ($months_pl[$mo] ?? $mo) . ' ' . $yr;

    $cats = array_map('strval', range(1, $daysInMonth));
    $avg_my = array_fill(0, $daysInMonth, null);
    $avg_tge = array_fill(0, $daysInMonth, null);

    if ($db) {
        $db->select_db('tymos');
        $res = $db->query("
            SELECT DAY(ts) AS d,
                   ROUND(SUM(cost_full_pstryk) / NULLIF(SUM(kwh_pstryk), 0), 4) AS my_price,
                   $TGE_SQL
            FROM energa
            WHERE DATE_FORMAT(ts, '%Y-%m') = '$monthParam'
              AND kwh_pstryk > 0
            GROUP BY DATE(ts)
            ORDER BY DATE(ts) ASC
        ");
        while ($r = $res->fetch_assoc()) {
            $d = (int)$r['d'] - 1;
            if ($d < $daysInMonth) {
                $avg_my[$d] = $r['my_price'] !== null ? (float)$r['my_price'] : null;
                $avg_tge[$d] = $r['tge_price'] !== null ? (float)$r['tge_price'] : null;
            }
        }
        // last day with data incomplete — null if zero
        if ($isThisMonth) {
            $curDay = (int)date('j') - 1;
            if ($curDay >= 0) {
                if ($avg_my[$curDay] !== null && (float)$avg_my[$curDay] == 0) $avg_my[$curDay] = null;
            }
        }
        $db->select_db('tymos');
    }

} elseif ($view === 'peryear') {
    // Lata bywaja niepelne (dane od 11.2025), wiec kazdy slupek ma w podpisie liczbe miesiecy —
    // inaczej pierwszy rok wygladalby na tani, a to tylko krotszy kawalek.
    $title = 'Pstryk uzyskany koszt · per rok (czerwone zawiera opłaty stałe)';
    if ($db) {
        $db->select_db('tymos');
        $res = $db->query("
            SELECT YEAR(ts) AS y,
                   ROUND(SUM(cost_full_pstryk) / NULLIF(SUM(kwh_pstryk), 0), 4) AS my_price,
                   $TGE_SQL,
                   COUNT(DISTINCT DATE_FORMAT(ts, '%Y-%m')) AS mies
            FROM energa
            WHERE kwh_pstryk > 0
            GROUP BY YEAR(ts)
            ORDER BY y ASC
        ");
        while ($r = $res->fetch_assoc()) {
            $v = $r['my_price'] !== null ? (float)$r['my_price'] : null;
            $cats[]       = $r['y'] . ' (' . (int)$r['mies'] . ' mc)';
            $avg_my[]     = $v;
            $avg_my_lbl[] = $v !== null ? number_format($v, 2, '.', '') : '';
            $t = $r['tge_price'] !== null ? (float)$r['tge_price'] : null;
            $avg_tge[]     = $t;
            $avg_tge_lbl[] = $t !== null ? number_format($t, 2, '.', '') : '';
        }
        $db->select_db('tymos');
    }

} else { // yearly
    $title = 'Pstryk średnie ceny · rok (czerwone zawiera opłaty stałe)';
    if ($db) {
        $db->select_db('tymos');
        $res = $db->query("
            SELECT DATE_FORMAT(ts, '%Y-%m') AS m,
                   ROUND(SUM(cost_full_pstryk) / NULLIF(SUM(kwh_pstryk), 0), 4) AS my_price,
                   $TGE_SQL
            FROM energa
            WHERE ts >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01 00:00:00')
              AND kwh_pstryk > 0
            GROUP BY DATE_FORMAT(ts, '%Y-%m')
            ORDER BY m ASC
        ");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        foreach ($rows as $i => $r) {
            $last = ($i === count($rows) - 1);
            $mo   = substr($r['m'], 5, 2);
            $cats[] = $months_pl[$mo] ?? $mo;
            $vA = ($last && (float)$r['my_price'] == 0) ? null : (float)$r['my_price'];
            $avg_my[]     = $vA;
            $avg_my_lbl[] = $vA !== null ? number_format($vA, 2, '.', '') : '';
            $vT = ($last && $vA === null) ? null : ($r['tge_price'] !== null ? (float)$r['tge_price'] : null);
            $avg_tge[]     = $vT;
            $avg_tge_lbl[] = $vT !== null ? number_format($vT, 2, '.', '') : '';
        }
        $db->select_db('tymos');
    }
}
?>
<div class="card-title"><?= $title ?></div>
<?php if ($view === 'yearly' || $view === 'peryear'): ?>
<?= chart_render([
    'width'      => 800,
    'categories' => $cats,
    'yaxis'      => [['color' => '#9a9a9a', 'decimals' => 2, 'oy_label_below' => ['text' => 'PLN', 'color' => '#9a9a9a']]],
    'series'     => [
        ['type'=>'bar','data'=>$avg_my,'color'=>'#f44336','labels'=>$avg_my_lbl,'label_color'=>'#f44336'],
        ['type'=>'bar','data'=>$avg_tge,'color'=>'#2196f3','labels'=>$avg_tge_lbl,'label_color'=>'#2196f3'],
    ],
    'h_annotations' => [['y' => $GWARANCJA, 'yaxis' => 0, 'color' => '#ffc107']],
    'legend' => [
        ['color' => '#f44336', 'label' => 'Średni uzyskany koszt PLN/kWh','type' => 'area'],
        ['color' => '#2196f3', 'label' => 'Sama energia z giełdy netto','type' => 'area'],
        ['color' => '#ffc107', 'label' => 'Gwarancja 0,50','type' => 'line', 'dash' => 5],
    ],
]) ?>
<?php else: ?>
<?= chart_render([
    'width'      => 800,
    'categories' => $cats,
    'yaxis'      => [['color' => '#9a9a9a', 'decimals' => 2, 'oy_label_below' => ['text' => 'PLN', 'color' => '#9a9a9a']]],
    'series'     => [
        ['type'=>'line','data'=>$avg_my,'color'=>'#f44336','null_gap'=>true],
        ['type'=>'line','data'=>$avg_tge,'color'=>'#2196f3','null_gap'=>true],
    ],
    'h_annotations' => [['y' => $GWARANCJA, 'yaxis' => 0, 'color' => '#ffc107']],
    'legend' => [
        ['color' => '#f44336', 'label' => 'Średni uzyskany koszt PLN/kWh','type' => 'line'],
        ['color' => '#2196f3', 'label' => 'Sama energia z giełdy netto','type' => 'line'],
        ['color' => '#ffc107', 'label' => 'Gwarancja 0,50','type' => 'line', 'dash' => 5],
    ],
]) ?>
<?php endif; ?>
