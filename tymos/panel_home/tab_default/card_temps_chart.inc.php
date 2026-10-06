<?php
/**
 * card_temps_chart — dobowy wykres temperatur (5-min sloty) do rozwinietego diva card_temps.
 *
 * Wydzielone z card_temps 2026-08-17. Po co osobna karta: kafelki na home maja stac
 * nieruchomo i pokazywac ZAWSZE dzis, a swipe po dniach ma dotyczyc samego wykresu.
 * Karta jest ladowana przez loadCard do kontenera .sw-zone, ktory nosi swipeData —
 * mechanizm swipe (index/swipe.inc.js) obsluguje ja tak samo jak pelna karte.
 *
 * Params: date=YYYY-MM-DD (domyslnie dzis).
 */
require_once __DIR__ . '/../../inc/chart.inc.php';
require_once __DIR__ . '/../../inc/outdoor_temps.inc.php';

$today     = date('Y-m-d');
$dateParam = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']))
             ? $_GET['date'] : $today;
if ($dateParam > $today) $dateParam = $today;
$isToday = ($dateParam === $today);

// naglowek dnia — przy swipe to jedyna wskazowka, ktory dzien widzisz
if ($isToday) {
    $dayLabel = 'Dzisiaj · ' . date('d.m');
} else {
    $diff = (int)round((strtotime($today) - strtotime($dateParam)) / 86400);
    if ($diff === 1)     $rel = 'Wczoraj';
    elseif ($diff === 2) $rel = 'Przedwczoraj';
    else                 $rel = $diff . ' dni temu';
    $dayLabel = $rel . ' · ' . date('d.m', strtotime($dateParam));
}

$SLOTS      = 288; // 24 h * 12 (co 5 min)
$cienHour   = array_fill(0, $SLOTS, null); // device10   — cien
$slonceHour = array_fill(0, $SLOTS, null); // device1043 — slonce
$salonHour  = array_fill(0, $SLOTS, null); // device684  — salon temp
$humHour    = array_fill(0, $SLOTS, null); // device684  — salon humidity
$chlSalon   = array_fill(0, $SLOTS, null); // dev1045 wentylatory ON
$chlNawiew  = array_fill(0, $SLOTS, null); // dev1052 zawor OPEN
$freecool   = array_fill(0, $SLOTS, null); // dev1044 Kuchnia Przewiew ON

if ($db) {
    $db->select_db('tymos');
    $res = $db->query("SELECT HOUR(ts)*12 + FLOOR(MINUTE(ts)/5) AS s, ROUND(AVG(temperature),1) AS t FROM device10 WHERE DATE(ts)='$dateParam' GROUP BY s");
    if ($res) while ($r = $res->fetch_assoc()) $cienHour[(int)$r['s']] = $r['t'] !== null ? (float)$r['t'] : null;
    $res = $db->query("SELECT HOUR(ts)*12 + FLOOR(MINUTE(ts)/5) AS s, ROUND(AVG(temperature),1) AS t FROM device1043 WHERE DATE(ts)='$dateParam' GROUP BY s");
    if ($res) while ($r = $res->fetch_assoc()) $slonceHour[(int)$r['s']] = $r['t'] !== null ? (float)$r['t'] : null;
    $res = $db->query("SELECT HOUR(ts)*12 + FLOOR(MINUTE(ts)/5) AS s, ROUND(AVG(temperature),1) AS t, ROUND(AVG(humidity),0) AS hu FROM device684 WHERE DATE(ts)='$dateParam' GROUP BY s");
    if ($res) while ($r = $res->fetch_assoc()) {
        $s = (int)$r['s'];
        $salonHour[$s] = $r['t']  !== null ? (float)$r['t']  : null;
        $humHour[$s]   = $r['hu'] !== null ? (float)$r['hu'] : null;
    }
    // stany chlodnic/free-coolingu — snapshot co minute z ai_wymienniki.php
    $res = $db->query("SELECT HOUR(ts)*12 + FLOOR(MINUTE(ts)/5) AS s, MAX(chlodnica_salon) AS cs, MAX(nawiew) AS nv, MAX(freecool) AS fc FROM klimat_ai WHERE DATE(ts)='$dateParam' GROUP BY s");
    if ($res) while ($r = $res->fetch_assoc()) {
        $s = (int)$r['s'];
        $chlSalon[$s]  = $r['cs'] ? 10.0 : null;
        $chlNawiew[$s] = $r['nv'] ? 20.0 : null;
        $freecool[$s]  = $r['fc'] ? 30.0 : null;
    }
}

// Niezmiennik slonce >= cien (inc/outdoor_temps.inc.php) — slot w slot, zeby linia slonca
// nigdy nie schodzila pod linie cienia. Puste sloty zostaja puste.
$slonceHour = outdoor_sun_fix_series($slonceHour, $cienHour);

// os X: etykieta tylko na pelnej godzinie
$cats = [];
for ($s = 0; $s < $SLOTS; $s++) $cats[] = ($s % 12 === 0) ? (string)intdiv($s, 12) : '';
?>
<div style="text-align:center; font-size:12px; color:#9ab; padding:0 0 6px;"><?= htmlspecialchars($dayLabel) ?></div>
<?= chart_render([
    'width' => 800, 'categories' => $cats,
    'yaxis' => [
        ['color' => '#ff4444', 'decimals' => 1, 'tight' => true, 'grids' => 10, 'oy_label_above' => ['text' => '°C', 'color' => '#ff4444']],
        ['color' => '#2b9fe6', 'decimals' => 0, 'side' => 'right', 'min' => 0, 'max' => 100, 'grids' => 10, 'oy_label_above' => ['text' => '%', 'color' => '#2b9fe6']],
        ['min' => 0, 'max' => 100, 'side' => 'right', 'hidden' => true],
    ],
    'legend' => [
        ['color' => '#ff4444', 'label' => 'Salon',       'type' => 'line', 'line_width' => 6],
        ['color' => '#ffb300', 'label' => 'Słońce',      'type' => 'line'],
        ['color' => '#90a4ae', 'label' => 'Cień',        'type' => 'line'],
        ['color' => '#2b9fe6', 'label' => 'Wilg. salon', 'type' => 'line', 'line_width' => 5, 'dash' => 2, 'gap' => 6],
        ['color' => '#4caf50', 'label' => 'Free-cooling',           'type' => 'line_thick'],
        ['color' => '#1565c0', 'label' => 'Chłodnica Rekuperator',  'type' => 'line_thick'],
        ['color' => '#64b5f6', 'label' => 'Chłodnica Salon',        'type' => 'line_thick'],
    ],
    'series' => [
        ['type' => 'line', 'data' => $freecool,   'color' => '#4caf50', 'yaxis' => 2, 'line_width' => 6],
        ['type' => 'line', 'data' => $chlNawiew,  'color' => '#1565c0', 'yaxis' => 2, 'line_width' => 6],
        ['type' => 'line', 'data' => $chlSalon,   'color' => '#64b5f6', 'yaxis' => 2, 'line_width' => 6],
        ['type' => 'line', 'data' => $salonHour,  'color' => '#ff4444', 'null_gap' => false, 'line_width' => 6],
        ['type' => 'line', 'data' => $slonceHour, 'color' => '#ffb300', 'null_gap' => false],
        ['type' => 'line', 'data' => $cienHour,   'color' => '#90a4ae', 'null_gap' => false],
        ['type' => 'line', 'data' => $humHour,    'color' => '#2b9fe6', 'yaxis' => 1, 'null_gap' => false, 'line_width' => 5, 'dash' => 2, 'gap' => 6],
    ],
]) ?>
