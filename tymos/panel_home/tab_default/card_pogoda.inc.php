<?php
/**
 * card_pogoda — prognoza na dzis i jutro, jeden wykres 48 h. Dane z `weather_hourly`/`weather_daily`
 * (import: actions/weather_fetch.php, cron co 30 min). Karta NIE odpytuje internetu — czyta baze,
 * wiec rysuje sie natychmiast i przezywa brak sieci.
 *
 * ZALOZENIE: ma sie czytac Z DALEKA. Kazda informacja siedzi we WLASNYM kanale wizualnym, zeby
 * nie zagluszaly sie nawzajem:
 *   tlo        — zachmurzenie (jasne = slonce, ciemne = chmury), noc dodatkowo przyciemniona
 *   linia      — temperatura, czerwona, bez wasow min/max
 *   slupki     — opad mm/h, zielone, od dolu
 *   pasek      — wiatr, kolor wg predkosci (ta sama technika, co pasek mocy nad wykresem cen)
 *   strzalki   — kierunek wiatru, co 3 h pod paskiem
 * Nad wykresem podsumowanie doby wielkimi cyframi: na pytanie „cieplo czy zimno" ma odpowiadac
 * bez patrzenia na przebieg.
 *
 * Wykres ZAWSZE zaczyna sie od dzisiejszej polnocy i nie przesuwa sie z godzina.
 */
$rows = [];
$r = $db->query("SELECT ts, temp, clouds, wind, gust, wdir, precip, pprob,
                        UNIX_TIMESTAMP(fetched_at) AS fa
                 FROM weather_hourly
                 WHERE ts >= CURDATE() AND ts < CURDATE() + INTERVAL 2 DAY
                 ORDER BY ts");
while ($r && $x = $r->fetch_assoc()) $rows[] = $x;

$days = [];
$r = $db->query("SELECT d, tmin, tmax, precip_sum, sunrise, sunset FROM weather_daily
                 WHERE d >= CURDATE() AND d < CURDATE() + INTERVAL 2 DAY ORDER BY d");
while ($r && $x = $r->fetch_assoc()) $days[] = $x;

if (!$rows) { echo '<div class="card-title">Pogoda</div><div style="padding:12px 2px;color:#777;font-size:13px">Brak danych — czekam na pierwszy import.</div>'; return; }

$age = time() - (int)$rows[0]['fa'];      // wiek prognozy w sekundach

// --- geometria SVG. Pasy od gory: godziny, chmury, wykres, wiatr, strzalki ---
$W = 680;
$L = 32;  $Rr = 6;                          // lewy margines = tyle, ile zajmuje sama skala
// 28 zamiast 44: etykieta konczy sie na 23, wiec dwucyfrowa wartosc zaczyna sie ok. 5, a nie
// 20 — wczesniej z lewej zostawal pas pustki. Zapas trzyma sie ujemnych dwucyfrowych (-10).
$Y_HRS   = 0;   $H_HRS   = 18;              // godziny nad wykresem, co 2 h
$Y_CLOUD = 19;  $H_CLOUD = 14;              // pasek zachmurzenia (+30% wysokosci)
$Y_PLOT  = 36;  $H_PLOT  = 108;             // temperatura + opad
$Y_WIND  = 147; $H_WIND  = 14;              // pasek wiatru (+30%)
$Y_ARR   = 164; $H_ARR   = 17;              // strzalki kierunku
$H = $Y_ARR + $H_ARR;

$n = count($rows);
$colW = ($W - $L - $Rr) / max(1, $n);

$temps = array_map(fn($x) => (float)$x['temp'], $rows);
$tMinR = min($temps); $tMaxR = max($temps);
// Zapas SYMETRYCZNY: 10 procent rozpietosci doby z gory i tyle samo z dolu, nie mniej niz
// 2 stopnie — nad szczytem i pod dolkiem siedza etykiety z wartoscia i bez zapasu wychodzily
// poza obszar. Symetria sprawia, ze krzywa nie przykleja sie do jednej krawedzi.
$pad  = max(2.0, ($tMaxR - $tMinR) * 0.10);
$tMin = floor($tMinR - $pad);
$tMax = ceil($tMaxR + $pad);
if ($tMax - $tMin < 6) $tMax = $tMin + 6;

$xOf = fn($i) => $L + $i * $colW;
$yOf = fn($t) => $Y_PLOT + $H_PLOT - ($t - $tMin) / ($tMax - $tMin) * $H_PLOT;

// Skala wiatru gestsza niz poprzednie siedem progow — na meteogramie ICM widac plynne przejscia,
// a przy siedmiu stopniach caly spokojny dzien mial jeden kolor i nie bylo widac, kiedy przybiera.
function windColor($kmh) {
    // Progi 1:1 z meteogramem ICM, przeliczone z m/s na km/h (API zwraca km/h; 1 m/s = 3,6 km/h).
    // KIERUNEK JASNOSCI: slabo = JASNY blekit, mocniej = ciemniejszy granat, potem zielen, zolc
    // i czerwien. Poprzednia wersja miala to odwrotnie — cisza wygladala grozniej niz wichura.
    $s = [
        [ 7.2, '#bfe3f5'],   // < 2 m/s   cisza
        [10.8, '#8fcdec'],   // < 3
        [14.4, '#57ace0'],   // < 4
        [18.0, '#2f7fc4'],   // < 5
        [21.6, '#1b558f'],   // < 6       najciemniejszy niebieski
        [25.2, '#2e9e57'],   // < 7 m/s   zielen — od tad ICM przechodzi na zielono
        [28.8, '#49bd63'],   // < 8
        [32.4, '#7ed957'],   // < 9
        [39.6, '#c3e04a'],   // < 11
        [46.8, '#ffc107'],   // < 13      zolty
        [57.6, '#ff8c00'],   // < 16
        [72.0, '#ff5722'],   // < 20
    ];
    foreach ($s as [$max, $c]) if ($kmh < $max) return $c;
    return '#e53935';
}
// Zachmurzenie jako osobny pasek u gory (jak wiatr u dolu), a nie jako tlo calego wykresu:
// czyste niebo = blekit, pelne zachmurzenie = ciemny grafit. Tlo wykresu zostaje jednolite,
// wiec linia temperatury i slupki opadu maja spokojne podloze.
function cloudColor($pct) {
    $f = max(0, min(100, (int)$pct)) / 100;
    $r = 158 - $f * 122; $g = 208 - $f * 164; $b = 246 - $f * 192;
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}
// Strzalka rysowana w SVG, nie znakiem Unicode: te same strzalki mialy inny ksztalt na macOS
// i na iPadzie (kazdy system bierze je z wlasnego kroju), a chcemy jeden wyglad wszedzie.
// API podaje kierunek, SKAD wieje; strzalka pokazuje, DOKAD — stad obrot o 180 stopni.

// Naglowek: temperatura o wschodzie, dzienne maksimum i temperatura o zachodzie. Trzy liczby
// mowia o dniu wiecej niz samo min/max — widac, czy rano jest zimno i czy wieczor trzyma cieplo.
$byTs = [];
foreach ($rows as $x) $byTs[substr($x['ts'], 0, 13)] = (float)$x['temp'];
function tempAt($byTs, $date, $time) {
    return $byTs[$date . ' ' . substr($time, 0, 2)] ?? null;
}
?>
<style>
.wx{padding:2px 0 4px}   /* bez ramki i tla, jak wykres cen — pelna szerokosc karty */
.wx svg{width:100%;height:auto;display:block}
.wx .gl{stroke:rgba(255,255,255,0.30);stroke-width:1;stroke-dasharray:2 3}
.wx .vg{stroke:rgba(255,255,255,0.30);stroke-width:1;stroke-dasharray:2 3}
.wx .ax{fill:#b0b0b0;font:700 18px sans-serif}
.wx .hr{fill:#9a9a9a;font:600 14px sans-serif}
</style>

<div class="wx">
<svg viewBox="0 0 <?= $W ?> <?= $H ?>" preserveAspectRatio="xMidYMid meet">
  <defs>
    <?php // Pasek chmur jako JEDEN gradient zamiast 48 prostokatow: przejscia sa plynne, wiec widac
    // nie tylko „pochmurno / slonecznie", ale i to, jak zachmurzenie narasta albo puszcza.
    // Przystanek wypada w SRODKU kazdej godziny, zeby kolor odpowiadal jej wartosci, a nie granicy. ?>
    <linearGradient id="wxCloud" x1="0" y1="0" x2="1" y2="0">
    <?php foreach ($rows as $i => $x): ?>
      <stop offset="<?= round(($i + 0.5) / $n * 100, 2) ?>%" stop-color="<?= cloudColor($x['clouds']) ?>"/>
    <?php endforeach; ?>
    </linearGradient>
    <?php // Wiatr tym samym sposobem, co chmury: plynne przejscia zamiast 48 slupkow, wiec widac,
    // kiedy zaczyna przybierac, a nie tylko ze „jest zielono". ?>
    <linearGradient id="wxWind" x1="0" y1="0" x2="1" y2="0">
    <?php foreach ($rows as $i => $x): ?>
      <stop offset="<?= round(($i + 0.5) / $n * 100, 2) ?>%" stop-color="<?= windColor((float)$x['wind']) ?>"/>
    <?php endforeach; ?>
    </linearGradient>
    <g id="wx-arrow">
      <path d="M0 7.8 V-6.5 M-4.2 -2.3 L0 -7.3 L4.2 -2.3" fill="none" stroke="#7fb0ff"
            stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
    </g>
  </defs>
  <?php // GODZINY nad wykresem, co 2 h
  foreach ($rows as $i => $x):
      $hh = (int)date('G', strtotime($x['ts']));
      if ($hh % 4 !== 0) continue; ?>
    <text class="hr" x="<?= round($xOf($i) + $colW / 2, 1) ?>" y="<?= $Y_HRS + 14 ?>" text-anchor="middle"><?= $hh ?></text>
  <?php endforeach; ?>

  <?php // PASEK ZACHMURZENIA — jeden prostokat wypelniony gradientem z defs ?>
  <rect x="<?= $L ?>" y="<?= $Y_CLOUD ?>" width="<?= $W - $L - $Rr ?>" height="<?= $H_CLOUD ?>"
        fill="url(#wxCloud)"/>

  <?php // TLO wykresu: jednolite, noc ciemniejsza
  foreach ($rows as $i => $x):
      $hh = (int)date('G', strtotime($x['ts']));
      if ($hh >= 6 && $hh < 20) continue; ?>
    <rect x="<?= round($xOf($i), 1) ?>" y="<?= $Y_PLOT ?>" width="<?= ceil($colW) + 1 ?>" height="<?= $H_PLOT ?>" fill="#3a3a41"/>
  <?php endforeach; ?>

  <?php // siatka pozioma, rowny krok
  // Krok dobierany do zakresu, celujac w 4-6 wartosci: siatka ma pomagac odczytac wysokosc
  // krzywej, a nie zasnuwac wykres liniami.
  $span  = $tMax - $tMin;
  $step  = $span > 34 ? 10 : ($span > 20 ? 5 : ($span > 10 ? 4 : ($span > 5 ? 2 : 1)));
  $ticks = [];
  for ($t = $tMin; $t <= $tMax; $t += $step) $ticks[] = $t;
  foreach ($ticks as $t):
      $y = round($yOf($t), 1); ?>
    <line class="gl" x1="<?= $L ?>" y1="<?= $y ?>" x2="<?= $W - $Rr ?>" y2="<?= $y ?>"/>
    <text class="ax" x="<?= $L - 5 ?>" y="<?= $y + 6 ?>" text-anchor="end"><?= $t ?></text>
  <?php endforeach; ?>

  <?php // pionowe kreski co 2 h, przerywane — od godzin az po pasek wiatru
  foreach ($rows as $i => $x):
      $hh = (int)date('G', strtotime($x['ts']));
      if ($hh % 4 !== 0) continue;
      $xx = round($xOf($i), 1); ?>
    <line class="vg" x1="<?= $xx ?>" y1="<?= $Y_PLOT ?>" x2="<?= $xx ?>" y2="<?= $Y_PLOT + $H_PLOT ?>"/>
  <?php endforeach; ?>

  <?php // OPAD — zielone slupki, 4 mm/h = pelna wysokosc, ale skala PIERWIASTKOWA.
  // Liniowo mzawka 0,1-0,4 mm/h (najczestszy opad w naszym klimacie) dawala 2-6% wysokosci,
  // czyli slupek nie do odroznienia od zera — user porownywal widget z ICM i widzial „brak
  // deszczu" przy realnej mzawce. Pierwiastek podnosi 0,2 mm do ~22% wysokosci, a 4 mm dalej
  // sieduje na 100%: male opady widac, duze sie nie przesterowuja.
  foreach ($rows as $i => $x):
      $p = (float)$x['precip']; if ($p <= 0) continue;
      $bh = max(4, sqrt(min(1.0, $p / 4.0)) * ($H_PLOT * 0.62)); ?>
    <rect x="<?= round($xOf($i) + 1, 1) ?>" y="<?= round($Y_PLOT + $H_PLOT - $bh, 1) ?>"
          width="<?= max(2, round($colW - 2, 1)) ?>" height="<?= round($bh, 1) ?>" fill="#22c55e" opacity=".92"/>
  <?php endforeach; ?>

  <?php // granica doby
  foreach ($rows as $i => $x):
      if (date('G', strtotime($x['ts'])) !== '0' || $i === 0) continue; ?>
    <line x1="<?= round($xOf($i), 1) ?>" y1="<?= $Y_HRS ?>" x2="<?= round($xOf($i), 1) ?>" y2="<?= $Y_PLOT + $H_PLOT ?>"
          stroke="rgba(255,255,255,0.4)" stroke-width="1.5"/>
  <?php endforeach; ?>

  <?php // TEMPERATURA
  $pts = [];
  foreach ($rows as $i => $x) $pts[] = round($xOf($i) + $colW / 2, 1) . ',' . round($yOf((float)$x['temp']), 1); ?>
  <polyline points="<?= implode(' ', $pts) ?>" fill="none" stroke="#ff5f45" stroke-width="2.6"
            stroke-linecap="round" stroke-linejoin="round"/>

  <?php // ETYKIETY NA KRZYWEJ: wschod, dzienne maksimum, zachod — liczba stoi tam, gdzie ta
  // temperatura faktycznie wypada, wiec czyta sie ja wzrokiem z wykresu, a nie z legendy.
  // Maksimum wyrozniona wielkoscia; przy szczycie etykieta idzie NAD punktem, przy skrajach
  // odsuwa sie w bok, zeby nie wyjsc poza obszar.
  $marks = [];
  foreach ($days as $dd) {
      $hSr = (int)substr($dd['sunrise'], 0, 2);
      $hSs = (int)substr($dd['sunset'],  0, 2);
      $best = null; $bestT = -99;
      foreach ($rows as $i => $x) {
          if (substr($x['ts'], 0, 10) !== $dd['d']) continue;
          $hh = (int)date('G', strtotime($x['ts']));
          if ($hh === $hSr) $marks[] = [$i, (float)$x['temp'], false];
          if ($hh === $hSs) $marks[] = [$i, (float)$x['temp'], false];
          if ((float)$x['temp'] > $bestT) { $bestT = (float)$x['temp']; $best = $i; }
      }
      if ($best !== null) $marks[] = [$best, $bestT, true];
  }
  foreach ($marks as [$i, $t, $isMax]):
      $cx = $xOf($i) + $colW / 2;
      $anchor = $cx < $L + 26 ? 'start' : ($cx > $W - $Rr - 26 ? 'end' : 'middle');
      $tx = $anchor === 'start' ? $L + 2 : ($anchor === 'end' ? $W - $Rr - 2 : $cx); ?>
    <circle cx="<?= round($cx, 1) ?>" cy="<?= round($yOf($t), 1) ?>" r="2.6" fill="#ff5f45"/>
    <text x="<?= round($tx, 1) ?>" y="<?= round($yOf($t) - 7, 1) ?>" text-anchor="<?= $anchor ?>"
          fill="#e8e8e8"
          style="font:700 <?= $isMax ? 22 : 17 ?>px sans-serif"><?= str_replace('.', ',', (string)round($t)) ?>°</text>
  <?php endforeach; ?>

  <?php // TERAZ — pionowa linia w biezacej godzinie i minucie. Ta sama niebiesc, co slupek
  // aktualnej godziny na wykresie cen, wiec oba wykresy mowia „tu jestes" tym samym kolorem.
  // Rysowana na koncu, zeby lezala nad krzywa i paskami.
  // Godziny od poczatku wykresu, nie od dzisiejszej polnocy — wykres zawsze startuje o 00:00
  // pierwszej doby w bazie, wiec liczenie od niej dziala takze, gdyby import kiedys zostal w tyle.
  $nowH = (time() - strtotime(substr($rows[0]['ts'], 0, 10) . ' 00:00:00')) / 3600;
  $nowX = $L + $nowH * $colW;
  if ($nowX >= $L && $nowX <= $W - $Rr): ?>
    <line x1="<?= round($nowX, 1) ?>" y1="<?= $Y_CLOUD ?>" x2="<?= round($nowX, 1) ?>" y2="<?= $Y_WIND + $H_WIND ?>"
          stroke="#007bff" stroke-width="3" opacity=".95"/>
  <?php endif; ?>

  <?php // PASEK WIATRU — jeden prostokat z gradientem ?>
  <rect x="<?= $L ?>" y="<?= $Y_WIND ?>" width="<?= $W - $L - $Rr ?>" height="<?= $H_WIND ?>"
        fill="url(#wxWind)"/>

  <?php // STRZALKI kierunku co 2 h
  foreach ($rows as $i => $x):
      $hh = (int)date('G', strtotime($x['ts']));
      if ($hh % 2 !== 0) continue;
      $rot = ((int)$x['wdir'] + 180) % 360;                       // skad wieje -> dokad leci
      $cx  = round($xOf($i) + $colW / 2, 1); $cy = $Y_ARR + 9; ?>
    <use href="#wx-arrow" transform="translate(<?= $cx ?>,<?= $cy ?>) rotate(<?= $rot ?>)"/>
  <?php endforeach; ?>
  <?php if ($age > 3 * 3600): ?>
    <text x="<?= $W - $Rr ?>" y="<?= $Y_HRS + 11 ?>" text-anchor="end"
          style="font:600 10px sans-serif" fill="#7a6a3a">prognoza sprzed <?= round($age / 3600) ?> h</text>
  <?php endif; ?>
</svg>
</div>
