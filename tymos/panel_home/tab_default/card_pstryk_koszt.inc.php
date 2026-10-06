<?php
require_once __DIR__ . '/../../inc/chart.inc.php';
// G12r = cost_full_g12r_grz (energia grzalek po stawce pozaszczytowej, patrz import_pstryk.php),
// z COALESCE na stary cost_full_g12r dla godzin bez korekty.

$view      = $_GET['view'] ?? 'yearly';
$today     = date('Y-m-d');
$months_pl = ['01'=>'sty','02'=>'lut','03'=>'mar','04'=>'kwi','05'=>'maj','06'=>'cze',
               '07'=>'lip','08'=>'sie','09'=>'wrz','10'=>'paź','11'=>'lis','12'=>'gru'];

$cats = []; $pln = []; $g11 = []; $g12r = [];
$pln_lbl = []; $g11_lbl = []; $g12r_lbl = [];
$title = 'Koszt · Pstryk / G11 / G12r';

if ($view === 'daily') {
    $dateParam = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']))
                 ? $_GET['date'] : $today;
    if ($dateParam > $today) $dateParam = $today;
    $isToday = ($dateParam === $today);
    $curHour = $isToday ? (int)date('G') : -1;

    $cats = array_map(fn($h) => (string)$h, range(0, 23));
    $pln  = array_fill(0, 24, null);
    $g11  = array_fill(0, 24, null);
    $g12r = array_fill(0, 24, null);

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
                   ROUND(cost_full_pstryk, 4) AS wszystko_pln,
                   ROUND(cost_full_g11,    4) AS wszystko_g11,
                   ROUND(COALESCE(cost_full_g12r_grz, cost_full_g12r), 4) AS wszystko_g12r
            FROM energa
            WHERE DATE(ts) = '$dateParam'
            ORDER BY ts ASC
        ");
        while ($r = $res->fetch_assoc()) {
            $h    = (int)$r['h'];
            $last = ($h === $curHour);
            $pln[$h]  = ($r['wszystko_pln']  === null || ($last && (float)$r['wszystko_pln']  == 0)) ? null : (float)$r['wszystko_pln'];
            $g11[$h]  = ($r['wszystko_g11']  === null || ($last && (float)$r['wszystko_g11']  == 0)) ? null : (float)$r['wszystko_g11'];
            $g12r[$h] = ($r['wszystko_g12r'] === null || ($last && (float)$r['wszystko_g12r'] == 0)) ? null : (float)$r['wszystko_g12r'];
        }
        $db->select_db('tymos');
    }

} elseif ($view === 'monthly') {
    $title = 'Koszt · miesiąc · Pstryk / G11 / G12r';
    $daysInMonth = (int)date('t');
    $cats = array_map('strval', range(1, $daysInMonth));
    $pln  = array_fill(0, $daysInMonth, null);
    $g11  = array_fill(0, $daysInMonth, null);
    $g12r = array_fill(0, $daysInMonth, null);
    if ($db) {
        $db->select_db('tymos');
        $res = $db->query("
            SELECT DAY(ts) AS d,
                   ROUND(SUM(COALESCE(cost_full_pstryk, 0)), 4) AS wszystko_pln,
                   ROUND(SUM(COALESCE(cost_full_g11,    0)), 4) AS wszystko_g11,
                   ROUND(SUM(COALESCE(cost_full_g12r_grz, cost_full_g12r, 0)), 4) AS wszystko_g12r
            FROM energa
            WHERE DATE_FORMAT(ts, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
              AND ts < DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00')
            GROUP BY DATE(ts)
            ORDER BY DATE(ts) ASC
        ");
        while ($r = $res->fetch_assoc()) {
            $d = (int)$r['d'] - 1;
            $pln[$d]  = (float)$r['wszystko_pln']  == 0 ? null : (float)$r['wszystko_pln'];
            $g11[$d]  = (float)$r['wszystko_g11']  == 0 ? null : (float)$r['wszystko_g11'];
            $g12r[$d] = (float)$r['wszystko_g12r'] == 0 ? null : (float)$r['wszystko_g12r'];
        }
        $db->select_db('tymos');
    }

} elseif ($view === 'peryear') {
    // Suma kosztow per ROK. Lata bywaja niepelne (dane od 11.2025) — liczba miesiecy w podpisie
    // slupka, zeby krotszy rok nie wygladal na tanszy.
    $title = 'Koszt · per rok · Pstryk / G11 / G12r';
    if ($db) {
        $db->select_db('tymos');
        $res = $db->query("
            SELECT YEAR(ts) AS y,
                   ROUND(SUM(COALESCE(cost_full_pstryk, 0)), 4) AS wszystko_pln,
                   ROUND(SUM(COALESCE(cost_full_g11,    0)), 4) AS wszystko_g11,
                   ROUND(SUM(COALESCE(cost_full_g12r_grz, cost_full_g12r, 0)), 4) AS wszystko_g12r,
                   COUNT(DISTINCT CASE WHEN cost_full_pstryk > 0 THEN DATE_FORMAT(ts, '%Y-%m') END) AS mies
            FROM energa
            GROUP BY YEAR(ts)
            HAVING wszystko_pln > 0
            ORDER BY y ASC
        ");
        while ($r = $res->fetch_assoc()) {
            $vPln  = (float)$r['wszystko_pln'];
            $vG11  = (float)$r['wszystko_g11'];
            $vG12r = (float)$r['wszystko_g12r'];
            $cats[]     = $r['y'] . ' (' . (int)$r['mies'] . ' mc)';
            $pln[]      = $vPln  == 0 ? null : $vPln;
            $g11[]      = $vG11  == 0 ? null : $vG11;
            $g12r[]     = $vG12r == 0 ? null : $vG12r;
            $pln_lbl[]  = $vPln  == 0 ? '' : (string)(int)round($vPln);
            $g11_lbl[]  = $vG11  == 0 ? '' : (string)(int)round($vG11);
            $g12r_lbl[] = $vG12r == 0 ? '' : (string)(int)round($vG12r);
        }
        $db->select_db('tymos');
    }

} else { // yearly
    $title = 'Koszt · rok · Pstryk / G11 / G12r';
    if ($db) {
        $db->select_db('tymos');
        $res = $db->query("
            SELECT DATE_FORMAT(ts, '%Y-%m') AS m,
                   ROUND(SUM(COALESCE(cost_full_pstryk, 0)), 4) AS wszystko_pln,
                   ROUND(SUM(COALESCE(cost_full_g11,    0)), 4) AS wszystko_g11,
                   ROUND(SUM(COALESCE(cost_full_g12r_grz, cost_full_g12r, 0)), 4) AS wszystko_g12r
            FROM energa
            WHERE ts >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01 00:00:00')
            GROUP BY DATE_FORMAT(ts, '%Y-%m')
            ORDER BY m ASC
        ");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        foreach ($rows as $i => $r) {
            $last   = ($i === count($rows) - 1);
            $mo     = substr($r['m'], 5, 2);
            $cats[] = $months_pl[$mo] ?? $mo;
            $vPln  = ($last && (float)$r['wszystko_pln']  == 0) ? null : (float)$r['wszystko_pln'];
            $vG11  = ($last && (float)$r['wszystko_g11']  == 0) ? null : (float)$r['wszystko_g11'];
            $vG12r = ($last && (float)$r['wszystko_g12r'] == 0) ? null : (float)$r['wszystko_g12r'];
            $pln[]      = $vPln;
            $g11[]      = $vG11;
            $g12r[]     = $vG12r;
            $pln_lbl[]  = $vPln  !== null ? (string)(int)round($vPln)  : '';
            $g11_lbl[]  = $vG11  !== null ? (string)(int)round($vG11)  : '';
            $g12r_lbl[] = $vG12r !== null ? (string)(int)round($vG12r) : '';
        }
        $db->select_db('tymos');
    }
}
?>
<div class="card-title"><?= $title ?></div>
<?php if ($view === 'yearly' || $view === 'peryear'): ?>
<?= chart_render([
    'width'      => 800,
    'bar_label_size' => 10,
    'categories' => $cats,
    'yaxis'      => [['color' => '#9a9a9a', 'decimals' => 0, 'oy_label_below' => ['text' => 'PLN', 'color' => '#9a9a9a']]],
    'series'     => [
        ['type'=>'bar','data'=>$pln,  'color'=>'#42a5f5','grouped'=>true,'labels'=>$pln_lbl, 'label_color'=>'#42a5f5'],
        ['type'=>'bar','data'=>$g11,  'color'=>'#9e9e9e','grouped'=>true,'labels'=>$g11_lbl, 'label_color'=>'#9e9e9e'],
        ['type'=>'bar','data'=>$g12r, 'color'=>'#66bb6a','grouped'=>true,'labels'=>$g12r_lbl,'label_color'=>'#66bb6a'],
    ],
    'legend' => [
        ['color' => '#42a5f5', 'label' => 'Pstryk G11f',       'type' => 'area'],
        ['color' => '#9e9e9e', 'label' => 'Energa-Obrót G11',  'type' => 'area'],
        ['color' => '#66bb6a', 'label' => 'Energa-Obrót G12r', 'type' => 'area'],
    ],
]) ?>
<div style="font-size:11px;margin-top:2px;">
<span style="color:#42a5f5;">G11f: zawiera ryczałt+mocowa+abonament 55,51+24,05+0,74+VAT=98,77 PLN/mc</span><br>
<span style="color:#9e9e9e;">G11: zawiera stałą sieciową+mocową+abonament 11,77+24,05+0,74+VAT=44,97 PLN/mc; 1,0992 zł/kWh</span><br>
<span style="color:#66bb6a;">G12r: zawiera stałą sieciową+mocową+abonament 20,17+24,05+0,74+VAT=55,30 PLN/mc; I strefa 1,3273 (dni robocze 7-13, 16-22), II strefa 0,5391 (13-16, 22-7 oraz całe soboty, niedziele i święta); energia grzałek bufora zawsze po II strefie (pod G12r grzałyby w niej), dane grzałek od 03.2026. Stawki z taryf Energa 2026.</span>
</div>
<?php else: ?>
<?= chart_render([
    'width'       => 800,
    'categories'  => $cats,
    'annotations' => [['x' => '1', 'color' => '#888888', 'dash' => 5]],
    'yaxis'       => [['color' => '#9a9a9a', 'decimals' => 2, 'oy_label_below' => ['text' => 'PLN', 'color' => '#9a9a9a']]],
    'series'      => [
        ['type'=>'line','data'=>$pln,  'color'=>'#42a5f5','grouped'=>true,'null_gap'=>true],
        ['type'=>'line','data'=>$g11,  'color'=>'#9e9e9e','grouped'=>true,'null_gap'=>true],
        ['type'=>'line','data'=>$g12r, 'color'=>'#66bb6a','grouped'=>true,'null_gap'=>true],
    ],
    'legend' => [
        ['color' => '#42a5f5', 'label' => 'Pstryk G11f',       'type' => 'line'],
        ['color' => '#9e9e9e', 'label' => 'Energa-Obrót G11',  'type' => 'line'],
        ['color' => '#66bb6a', 'label' => 'Energa-Obrót G12r', 'type' => 'line'],
    ],
]) ?>
<div style="font-size:11px;margin-top:2px;">
<span style="color:#42a5f5;">G11f: zawiera ryczałt+mocowa+abonament 55,51+24,05+0,74+VAT=98,77 PLN/mc</span><br>
<span style="color:#9e9e9e;">G11: zawiera stałą sieciową+mocową+abonament 11,77+24,05+0,74+VAT=44,97 PLN/mc; 1,0992 zł/kWh</span><br>
<span style="color:#66bb6a;">G12r: zawiera stałą sieciową+mocową+abonament 20,17+24,05+0,74+VAT=55,30 PLN/mc; I strefa 1,3273 (dni robocze 7-13, 16-22), II strefa 0,5391 (13-16, 22-7 oraz całe soboty, niedziele i święta); energia grzałek bufora zawsze po II strefie (pod G12r grzałyby w niej), dane grzałek od 03.2026. Stawki z taryf Energa 2026.</span>
</div>
<?php endif; ?>
