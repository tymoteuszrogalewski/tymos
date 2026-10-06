<?php
require_once __DIR__ . '/../../inc/chart.inc.php';

$daysBack = max(0, min(7, (int)($_GET['days_back'] ?? 0)));
$isToday  = ($daysBack === 0);
$today    = date('Y-m-d');

// Punkt odniesienia: dzien przesuniety o $daysBack, dane az do teraz
$refDate = date('Y-m-d', time() - $daysBack * 86400);
$refTs   = time();
$refSql  = date('Y-m-d H:i:s', $refTs);
$preHours = 8;
$totalHours = $preHours + 24 + 12; // 44h
$startTs  = strtotime($refDate . ' 00:00:00') - $preHours * 3600;
$startSql = date('Y-m-d H:i:s', $startTs);

// Tytuł
$title = 'Bufor AI';
if (!$isToday) {
    $dateParam = date('Y-m-d', $refTs);
    $diff = $daysBack;
    if ($diff === 1)      $rel = 'Wczoraj';
    elseif ($diff === 2)  $rel = 'Przedwczoraj';
    else                  $rel = $diff . ' dni temu';
    $title .= ' · ' . $rel . ' ' . date('d.m', $refTs);
}

$db->select_db('tymos');

// Prog grzalki z settings
$sRes2 = $db->query("SELECT v FROM settings WHERE k='pellet_prog_grzalki' LIMIT 1");
$prog_grzalki = ($sRes2 && ($sRow2 = $sRes2->fetch_assoc())) ? (float)$sRow2['v'] : 0.42;

// Zapytanie 1: bufor_temp — od północy dnia ref
//
// MAPOWANIE GRZALEK (potwierdzone 2026-08-17 na fakcie: dev3 stalo 0 W przez ~31 h z wywalonym STB,
// a temp gora 68,8 vs dol 53,4 przy pracujacym dev1):
//   bufor_ai.power1 = device3 = "Bufor Grzałka 1" = DOLNA
//   bufor_ai.power2 = device1 = "Bufor Grzałka 2" = GÓRNA
// Wczesniej legenda tego wykresu miala to ODWROTNIE. Kolory jak temperatury: gora czerwona, dol pomaranczowa.
// Geometria (user, 2026-08-17): "dol" = dziura na samym dole bufora, "gora" = dziura wyzej i w bok,
// realnie tylko ~15-20 cm wyzej. Czujniki: dev11 na dole bufora, dev12 u gory w tulei czujnikowej.
$res = $db->query("
    SELECT
        DATE(ts) AS d,
        HOUR(ts) AS h,
        FLOOR(MINUTE(ts) / 5) AS q,
        AVG(temp) AS temp,
        AVG(temp_dol) AS temp_dol,
        MAX(target_temp) AS target_temp,
        MAX(CASE WHEN decision = 1  THEN 1 ELSE 0 END) AS grzej_flag,
        MAX(CASE WHEN decision = -1 THEN 1 ELSE 0 END) AS czekaj_short_flag,
        MAX(CASE WHEN decision IN (-2, -3) THEN 1 ELSE 0 END) AS czekaj_long_flag,
        MAX(pralka)      AS pralka,
        MAX(zmywarka)    AS zmywarka,
        MAX(zmywarka_zawor) AS zmywarka_zawor,
        MAX(nagrzewnica) AS nagrzewnica,
        MAX(podlogowka)  AS podlogowka,
        MAX(prysznic)    AS prysznic,
        MAX(CASE WHEN power2 > 50 THEN 1 ELSE 0 END) AS grzalka_gora,
        MAX(CASE WHEN power1 > 50 THEN 1 ELSE 0 END) AS grzalka_dol
    FROM bufor_ai
    WHERE ts >= '$startSql'
      AND ts <= '$refSql'
    GROUP BY DATE(ts), HOUR(ts), FLOOR(MINUTE(ts) / 5)
    ORDER BY d ASC, h ASC, q ASC
");
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;

// Zapytanie 2: ceny — today + tomorrow (36h)
$endPriceSql = date('Y-m-d H:i:s', $startTs + $totalHours * 3600);
$pres = $db->query("
    SELECT DATE_FORMAT(ts, '%Y-%m-%d') AS d, HOUR(ts) AS h, full_price_pstryk AS price, continuous_tge AS cont
    FROM energa
    WHERE ts >= '$startSql'
      AND ts <  '$endPriceSql'
    ORDER BY ts ASC
");
$price_map = []; $cont_map = [];
while ($pr = $pres->fetch_assoc()) {
    $key = $pr['d'] . ' ' . (int)$pr['h'];
    $price_map[$key] = ($pr['price'] !== null) ? (float)$pr['price'] : null;
    $cont_map[$key]  = ($pr['cont']  !== null) ? (float)$pr['cont']  : null;
}

// Indeksuj bufor_ai po d/h/q
$ai_map = [];
foreach ($rows as $r) {
    $key = $r['d'] . ' ' . (int)$r['h'] . ' ' . (int)$r['q'];
    $ai_map[$key] = $r;
}

// Buduj pelna siatke: today 0-23 + 12h jutra = 36 godzin * 4 kwarty
$cats = []; $temp = []; $temp_dol = []; $target = []; $price = []; $cont = []; $prog_line = [];
$grzej = []; $czekaj_s = []; $czekaj_l = []; $nagr = []; $podl = []; $pral = []; $zmyw = []; $zmyw_zS = []; $zmyw_zD = []; $prysz = []; $grzGora = []; $grzDol = [];
$future = [];
$nowSlot = (int)((time() - $startTs) / 300);

$tz = new DateTimeZone(date_default_timezone_get());
$slot_dt = new DateTime(date('Y-m-d H:i:s', $startTs), $tz);

for ($slot = 0; $slot < $totalHours * 12; $slot++) {
    $fd = $slot_dt->format('Y-m-d');
    $fh = (int)$slot_dt->format('G');
    $fq = (int)floor((int)$slot_dt->format('i') / 5);
    $key = $fd . ' ' . $fh . ' ' . $fq;

    // Label: godzina przy q=0
    if ($fq === 0) {
        if ($fh === 0 && $fd === $refDate) {
            $cats[] = '0';  // polnoc dzis
        } elseif ($fh === 0 && $fd > $refDate) {
            $cats[] = '+0'; // polnoc jutro
        } else {
            $cats[] = (string)$fh;
        }
    } else {
        $cats[] = '';
    }

    if (isset($ai_map[$key])) {
        $r = $ai_map[$key];
        $temp[]     = round((float)$r['temp'], 1);
        $temp_dol[] = ($r['temp_dol'] !== null) ? round((float)$r['temp_dol'], 1) : null;
        $target[]   = ($r['target_temp'] > 0) ? (float)$r['target_temp'] : null;
        $grzej[]     = $r['grzej_flag']  ? 100.0 : null;
        $czekaj_s[]  = $r['czekaj_short_flag'] ? 100.0 : null;
        $czekaj_l[]  = $r['czekaj_long_flag'] ? 100.0 : null;
        $podl[]      = $r['podlogowka']  ? 5.0  : null;
        $nagr[]      = $r['nagrzewnica'] ? 10.0 : null;
        $pral[]      = $r['pralka']      ? 25.0 : null;
        $zmyw[]      = $r['zmywarka']    ? 35.0 : null;
        // Zawor: solid=HOT(OPEN), dashed=COLD(CLOSE) — na wysokosci zmywarki
        $zmyw_zS[]   = (isset($r['zmywarka_zawor']) && $r['zmywarka_zawor']) ? 35.0 : null;
        $zmyw_zD[]   = (isset($r['zmywarka_zawor']) && !$r['zmywarka_zawor']) ? 35.0 : null;
        $prysz[]     = $r['prysznic']    ? 45.0 : null;
        $grzGora[]   = $r['grzalka_gora'] ? 3.0 : null;
        $grzDol[]    = $r['grzalka_dol']  ? 2.0 : null;
    } else {
        $temp[]   = null; $temp_dol[] = null; $target[] = null;
        $grzej[]  = null; $czekaj_s[] = null; $czekaj_l[] = null; $nagr[] = null;
        $podl[]   = null; $pral[]   = null; $zmyw[] = null; $zmyw_zS[] = null; $zmyw_zD[] = null; $prysz[] = null;
        $grzGora[] = null; $grzDol[] = null;
    }

    $price[]     = $price_map[$fd . ' ' . $fh] ?? null;
    $cont[]      = $cont_map[$fd . ' ' . $fh] ?? null;
    $prog_line[] = $prog_grzalki;
    $future[]    = ($isToday && $slot >= $nowSlot) ? 100.0 : null;

    $slot_dt->modify('+5 minutes');
}

// Zakresy Y
$temp_vals  = array_filter(array_merge($temp, $temp_dol, $target), fn($v) => $v !== null);
$y0_min = count($temp_vals) ? (int)floor(min($temp_vals)) : 40;
$y0_max = count($temp_vals) ? (int)ceil(max($temp_vals))  : 80;

$price_vals = array_filter($price, fn($v) => $v !== null);
$y1_min = count($price_vals) ? round(min(min($price_vals), $prog_grzalki) - 0.15, 2) : 0.0;
$y1_max = count($price_vals) ? max($price_vals) : 2.0;

$db->select_db('tymos');
?>
<div class="card-title"><?= $title ?></div>
<?= chart_render([
    'width'        => 1600,
    'height'       => 750,
    'font_size_axis' => 24,
    'categories'   => $cats,
    'label_min_px' => 20,
    'annotations'  => [
        ['x' => '0',  'color' => '#ffffff', 'dash' => 4, 'gap' => 4],
        ['x' => '+0', 'color' => '#ffffff', 'dash' => 4, 'gap' => 4],
    ],
    'yaxis'        => [
        ['color' => '#f44336', 'decimals' => 0, 'grids' => 8, 'min' => $y0_min, 'max' => $y0_max, 'oy_label_below' => ['text' => '°C',  'color' => '#f44336']],
        ['color' => '#42a5f5', 'decimals' => 2, 'grids' => 8, 'side' => 'right', 'min' => $y1_min, 'max' => $y1_max, 'oy_label_below' => ['text' => 'PLN', 'color' => '#42a5f5']],
        ['min' => 0, 'max' => 100, 'side' => 'right', 'hidden' => true],
    ],
    'legend' => [
        ['color' => '#4caf50', 'label' => 'Grzej',       'type' => 'area', 'fill_opacity' => 0.29, 'border' => false],
        ['color' => '#e65100', 'label' => 'Czekaj',      'type' => 'area', 'fill_opacity' => 0.29, 'border' => false],
        ['color' => '#ffeb3b', 'label' => 'Czekaj (dlugo)', 'type' => 'area', 'fill_opacity' => 0.29, 'border' => false],
        ['color' => '#f44336', 'label' => 'Bufor Temp Góra', 'type' => 'line'],
        ['color' => '#ff9800', 'label' => 'Bufor Temp Dół', 'type' => 'line'],
        ['color' => '#f44336', 'label' => 'Target',      'type' => 'line_thick'],
        ['color' => '#42a5f5', 'label' => 'PLN/kWh',        'type' => 'line'],
        ['color' => '#9e9e9e', 'label' => 'TGE Continuous (estymata)', 'type' => 'line', 'line_width' => 1, 'dash' => 4, 'gap' => 4],
        ['color' => '#42a5f5', 'label' => 'Próg grzałki',   'type' => 'line'],
        ['color' => '#42a5f5', 'label' => 'Prysznic',    'type' => 'area', 'fill_opacity' => 0.37],
        ['color' => '#9c27b0', 'label' => 'Zmywarka',    'type' => 'area', 'fill_opacity' => 0.37, 'border' => false],
        ['color' => '#4caf50', 'label' => 'Pralka',      'type' => 'area', 'fill_opacity' => 0.37],
        ['color' => '#f44336', 'label' => 'Nagrzewnica', 'type' => 'area', 'fill_opacity' => 0.37],
        ['color' => '#e91e63', 'label' => 'Podłogówka',  'type' => 'area', 'fill_opacity' => 0.37],
        ['color' => '#ff9800', 'label' => 'Próg zaworu',   'type' => 'line'],
        ['color' => '#f44336', 'label' => 'Grzałka 2 (góra)',  'type' => 'line_thick'],
        ['color' => '#ff9800', 'label' => 'Grzałka 1 (dół)',  'type' => 'line_thick'],
    ],
    'series' => [
        ['type' => 'area', 'data' => $future, 'color' => '#333333', 'yaxis' => 2, 'fill_alpha' => 80, 'line_width' => 0],
        ['type' => 'bar', 'data' => $grzej,    'color' => '#4caf50', 'yaxis' => 2, 'color_alpha' => 90, 'bar_gap_pct' => 0],
        ['type' => 'bar', 'data' => $czekaj_s, 'color' => '#e65100', 'yaxis' => 2, 'color_alpha' => 90, 'bar_gap_pct' => 0],
        ['type' => 'bar', 'data' => $czekaj_l, 'color' => '#ffeb3b', 'yaxis' => 2, 'color_alpha' => 90, 'bar_gap_pct' => 0],
        ['type' => 'area', 'data' => $prysz,  'color' => '#42a5f5', 'yaxis' => 2, 'fill_alpha' => 80, 'line_width' => 5],
        ['type' => 'area', 'data' => $zmyw,   'color' => '#9c27b0', 'yaxis' => 2, 'fill_alpha' => 80, 'line_width' => 0],
        ['type' => 'area', 'data' => $pral,   'color' => '#4caf50', 'yaxis' => 2, 'fill_alpha' => 80, 'line_width' => 5],
        ['type' => 'area', 'data' => $nagr,   'color' => '#f44336', 'yaxis' => 2, 'fill_alpha' => 80],
        ['type' => 'area', 'data' => $podl,    'color' => '#e91e63', 'yaxis' => 2, 'fill_alpha' => 80],
        ['type' => 'line', 'data' => $zmyw_zS, 'color' => '#9c27b0', 'yaxis' => 2, 'line_width' => 8],
        ['type' => 'line', 'data' => $zmyw_zD, 'color' => '#9c27b0', 'yaxis' => 2, 'line_width' => 8, 'dash' => 6],
        ['type' => 'line', 'data' => $grzGora, 'color' => '#f44336', 'yaxis' => 2, 'line_width' => 6],
        ['type' => 'line', 'data' => $grzDol,  'color' => '#ff9800', 'yaxis' => 2, 'line_width' => 6],
        ['type' => 'line', 'data' => $target, 'color' => '#f44336', 'yaxis' => 0, 'line_width' => 8],
        ['type' => 'line', 'data' => $temp,     'color' => '#f44336', 'yaxis' => 0],
        ['type' => 'line', 'data' => $temp_dol, 'color' => '#ff9800', 'yaxis' => 0],
        ['type' => 'line', 'data' => $cont,      'color' => '#9e9e9e', 'yaxis' => 1, 'line_width' => 1, 'dash' => 4, 'gap' => 4, 'null_gap' => true],
        ['type' => 'line', 'data' => $price,     'color' => '#42a5f5', 'yaxis' => 1, 'color_alpha' => 35],
        ['type' => 'line', 'data' => $prog_line, 'color' => '#42a5f5', 'yaxis' => 1, 'line_width' => 1, 'dash' => 8],
        ['type' => 'line', 'data' => array_map(fn($v) => $v / 2, $prog_line), 'color' => '#ffffff', 'yaxis' => 1, 'line_width' => 1, 'dash' => 8],
        ['type' => 'line', 'data' => array_fill(0, count($cats), 0.40), 'color' => '#ff9800', 'yaxis' => 1, 'line_width' => 1, 'dash' => 8],
    ],
]) ?>
<details style="margin: 8px 0 0 0; background: rgba(255,255,255,0.035); border: 1px solid rgba(255,255,255,0.06); border-radius: 4px;">
  <summary style="cursor: pointer; padding: 8px 14px; font-size: 13px; opacity: 0.7; user-select: none; list-style: none;">
    <span style="display: inline-block; width: 10px; transition: transform 0.15s;">▶</span>
    Filozofia bufora — decision co 5 min, prog = settings.pellet_prog_grzalki (obecnie <?= number_format($prog_grzalki, 2) ?> PLN)
  </summary>
<pre style="font-family: monospace; font-size: 13px; line-height: 1.5; opacity: 0.75; padding: 4px 20px 14px 20px; margin: 0; white-space: pre-wrap;">
1. FLOOR 50°C        bufor &lt; 50°C    →  grzej zawsze (niezaleznie od ceny)
2. TARGET wg ceny    cena &lt; 0        →  70°C
                     cena &lt; 0.25     →  65°C
                     cena &lt; prog     →  60°C
                     cena &gt;= prog    →  50°C
3. LOOKAHEAD 12h     min12h &lt; -0.10  →  czekaj na dolek (target 50)
                     piec OFF: aktywne tez gdy curCost - min12h &gt;= 0.20
4. SLIDING WINDOW    bufor &gt;= 50°C, czas nagrzewania N=ceil((target-bufor)/13°C/h)
                     znajdz N kolejnych godzin o min sumie cen do 5h naprzod
                     jesli okno zaczyna sie pozniej i zysk &gt;= 0.05*N PLN  →  decision -1
5. PIEC OFF dolek    target 50 → 60 gdy curCost ~ min8h (delta &lt;= 0.05)
6. STOP              bufor &gt;= target  →  decision 0 (tanio) lub -3 (drogo)

DECISION:     1 = Grzej         (zielony)
             -1 = Czekaj krotko (pomaranczowy)
          -2/-3 = Czekaj dlugo  (zolty)
              0 = Stop
PREDKOSC: ~6.5°C/h per  grzalka 3kW
           ~13°C/h obie grzalki 6kW
</pre>
</details>
<style>
  details[open] > summary > span:first-child { transform: rotate(90deg); }
  summary::-webkit-details-marker { display: none; }
</style>
