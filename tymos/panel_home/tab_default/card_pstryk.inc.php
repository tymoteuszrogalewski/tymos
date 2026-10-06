<?php
require_once __DIR__ . '/../../inc/chart.inc.php';
require_once __DIR__ . '/../../inc/power_limits.inc.php';

// --- parametr daty (obsługa nawigacji dzień-po-dniu przez swipe) ---
$today     = date('Y-m-d');
$dateParam = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']))
             ? $_GET['date'] : $today;
if ($dateParam > $today) $dateParam = $today; // nie w przyszłość
$isToday   = ($dateParam === $today);
$curHour   = $isToday ? (int)date('G') : -1; // -1 = żadna godzina nie jest "bieżąca"

// --- label daty (tylko dla dni historycznych) ---
$dateLabel = '';
if (!$isToday) {
    $daysDiff = (int)round((strtotime($today) - strtotime($dateParam)) / 86400);
    if ($daysDiff === 1)      $rel = 'Wczoraj';
    elseif ($daysDiff === 2)  $rel = 'Przedwczoraj';
    else                      $rel = $daysDiff . ' dni temu';
    $dateLabel = htmlspecialchars($rel) . ' · ' . date('d.m', strtotime($dateParam));
}

// --- dane z DB ---
$prices    = array_fill(0, 24, null);
$usages    = array_fill(0, 24, null);
$tomorrow  = array_fill(0, 24, null);
$pstryk_est = array_fill(0, 24, null);
$pLabels   = array_fill(0, 24, '');

$pellet_prog = 0.42;
if ($db) {
    $db->select_db('tymos');
    $sRes = $db->query("SELECT v FROM settings WHERE k='pellet_prog_grzalki' LIMIT 1");
    if ($sRes && ($sRow = $sRes->fetch_assoc())) $pellet_prog = (float)$sRow['v'];

    // ceny (PLN/kWh)
    $res = $db->query("
        SELECT HOUR(ts) AS h, full_price_pstryk
        FROM energa
        WHERE DATE(ts) = '$dateParam'
        ORDER BY h
    ");
    while ($row = $res->fetch_assoc()) {
        $h = (int)$row['h'];
        $pv = (float)$row['full_price_pstryk'];
        $prices[$h] = ($h === $curHour) ? ['v' => $pv, 'mark' => true] : $pv;
        if ($h === $curHour) $pLabels[$h] = number_format($pv, 2, '.', '');
    }

    // estymacja Pstryk z TGE Fixing I — na następny dzień (wyprzedza tomorrow/pstryk)
    $res = $db->query("
        SELECT HOUR(ts) AS h, full_price_pstryk_est
        FROM energa
        WHERE DATE(ts) = DATE_ADD('$dateParam', INTERVAL 1 DAY)
        ORDER BY h
    ");
    while ($row = $res->fetch_assoc()) {
        $v = $row['full_price_pstryk_est'] !== null ? (float)$row['full_price_pstryk_est'] : null;
        $pstryk_est[(int)$row['h']] = $v;
    }

    // ceny następnego dnia (dla historycznych: jutro = znane ceny; dla dziś: jutro = prognoza)
    $res = $db->query("
        SELECT HOUR(ts) AS h, full_price_pstryk
        FROM energa
        WHERE DATE(ts) = DATE_ADD('$dateParam', INTERVAL 1 DAY)
        ORDER BY h
    ");
    while ($row = $res->fetch_assoc()) {
        $v = $row['full_price_pstryk'] !== null ? (float)$row['full_price_pstryk'] : null;
        $tomorrow[(int)$row['h']] = ($v !== null && $v != 0.0) ? $v : null;
    }

    // zużycie i koszt
    $res = $db->query("
        SELECT HOUR(ts) AS h, kwh_pstryk, cost_full_pstryk
        FROM energa
        WHERE DATE(ts) = '$dateParam'
        ORDER BY h
    ");
    while ($row = $res->fetch_assoc()) {
        $h = (int)$row['h'];
        $kwhVal  = $row['kwh_pstryk']       !== null ? (float)$row['kwh_pstryk']       : null;
        if ($h === $curHour && $kwhVal  === 0.0) $kwhVal  = null;
        $usages[$h] = $kwhVal;
    }

    // stan zaworu zmywarki (id=15) — z devices.last_state (tabela device15 nie trzyma kolumny state)
    $vRes = $db->query("SELECT last_state FROM devices WHERE id=15");
    $vLs = ($vRes && ($vRow = $vRes->fetch_assoc())) ? (json_decode($vRow['last_state'] ?? '{}', true) ?: []) : [];
    $zawor_state = isset($vLs['state']) ? strtolower($vLs['state']) : null;
    // open = zmywarka bierze z bufora (gorace, czerwone), close = z sieci (zimne, niebieskie)
    $zawor_color = ($zawor_state === 'open') ? '#ff4444' : (($zawor_state === 'close') ? '#42a5f5' : null);
    $zawor_icon  = $zawor_color
        ? '<svg width="12" height="14" viewBox="0 0 24 28" style="vertical-align:-2px;margin-left:4px"><path d="M12 2C7 9 4 14 4 19a8 8 0 0 0 16 0c0-5-3-10-8-17z" fill="' . $zawor_color . '"/></svg>'
        : '';
}

// --- PASEK MOCY U GORY (97,5-100% wysokosci) ---
// Godzinowe MAX obciazenia najgorszej fazy, kolor wg progow z inc/power_limits.inc.php.
//
// Kolor per godzina wymusza BARY (kolor linii jest jeden na cala serie), a bar w chart.inc.php
// zawsze rosnie od zera — wiec pasek stoi na STACKU: niewidzialny cokol 0-97,5 (color_alpha=127)
// i na nim kreska w kolorze progu. GRUBOSC ZALEZY OD PROGU (patrz inc/power_limits): zielony 2
// jednostka, kazdy kolejny prog o 1 wiecej, magenta 5. Dolna krawedz stoi w miejscu (95), rosnie
// gorna — przekroczenie widac wiec takze bez rozrozniania odcieni. Wszystkie serie w tej samej grupie 'pwr', zeby liczyly sie
// jako JEDNA pozycja i zajmowaly pelna szerokosc kolumny godziny.
// (Wersja na liniach dawala czarne dziury na granicach kolorow i wezsza kreske dla samotnej godziny.)
$pwrHourly = pwr_worst_phase_hourly($db, $dateParam);
$pwrBase   = array_fill(0, 24, null);
$pwrBands  = [];   // kolor => tablica 24 x (2.5|null)
foreach ($pwrHourly as $h => $w) {
    if ($w === null) continue;
    $pwrBase[$h] = PWR_BAND_BASE;                       // wspolna dolna krawedz — kreska rosnie W GORE
    $c = pwr_tier_color($w);
    if (!isset($pwrBands[$c])) $pwrBands[$c] = array_fill(0, 24, null);
    $pwrBands[$c][$h] = pwr_tier_thick($w);
}
$pwrSeries = [
    ['type' => 'bar', 'data' => $pwrBase, 'yaxis' => 2, 'stack' => true, 'group' => 'pwr',
     'color' => '#000000', 'color_alpha' => 127],
];
foreach ($pwrBands as $color => $data) {
    $pwrSeries[] = ['type' => 'bar', 'data' => $data, 'yaxis' => 2, 'stack' => true, 'group' => 'pwr',
                    'color' => $color];
}

// Sufit osi cen podniesiony o 18% (bylo 10%): slupki nie wchodza pod pasek mocy (2,5%), a etykieta
// nad slupkiem biezacej godziny (~22 px) miesci sie nawet przy najdrozszej godzinie, bo gorny margines zmalal do 14.
$priceVals = [];
foreach (array_merge($prices, $tomorrow, $pstryk_est) as $v) {
    if (is_array($v)) $v = $v['v'] ?? null;
    if ($v !== null) $priceVals[] = (float)$v;
}
$priceMax = count($priceVals) ? max($priceVals) * 1.18 : null;

// --- wykres ---
$chart = chart_render([
    'width'       => 680,
    'height'      => 200,   // 2026-08-29: -1/3 na probe, wykres byl wyzszy niz potrzeba
    'bg'          => 'transparent',
    'grid_color'  => '#ffffff',
    'no_grid'     => true,
    'label_color' => '#d0d0d0',
    'font_size'   => 8,
    // top 36 -> 14 (2026-09-04): opisy osi zeszly na dol, gora nie ma czego trzymac. Etykieta
    // nad slupkiem biezacej godziny miesci sie dzieki sufitowi osi +18% (nizej, $priceMax).
    'margin'      => ['top' => 14, 'right' => 46, 'bottom' => 28, 'left' => 40],
    'categories'  => array_map(fn($h) => (string)$h, range(0, 23)),
    'yaxis'       => [
        // Opisy osi NA DOLE (2026-09-04): lewa PLN zielona, prawa kWh niebieska.
        ['color' => '#81c784', 'decimals' => 2, 'grids' => 10, 'max' => $priceMax,
         'oy_label_below' => ['text' => 'PLN', 'color' => '#81c784']],
        // Prawa OY = kWh (niebieska). Czerwona seria kosztu PLN wyrzucona 2026-09-04.
        ['color' => '#2288ee', 'decimals' => 2, 'side' => 'right', 'grids' => 6,
         'oy_label_below' => ['text' => 'kWh', 'color' => '#2288ee']],
        ['min' => 0, 'max' => 100, 'side' => 'right', 'hidden' => true],
    ],
    'series' => array_merge($pwrSeries, [
        [
            'data'         => $prices,
            'type'         => 'bar',
            'yaxis'        => 0,
            'color'        => '#81c784',
            'color_ranges' => [
                ['max' => 0,    'color' => '#9c27b0'],
                ['max' => 0.40, 'color' => '#ffffff'],
                ['max' => $pellet_prog, 'color' => '#b0e8b0'],
                ['max' => 1.10, 'color' => '#6abf6a'],
                ['max' => 1.40, 'color' => '#ff9800'],
                ['color' => '#f44336'],
            ],
            'mark'        => ['color' => '#007bff'],
            'labels'      => $pLabels,
            'label_color' => '#ffffff',
        ],
        [
            'data'       => $usages,
            'type'       => 'line',
            'yaxis'      => 1,
            'color'      => '#2288ee',
            'line_width' => 1,
            'dash'       => 4,
            'null_gap'   => true,
        ],
        [
            'data'       => $tomorrow,
            'type'       => 'line',
            'yaxis'      => 0,
            'color'      => '#eeeeee',
            'null_gap'   => true,
        ],
        [
            'data'       => $pstryk_est,
            'type'       => 'line',
            'yaxis'      => 0,
            'color'      => '#eeeeee',
            'dash'       => 4,
            'null_gap'   => true,
        ],
    ]),
    'h_annotations' => [
        ['y' => 0.40, 'yaxis' => 0, 'color' => '#ffffff', 'dash' => 4, 'gap' => 6],
        ['y' => $pellet_prog, 'yaxis' => 0, 'color' => '#b0e8b0', 'dash' => 4, 'gap' => 6],
        ['y' => 1.10, 'yaxis' => 0, 'color' => '#ffc107', 'dash' => 4, 'gap' => 6],
    ],
]);
?>
<?php /* Tytul znika dla DZISIAJ — slowo "Pstryk" i ikona zaworu nic nie wnosily, a zabieraly
         wiersz pikseli. Przy swipe w przeszlosc zostaje sama data, bo bez niej nie wiadomo,
         ktory dzien sie oglada. */ ?>
<?php if ($dateLabel): ?><div class="card-title"><?= $dateLabel ?></div><?php endif; ?>
<div id="pstryk-toggle" style="cursor:pointer;"><?= $chart ?></div>
<script>
(function() {
  var tog = document.getElementById('pstryk-toggle');
  if (!tog) return;

  // Rozwiniecie (raporty pstryka) to OSOBNA karta `pstryk_load` w layoucie, nie div w srodku.
  // Powod: swipe i auto-refresh co 30 s wymieniaja CALA zawartosc tej karty, wiec div w srodku
  // znikalby przy kazdym przewinieciu dnia. Od 2026-09-04 karta `pstryk_load` pokazuje sie
  // w OKNIE nad panelem (winShow, cards.inc.js); w kolumnie stoi schowana.
  // Stan w window.pstrykLoadOpen czyta skrypt pstryk_load (schowa sie, gdy okno zamkniete).
  tog.onclick = function() {
      var el = document.getElementById('card_pstryk_load');
      if (!el) return;
      window.pstrykLoadOpen = true;
      winShow(el, function() {
          window.pstrykLoadOpen = false;
          // zatrzymaj cykle podkart i wyrzuc je z DOM — inaczej Pi dalej generowaloby PNG-i do ukrytego diva
          clearTimeout(cardTimers['pstryk_live']);
          clearTimeout(cardTimers['pstryk_day']);
          clearTimeout(cardTimers['pl_rep']);
          cardRunCleanup(el);
          $(el).find('.card-sw').empty();
      });
      loadCard('home', 'default', 'pstryk_load', 0, null, 'pstryk_load');
  };
})();
</script>
