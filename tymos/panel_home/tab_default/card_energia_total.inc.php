<?php
require_once __DIR__ . '/../../config.inc.php';
require_once __DIR__ . '/../../inc/chart.inc.php';
require_once __DIR__ . '/../../inc/device_chart.inc.php';

$months_pl = ['01'=>'sty','02'=>'lut','03'=>'mar','04'=>'kwi','05'=>'maj','06'=>'cze',
              '07'=>'lip','08'=>'sie','09'=>'wrz','10'=>'paź','11'=>'lis','12'=>'gru'];

$G11_RATE = 1.0992;   // Energa 2026: 0,6172 brutto + 1,23 x (0,3485+0,0331+0,0073+0,0030), zweryfikowane 2026-09-09
$abo_g11f_premium = (55.51 - 11.70) * 1.23;  // brutto/mc — doklejka do grzalek (Pstryk) w scenariuszu A
$abo_g11_full     = (11.70 + 24.05 + 0.74) * 1.23;  // ~44.88 brutto/mc — pelne abo G11 do scen B

$db->select_db('tymos');

// Total zuzycie + koszt domu per miesiac (do proporcjonalnego rozdzialu premium G11f
// oraz do linii Pellet+Pstryk = pellet_faktyczny + caly_dom_pstryk)
$dom_kwh_by_month = []; $dom_cost_by_month = [];
$res = $db->query("
    SELECT DATE_FORMAT(ts, '%Y-%m') AS m,
           SUM(kwh_pstryk) AS dom_kwh,
           SUM(cost_full_pstryk) AS dom_cost
    FROM energa
    WHERE ts >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01')
    GROUP BY DATE_FORMAT(ts, '%Y-%m')
");
while ($r = $res->fetch_assoc()) {
    $dom_kwh_by_month[$r['m']]  = (float)$r['dom_kwh'];
    $dom_cost_by_month[$r['m']] = (float)$r['dom_cost'];
}
// Lokalna ekstrapolowana wersja dom_kwh tylko do abo_share (mianownik proporcji).
// Reszta uzywa oryginalu — bo kwh_dom_bez_gz, cost_dom_g11 itp. liczymy "do teraz",
// a niespojnie z grzalkami_kwh (te nie sa ekstrapolowane) dawaloby ~1000 PLN dla 1 maja.
$dom_kwh_for_abo = $dom_kwh_by_month;
$cur_m = date('Y-m');
if (isset($dom_kwh_for_abo[$cur_m])) {
    $dp = (int)date('j'); $dim = (int)date('t');
    if ($dp > 0) $dom_kwh_for_abo[$cur_m] = $dom_kwh_for_abo[$cur_m] * $dim / $dp;
}

// Pellet faktyczny per miesiac
$pellet = [];
$res = $db->query("
    SELECT DATE_FORMAT(ts, '%Y-%m') AS m, ROUND(SUM(cost), 1) AS cost
    FROM pellet
    WHERE ts >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01')
    GROUP BY DATE_FORMAT(ts, '%Y-%m')
");
while ($r = $res->fetch_assoc()) $pellet[$r['m']] = (float)$r['cost'];

// SZACUNEK PELLETU — wg biezacej ceny SKLEPOWEJ, nie ceny posiadanego zapasu.
// cost_pellet jest policzony dla PELLET_CENA_BAZOWA; sprawnosc pieca nie zalezy od
// ceny paliwa, wiec skalowanie jest liniowe. Linia `$pellet` wyzej to historia
// faktyczna z tabeli `pellet` i jej NIE skalujemy.
$rRy = $db->query("SELECT v FROM settings WHERE k='pellet_cena_rynek' LIMIT 1");
$pellet_rynek = $rRy && ($row = $rRy->fetch_assoc()) ? (float)$row['v'] : 2450;
$pellet_mult  = $pellet_rynek / PELLET_CENA_BAZOWA;

// Pellet equivalent grzania (z energa_vs_pellet) - CO+CWU
$heating_pellet_eq = [];
$res = $db->query("
    SELECT DATE_FORMAT(ts, '%Y-%m') AS m, ROUND(SUM(cost_pellet), 1) AS cp
    FROM energa_vs_pellet
    WHERE ts >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01')
    GROUP BY DATE_FORMAT(ts, '%Y-%m')
");
while ($r = $res->fetch_assoc()) $heating_pellet_eq[$r['m']] = (float)$r['cp'] * $pellet_mult;

// Koszty grzalek z energa_vs_pellet (z doklejka premium G11f w scenariuszu A)
$dev_pstryk = []; $dev_g11 = []; $grzalki_kwh_by_month = []; $grzalki_cost_pstryk_raw = [];
$res = $db->query("
    SELECT DATE_FORMAT(ts, '%Y-%m') AS m,
           ROUND(SUM(cost_electric), 2) AS cost_pstryk,
           ROUND(SUM(kwh * {$G11_RATE}), 2) AS cost_g11,
           SUM(kwh) AS kwh
    FROM energa_vs_pellet WHERE kwh > 0
    GROUP BY DATE_FORMAT(ts, '%Y-%m')
");
while ($r = $res->fetch_assoc()) {
    $kwh_gz = (float)$r['kwh'];
    $grzalki_kwh_by_month[$r['m']]   = $kwh_gz;
    $grzalki_cost_pstryk_raw[$r['m']] = (float)$r['cost_pstryk'];  // krancowy koszt grzalek (bez abo_share)
    $dom_kwh = $dom_kwh_for_abo[$r['m']] ?? 0;
    $abo_share = ($dom_kwh > 0) ? $abo_g11f_premium * ($kwh_gz / $dom_kwh) : 0;
    $dev_pstryk[$r['m']]['grzalki'] = (float)$r['cost_pstryk'] + $abo_share;
    $dev_g11[$r['m']]['grzalki']    = (float)$r['cost_g11'];
}
// Koszty pralki, zmywarki i rekuperatora z stat
foreach ([19 => 'pralka', 8 => 'zmywarka', 18 => 'reku'] as $did => $dname) {
    $stbl = "stat{$did}";
    $pcol = _stat_power_col($db, $stbl);
    $res = $db->query("
        SELECT DATE_FORMAT(h.hour_ts, '%Y-%m') AS m,
               ROUND(SUM(h.avg_w / 1000 * COALESCE(ep.full_price_pstryk, 0)), 2) AS cost_pstryk,
               ROUND(SUM(h.avg_w / 1000 * {$G11_RATE}), 2) AS cost_g11
        FROM (SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:00:00') AS hour_ts, AVG({$pcol}) AS avg_w
              FROM `{$stbl}` WHERE {$pcol} IS NOT NULL GROUP BY DATE_FORMAT(ts, '%Y-%m-%d %H:00:00')) h
        LEFT JOIN energa ep ON ep.ts = h.hour_ts
        GROUP BY DATE_FORMAT(h.hour_ts, '%Y-%m')
    ");
    if ($res) while ($r = $res->fetch_assoc()) {
        $dev_pstryk[$r['m']][$dname] = (float)$r['cost_pstryk'];
        $dev_g11[$r['m']][$dname]    = (float)$r['cost_g11'];
    }
}

// Zbierz wszystkie miesiace
$allMonths = array_unique(array_merge(array_keys($pellet), array_keys($dev_pstryk)));
sort($allMonths);

$cats = [];
// Scenariusz A: Pstryk (bar group 1). Labele per-bar — label przyklejamy do najwyzszego widocznego
// segmentu w danym miesiacu (bo w miesiacu bez pelletu bar pelletu nie istnieje → labelka zginela).
$d_grzalki = []; $d_pralka = []; $d_zmywarka = []; $d_pellet_a = []; $d_reku_a = [];
$lbl_a_pellet = []; $lbl_a_zmywarka = []; $lbl_a_pralka = []; $lbl_a_grzalki = []; $lbl_a_reku = [];
$line_total_a = []; $lbl_line_a = [];
// Scenariusz B: G11+pellet (bar group 2)
$d_pellet_eq = []; $d_pralka_g11 = []; $d_zmywarka_g11 = []; $d_reku_b = [];
$lbl_b_zmywarka = []; $lbl_b_pralka = []; $lbl_b_pellet = []; $lbl_b_reku = [];
$line_total_b = []; $lbl_line_b = [];
$line_total_c = []; $lbl_line_c = [];
$sum_a = 0; $sum_b = 0;

foreach ($allMonths as $m) {
    $mo = substr($m, 5, 2);
    $cats[] = $months_pl[$mo] ?? $mo;

    $pe  = $pellet[$m] ?? 0;
    $hpe = $heating_pellet_eq[$m] ?? 0;
    $gz  = $dev_pstryk[$m]['grzalki'] ?? 0;
    $pr  = $dev_pstryk[$m]['pralka'] ?? 0;
    $zm  = $dev_pstryk[$m]['zmywarka'] ?? 0;

    $pr_g11 = $dev_g11[$m]['pralka'] ?? 0;
    $zm_g11 = $dev_g11[$m]['zmywarka'] ?? 0;

    // Reku — realny koszt z device 18 (gniazdko Sonoff), ta sama wartosc (Pstryk) w A i B
    $reku = $dev_pstryk[$m]['reku'] ?? 0;

    $has_device_data = ($gz > 0 || $pr > 0 || $zm > 0 || $reku > 0);

    // --- Scenariusz A: Pstryk ---
    $d_grzalki[]  = $gz > 0 ? $gz : null;
    $d_pralka[]   = $pr > 0 ? $pr : null;
    $d_zmywarka[] = $zm > 0 ? $zm : null;
    $d_pellet_a[] = $pe > 0 ? $pe : null;
    $d_reku_a[]   = $reku > 0 ? $reku : null;

    $total_a = $gz + $pr + $zm + $pe + $reku;
    $lblA = $total_a > 0 ? (int)round($total_a) : '';
    // Top stack order (bottom→top): grzalki, pralka, zmywarka, pellet, reku.
    // Label trafia na NAJWYZSZY widoczny segment.
    $lbl_a_reku[]     = ($reku > 0) ? $lblA : '';
    $lbl_a_pellet[]   = ($reku == 0 && $pe > 0) ? $lblA : '';
    $lbl_a_zmywarka[] = ($reku == 0 && $pe == 0 && $zm > 0) ? $lblA : '';
    $lbl_a_pralka[]   = ($reku == 0 && $pe == 0 && $zm == 0 && $pr > 0) ? $lblA : '';
    $lbl_a_grzalki[]  = ($reku == 0 && $pe == 0 && $zm == 0 && $pr == 0 && $gz > 0) ? $lblA : '';
    // Linia: pellet faktyczny + caly dom Pstryk (cost_full_pstryk z licznika)
    $dom_cost = $dom_cost_by_month[$m] ?? 0;
    $line_val = $pe + $dom_cost;
    $line_total_a[] = $line_val > 0 ? $line_val : null;
    $lbl_line_a[]   = $line_val > 0 ? (int)round($line_val) : '';
    $sum_a += $total_a;

    // Linia B: pellet (eq + faktyczny) + caly dom na G11 (bez kwh grzalek bo w scen B nie ma)
    $kwh_grzalki_msc = $grzalki_kwh_by_month[$m] ?? 0;
    $kwh_dom_msc     = $dom_kwh_by_month[$m] ?? 0;
    $kwh_dom_bez_gz  = max(0, $kwh_dom_msc - $kwh_grzalki_msc);
    $cost_dom_g11    = ($kwh_dom_bez_gz > 0) ? ($kwh_dom_bez_gz * $G11_RATE + $abo_g11_full) : 0;

    // Linia C: pellet (eq + faktyczny) + caly dom na Pstryk (bez krancowego kosztu grzalek)
    // Pstryk abo G11f i mocowa zostaja (zaszyte juz w cost_full_pstryk), wiec
    // odejmujemy tylko krancowy koszt grzalek (cost_electric, czyli kwh*full_price).
    $dom_cost_msc        = $dom_cost_by_month[$m] ?? 0;
    $gz_cost_pstryk_raw  = $grzalki_cost_pstryk_raw[$m] ?? 0;
    $cost_dom_pstryk_bg  = max(0, $dom_cost_msc - $gz_cost_pstryk_raw);

    // --- Scenariusz B: G11 + pellet full ---
    if ($has_device_data) {
        // Pellet equivalent (CO+CWU) zamiast grzalek
        $b_pellet_eq = $hpe;
        // Pralka i zmywarka: te same kWh co Pstryk, ale wycenione na G11
        // (bufor by dalej istnial, grzany pelletem — te same kWh)
        $b_pralka    = $pr_g11;
        $b_zmywarka  = $zm_g11;
        // Pellet faktyczny (to co spalil obok grzalek)
        $b_pellet_f  = $pe;

        $b_pellet_total = $b_pellet_eq + $b_pellet_f;
        $line_val_b = $b_pellet_total + $cost_dom_g11;
        $line_total_b[] = $line_val_b > 0 ? $line_val_b : null;
        $lbl_line_b[]   = $line_val_b > 0 ? (int)round($line_val_b) : '';
        $line_val_c = $b_pellet_total + $cost_dom_pstryk_bg;
        $line_total_c[] = $line_val_c > 0 ? $line_val_c : null;
        $lbl_line_c[]   = $line_val_c > 0 ? (int)round($line_val_c) : '';
        $d_pellet_eq[]    = $b_pellet_total > 0 ? $b_pellet_total : null;
        $d_pralka_g11[]   = $b_pralka > 0 ? $b_pralka : null;
        $d_zmywarka_g11[] = $b_zmywarka > 0 ? $b_zmywarka : null;
        $d_reku_b[]       = $reku > 0 ? $reku : null;

        $total_b = $b_pellet_total + $b_pralka + $b_zmywarka + $reku;
        $lblB = $total_b > 0 ? (int)round($total_b) : '';
        // Stack B (bottom-up): pellet_eq -> pralka_g11 -> zmywarka_g11 -> reku. Top visible dostaje label.
        $lbl_b_reku[]     = ($reku > 0) ? $lblB : '';
        $lbl_b_zmywarka[] = ($reku == 0 && $b_zmywarka > 0) ? $lblB : '';
        $lbl_b_pralka[]   = ($reku == 0 && $b_zmywarka == 0 && $b_pralka > 0) ? $lblB : '';
        $lbl_b_pellet[]   = ($reku == 0 && $b_zmywarka == 0 && $b_pralka == 0 && $b_pellet_total > 0) ? $lblB : '';
        $sum_b += $total_b;
    } else {
        $d_pellet_eq[]    = $pe > 0 ? $pe : null;
        $d_pralka_g11[]   = null;
        $d_zmywarka_g11[] = null;
        $d_reku_b[]       = null;
        $lbl_b_zmywarka[] = '';
        $lbl_b_pralka[]   = '';
        $lbl_b_reku[]     = '';
        $lbl_b_pellet[]   = $pe > 0 ? (int)round($pe) : '';
        $line_val_b = $pe + $cost_dom_g11;
        $line_total_b[] = $line_val_b > 0 ? $line_val_b : null;
        $lbl_line_b[]   = $line_val_b > 0 ? (int)round($line_val_b) : '';
        $line_val_c = $pe + $cost_dom_pstryk_bg;
        $line_total_c[] = $line_val_c > 0 ? $line_val_c : null;
        $lbl_line_c[]   = $line_val_c > 0 ? (int)round($line_val_c) : '';
        $sum_b += $pe;
    }
}

$savings = $sum_b - $sum_a;
$savColor = $savings >= 0 ? '#4caf50' : '#f44336';
$savSign = $savings >= 0 ? '+' : '';

$db->select_db('tymos');
?>
<div class="card-title">Ogrzewanie + AGD + Prąd: Bieżące vs Bufor pellet only + reszta prąd <span style="opacity:.6;font-size:.75em">(zawiera opłaty stałe)</span></div>
<?= chart_render([
    'width'      => 1600,
    'height'     => 750,
    'categories' => $cats,
    'yaxis'      => [['color' => '#9a9a9a', 'decimals' => 0, 'grids' => 12, 'oy_label_below' => ['text' => 'PLN', 'color' => '#9a9a9a']]],
    'series'     => [
        // Scenariusz A: Pstryk (niebieski bar). Label sum_a przyklejony do top-most widocznego segmentu.
        ['type' => 'bar', 'data' => $d_grzalki,  'color' => '#1565c0', 'stack' => true, 'group' => 'a', 'labels' => $lbl_a_grzalki,  'label_color' => '#9a9a9a'],
        ['type' => 'bar', 'data' => $d_pralka,   'color' => '#4caf50', 'stack' => true, 'group' => 'a', 'labels' => $lbl_a_pralka,   'label_color' => '#9a9a9a'],
        ['type' => 'bar', 'data' => $d_zmywarka, 'color' => '#9c27b0', 'stack' => true, 'group' => 'a', 'labels' => $lbl_a_zmywarka, 'label_color' => '#9a9a9a'],
        ['type' => 'bar', 'data' => $d_pellet_a, 'color' => '#ff6600', 'stack' => true, 'group' => 'a', 'labels' => $lbl_a_pellet,   'label_color' => '#9a9a9a'],
        ['type' => 'bar', 'data' => $d_reku_a,   'color' => '#b39ddb', 'stack' => true, 'group' => 'a', 'labels' => $lbl_a_reku,     'label_color' => '#9a9a9a'],
        // Scenariusz B: G11+pellet (szary bar). Label sum_b przyklejony do top-most widocznego.
        ['type' => 'bar', 'data' => $d_pellet_eq,    'color' => '#ff8a65', 'stack' => true, 'group' => 'b', 'labels' => $lbl_b_pellet,   'label_color' => '#9a9a9a'],
        ['type' => 'bar', 'data' => $d_pralka_g11,   'color' => '#bdbdbd', 'stack' => true, 'group' => 'b', 'labels' => $lbl_b_pralka,   'label_color' => '#9a9a9a'],
        ['type' => 'bar', 'data' => $d_zmywarka_g11, 'color' => '#757575', 'stack' => true, 'group' => 'b', 'labels' => $lbl_b_zmywarka, 'label_color' => '#9a9a9a'],
        ['type' => 'bar', 'data' => $d_reku_b,       'color' => '#b39ddb', 'stack' => true, 'group' => 'b', 'labels' => $lbl_b_reku,     'label_color' => '#9a9a9a'],
        // Linia sumy A: Pellet (faktyczny) + caly dom Pstryk
        // (bez x_frac — chart.inc.php sam wyrownuje do pierwszej grupy bar)
        ['type' => 'line', 'data' => $line_total_a,  'color' => '#f44336', 'yaxis' => 0, 'line_width' => 5, 'null_gap' => true, 'labels' => $lbl_line_a, 'label_color' => '#f44336'],
        // Linia sumy B: Pellet (szac+faktyczny) + caly dom G11 — biala dashed, x_frac=0.75
        ['type' => 'line', 'data' => $line_total_b,  'color' => '#ffffff', 'yaxis' => 0, 'line_width' => 5, 'null_gap' => true, 'labels' => $lbl_line_b, 'label_color' => '#ffffff', 'dash' => 8, 'gap' => 6, 'x_frac' => 0.75],
        // Linia sumy C: Pellet (szac+faktyczny) + caly dom Pstryk (bez grzalek) — czerwona dashed, x_frac=0.75
        ['type' => 'line', 'data' => $line_total_c,  'color' => '#f44336', 'yaxis' => 0, 'line_width' => 5, 'null_gap' => true, 'labels' => $lbl_line_c, 'label_color' => '#f44336', 'dash' => 8, 'gap' => 6, 'x_frac' => 0.75],
    ],
    'legend' => [
        ['color' => '#1565c0', 'label' => 'Grzałki (Pstryk)',    'type' => 'bar'],
        ['color' => '#4caf50', 'label' => 'Pralka (Pstryk)',     'type' => 'bar'],
        ['color' => '#9c27b0', 'label' => 'Zmywarka (Pstryk)',   'type' => 'bar'],
        ['color' => '#ff6600', 'label' => 'Pellet (faktyczny)',  'type' => 'bar'],
        ['color' => '#b39ddb', 'label' => 'Rekuperator',         'type' => 'bar'],
        ['color' => '#000000', 'label' => '',                    'type' => 'newline'],
        ['color' => '#ff8a65', 'label' => 'Szacowane Pellet · wg ' . number_format($pellet_rynek, 0, ',', ' ') . ' zł/t', 'type' => 'bar'],
        ['color' => '#bdbdbd', 'label' => 'Szacowane Pralka (G11)',     'type' => 'bar'],
        ['color' => '#757575', 'label' => 'Szacowane Zmywarka (G11)',   'type' => 'bar'],
        ['color' => '#000000', 'label' => '',                           'type' => 'newline'],
        ['color' => '#f44336', 'label' => 'Pellet + Pstryk(cała faktura)',           'type' => 'line', 'line_width' => 5],
        ['color' => '#f44336', 'label' => 'Szacowane Pellet + Pstryk(cała faktura)', 'type' => 'line', 'line_width' => 5, 'dash' => 8, 'gap' => 6],
        ['color' => '#ffffff', 'label' => 'Szacowane Pellet + G11(cała faktura)',    'type' => 'line', 'line_width' => 5, 'dash' => 8, 'gap' => 6],
    ],
]) ?>
