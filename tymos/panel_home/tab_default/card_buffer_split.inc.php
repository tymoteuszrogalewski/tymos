<?php
require_once __DIR__ . '/../../inc/chart.inc.php';
require_once __DIR__ . '/_buffer_lib.inc.php';

/**
 * card_buffer_split.inc.php — heat drawn FROM the buffer, split by consumer:
 *   1. Pralka (washer, device19)   2. Zmywarka (dishwasher, device8)   3. CWU i inne (residual)
 *
 * Detekcja: cykle ze stat<ID> (power_avg per 5-min bucket), gap > 45 min = nowy cykl.
 *   Cykl kWh = SUM(power_avg)/12000 (W*300s/3.6e6). Z calkowania mocy (licznik Sonoff zamarza).
 *   Filtr realnego cyklu: > 0.05 kWh i >= 20 min.
 * Baseline samogrzania = P90 WSZYSTKICH cykli danego urzadzenia (globalny — per-miesiac sie zapada).
 * Cieplo z bufora na cykl (bh) = max(0, baseline - kWh_cyklu).
 *
 * WYCENA (2026-06-09, uczciwa):
 *   - "Bez bufora" (kontrfakt) = baseline_kWh * cena Pstryk Z GODZINY cyklu (samogrzanie w realnym czasie pracy).
 *   - "Z bufora" (realnie placone) = wlasny prad AGD (kWh_cyklu * cena z godziny) + woda z bufora (bh * MIN ceny z ostatnich 24h).
 *     Bufor grzeje sie w dolku cenowym, wiec woda z bufora wyceniana po min-24h (proxy, lekko naciagane ale na oko OK).
 *   - Oszczednosc = roznica slupkow = bh * (cena_w_trakcie - cena_min_24h).
 * CWU i inne = grzalki_kWh - pralka_bh - zmywarka_bh; koszt = udzial w realnym koszcie grzalek (energa_vs_pellet).
 */

$months_pl = ['01'=>'sty','02'=>'lut','03'=>'mar','04'=>'kwi','05'=>'maj','06'=>'cze',
              '07'=>'lip','08'=>'sie','09'=>'wrz','10'=>'paź','11'=>'lis','12'=>'gru'];

// _bs_cycles / _bs_p90 / _bs_price → _buffer_lib.inc.php (wspoldzielone z card_buffer_cwu_daily).

// Per appliance, per month: nobuf_pln (kontrfakt), real_pln (realnie placone), bh (kWh z bufora)
function _bs_appliance($db, int $devId, array $priceByHour, array $monthAvg): array {
    $cycles = _bs_cycles($db, $devId);
    $base = _bs_p90(array_column($cycles, 'kwh'));
    $m = [];
    foreach ($cycles as $c) {
        $mm = $c['m'];
        $cf = max($c['kwh'], $base);          // counterfactual kWh (samogrzanie wszystkiego)
        $bh = $cf - $c['kwh'];                 // cieplo wziete z bufora
        [$run, $min24] = _bs_price($priceByHour, $monthAvg, $c['start']);
        if (!isset($m[$mm])) $m[$mm] = ['nobuf_pln' => 0.0, 'real_pln' => 0.0, 'bh' => 0.0];
        $m[$mm]['nobuf_pln'] += $cf * $run;                       // bez bufora: wszystko grzane samo, po cenie z godziny
        $m[$mm]['real_pln']  += $c['kwh'] * $run + $bh * $min24;  // realnie: wlasny prad po cenie godziny + woda z bufora po min-24h
        $m[$mm]['bh']        += $bh;
    }
    return $m;
}

$pra = []; $zmy = []; $grz = []; $priceByHour = []; $monthAvg = [];
if ($db) {
    $db->select_db('tymos');

    $res = $db->query("SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:00:00') AS h, full_price_pstryk AS p
                       FROM energa WHERE full_price_pstryk IS NOT NULL
                         AND ts >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01 00:00:00')");
    if ($res) while ($r = $res->fetch_assoc()) $priceByHour[$r['h']] = (float)$r['p'];

    $res = $db->query("SELECT DATE_FORMAT(ts, '%Y-%m') AS m, AVG(full_price_pstryk) AS p
                       FROM energa WHERE full_price_pstryk IS NOT NULL
                         AND ts >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01 00:00:00') GROUP BY m");
    if ($res) while ($r = $res->fetch_assoc()) $monthAvg[$r['m']] = (float)$r['p'];

    $pra = _bs_appliance($db, 19, $priceByHour, $monthAvg);
    $zmy = _bs_appliance($db, 8,  $priceByHour, $monthAvg);

    $res = $db->query("SELECT DATE_FORMAT(ts, '%Y-%m') AS m, ROUND(SUM(kwh), 4) AS kwh, ROUND(SUM(cost_electric), 4) AS cost
                       FROM energa_vs_pellet WHERE kwh > 0
                         AND ts >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01 00:00:00') GROUP BY m");
    if ($res) while ($r = $res->fetch_assoc()) $grz[$r['m']] = ['kwh' => (float)$r['kwh'], 'cost' => (float)$r['cost']];
}

$mset = [];
foreach (array_keys($grz) as $m) $mset[$m] = true;
foreach (array_keys($pra) as $m) $mset[$m] = true;
foreach (array_keys($zmy) as $m) $mset[$m] = true;
$allM = array_keys($mset);
sort($allM);

$cats = [];
$pra_nobuf = []; $pra_real = []; $pra_bh = []; $pra_nlbl = []; $pra_rlbl = [];
$zmy_nobuf = []; $zmy_real = []; $zmy_bh = []; $zmy_nlbl = []; $zmy_rlbl = [];
$cwu_pln = []; $cwu_kwh = []; $cwu_lbl = [];

foreach ($allM as $m) {
    $cats[] = $months_pl[substr($m, 5, 2)] ?? substr($m, 5, 2);

    $pa = $pra[$m] ?? ['nobuf_pln'=>0,'real_pln'=>0,'bh'=>0];
    $pra_nobuf[] = round($pa['nobuf_pln'], 2);
    $pra_real[]  = round($pa['real_pln'], 2);
    $pra_bh[]    = round($pa['bh'], 2);
    $pra_nlbl[]  = $pa['nobuf_pln'] >= 1 ? (string)(int)round($pa['nobuf_pln']) : '';
    $pra_rlbl[]  = $pa['real_pln']  >= 1 ? (string)(int)round($pa['real_pln'])  : '';

    $za = $zmy[$m] ?? ['nobuf_pln'=>0,'real_pln'=>0,'bh'=>0];
    $zmy_nobuf[] = round($za['nobuf_pln'], 2);
    $zmy_real[]  = round($za['real_pln'], 2);
    $zmy_bh[]    = round($za['bh'], 2);
    $zmy_nlbl[]  = $za['nobuf_pln'] >= 1 ? (string)(int)round($za['nobuf_pln']) : '';
    $zmy_rlbl[]  = $za['real_pln']  >= 1 ? (string)(int)round($za['real_pln'])  : '';

    $G = $grz[$m]['kwh']  ?? 0.0;
    $Gc = $grz[$m]['cost'] ?? 0.0;
    $C = max(0.0, $G - $pa['bh'] - $za['bh']);
    $cwu_kwh[] = round($C, 1);
    $cwu_pln[] = $G > 0 ? round($Gc * $C / $G, 2) : 0.0;
    $cwu_lbl[] = $C > 1 ? (string)(int)round($G > 0 ? $Gc * $C / $G : 0) : '';
}

// Appliance chart: 2 PLN bars (bez/z bufora) on left axis + saved-kWh line on right axis. Gap miedzy slupkami = oszczednosc.
function _bs_chart_appliance(string $title, array $cats, array $nobuf, array $real, array $bh, array $nlbl, array $rlbl): string {
    return '<div class="card-title">' . $title . '</div>' . chart_render([
        'width' => 800, 'bar_label_size' => 10, 'categories' => $cats,
        'yaxis' => [
            ['color' => '#42a5f5', 'decimals' => 0, 'oy_label_below' => ['text' => 'PLN', 'color' => '#42a5f5']],
            ['color' => '#90a4ae', 'decimals' => 0, 'side' => 'right', 'oy_label_below' => ['text' => 'kWh', 'color' => '#90a4ae']],
        ],
        'series' => [
            ['type'=>'bar','data'=>$nobuf,'color'=>'#90caf9','grouped'=>true,'labels'=>$nlbl,'label_color'=>'#90caf9'],
            ['type'=>'bar','data'=>$real, 'color'=>'#42a5f5','grouped'=>true,'labels'=>$rlbl,'label_color'=>'#42a5f5'],
            ['type'=>'line','data'=>$bh,  'color'=>'#1565c0','yaxis'=>1,'null_gap'=>true],
        ],
        'legend' => [
            ['color'=>'#90caf9','label'=>'Bez bufora (czysty prąd, cena z godziny)','type'=>'area'],
            ['color'=>'#42a5f5','label'=>'Z bufora (realnie: prąd AGD + woda po min-24h)','type'=>'area'],
            ['color'=>'#1565c0','label'=>'Ciepło z bufora kWh','type'=>'line'],
        ],
    ]);
}

echo _bs_chart_appliance('Bufor · rok · Pralka — oszczędność (PLN / kWh)',   $cats, $pra_nobuf, $pra_real, $pra_bh, $pra_nlbl, $pra_rlbl);
echo _bs_chart_appliance('Bufor · rok · Zmywarka — oszczędność (PLN / kWh)', $cats, $zmy_nobuf, $zmy_real, $zmy_bh, $zmy_nlbl, $zmy_rlbl);

echo '<div class="card-title">Bufor · rok · CWU i inne (PLN / kWh)</div>' . chart_render([
    'width' => 800, 'bar_label_size' => 10, 'categories' => $cats,
    'yaxis' => [
        ['color' => '#42a5f5', 'decimals' => 0, 'oy_label_below' => ['text' => 'PLN', 'color' => '#42a5f5']],
        ['color' => '#90a4ae', 'decimals' => 0, 'side' => 'right', 'oy_label_below' => ['text' => 'kWh', 'color' => '#90a4ae']],
    ],
    'series' => [
        ['type'=>'bar','data'=>$cwu_pln,'color'=>'#42a5f5','labels'=>$cwu_lbl,'label_color'=>'#42a5f5'],
        ['type'=>'line','data'=>$cwu_kwh,'color'=>'#1565c0','yaxis'=>1,'null_gap'=>true],
    ],
    'legend' => [
        ['color'=>'#42a5f5','label'=>'CWU i inne — koszt grzałek','type'=>'area'],
        ['color'=>'#1565c0','label'=>'kWh','type'=>'line'],
    ],
]);
?>
<div style="font-size:11px;color:#888;padding:4px 8px;line-height:1.4">
  Pralka/zmywarka: „Bez bufora" = gdyby grzało wodę samo (cykle × koszt zimnego napustu) po cenie Pstryk z godziny pracy;
  „Z bufora" = realnie zapłacone = własny prąd AGD (po cenie z godziny) + woda z bufora wyceniona po MIN cenie z ostatnich 24h
  (bufor grzeje się w dołku — proxy). Różnica słupków = realna oszczędność. Linia = ciepło pobrane z bufora (kWh).
  CWU i inne = grzałki − pralka − zmywarka, wycena = udział w realnym koszcie grzałek.
</div>
