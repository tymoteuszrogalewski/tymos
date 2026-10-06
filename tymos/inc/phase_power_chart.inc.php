<?php
/**
 * inc/phase_power_chart.inc.php — wspolny silnik wykresow obciazenia PER FAZA
 * (BleBox Pstryk: device300 = L1, device301 = L2, device302 = L3).
 *
 * Uzywaja go dwie karty, kazda z wlasnym swipe:
 *   card_pstryk_live — okno 2,5 h, 900 slotow po 10 s, JEDNA PROBKA = JEDEN PIKSEL, swipe co 2,5 h wstecz
 *   card_pstryk_day  — doba, 288 slotow po 5 min, MAX, swipe po dniach
 *
 * Szerokosc PNG 997 px (900 kolumn + osie) trafia na iPada ~1:1: kolumna karty ma ~500 CSS px,
 * a ekran jest retina 2x. Na obu wykresach pasek najbardziej pradozernych urzadzen U GORY
 * (moc rysuje sie na dole) — mechanizm i kolory z card_bufor: ukryta os 0-100 i grube linie poziome.
 */
require_once __DIR__ . '/chart.inc.php';
require_once __DIR__ . '/power_limits.inc.php';

const PPC_W        = 997;    // 900 kolumn wykresu + 45 lewo (os kW) + 52 prawo (os A)
const PPC_H        = 300;
const PPC_SLOTS    = 900;    // = szerokosc obszaru rysowania w pikselach
const PPC_STEP     = 10;     // s — poll BleBoxa
const PPC_HOLD_MAX = 1800;   // s — jak dlugo jeden push trzyma stan urzadzenia (ochrona przed martwym gniazdkiem)
const PPC_YFLOOR   = 9200.0; // W — 40 A przy 230 V, czyli 1,25x wkladki 32 A

/**
 * KOMPENSACJA SKALI. Te wykresy sa rysowane na 997 px, a wykres cen na 680 px, i OBA sa wyswietlane
 * na tej samej szerokosci karty (~500 CSS px). Efekt: to samo `line_width` i ta sama czcionka
 * WYGLADAJA tu o ~32% mniej niz na wykresie cen — czcionki drobniejsze, linie na granicy widocznosci.
 * Dlatego kosmetyka jest tu przeskalowana przez 997/680 ≈ 1,47: czcionka osi 21 (zamiast domyslnych
 * 16,2), pasek stopni 9 px (zamiast 6), paski urzadzen 6 px (zamiast 4), fazy 2 px (zamiast 1).
 * Po przeskalowaniu wszystkie trzy wykresy wygladaja jednakowo na iPadzie.
 * Przy zmianie PPC_W przeliczyc te wartosci.
 */
const PPC_FONT_AXIS = 21;
const PPC_LW_PHASE  = 2;
const PPC_LW_DEV    = 6;
const PPC_LW_TIER   = 9;

// Urzadzenia paska: [device_id, prog W, wysokosc na ukrytej osi, kolor, etykieta]
// Kolory grzalek/pralki/zmywarki 1:1 z Bufora AI. Suszarki tam nie ma — dostaje zolc "Czekaj dlugo".
// Grzalki: device1 = "Bufor Grzałka 2" = GORNA (L1), device3 = "Bufor Grzałka 1" = DOLNA (L3, ta z STB).
// Kolory jak temperatury bufora: gora czerwona, dol pomaranczowa.
// y=98 zajmuje pasek stopni obciazenia (patrz ppc_render), wiec urzadzenia zaczynaja sie od 94.
$PPC_DEVS = [
    [1,  50, 94, '#f44336', 'Grzałka 2 (góra)'],
    [3,  50, 90, '#ff9800', 'Grzałka 1 (dół)'],
    [19, 10, 86, '#4caf50', 'Pralka'],
    [17, 10, 82, '#ffeb3b', 'Suszarka'],
    [8,   5, 78, '#9c27b0', 'Zmywarka'],
];

$PPC_MARGIN = ['top' => 10, 'right' => 52, 'bottom' => 26, 'left' => 45];

$PPC_PHASE_LEGEND = [
    ['type' => 'line', 'line_width' => PPC_LW_PHASE, 'color' => '#e74c3c', 'label' => 'L1'],
    ['type' => 'line', 'line_width' => PPC_LW_PHASE, 'color' => '#27ae60', 'label' => 'L2'],
    ['type' => 'line', 'line_width' => PPC_LW_PHASE, 'color' => '#3498db', 'label' => 'L3'],
];

/**
 * Sloty, w ktorych urzadzenie bralo prad. Zwraca tablice null|$y do serii typu line.
 *
 * Gniazdka Zigbee pushuja nieregularnie, wiec MAX per slot rozsypalby pasek na kropki —
 * kazdy push trzyma stan do nastepnego (max PPC_HOLD_MAX). Brany jest tez ostatni push
 * PRZED oknem, inaczej urzadzenie wlaczone wczesniej nie istnieje na wykresie.
 */
function ppc_dev_slots($db, int $devId, float $thresh, int $t0, int $stepSec, int $slots, float $y): array
{
    $out = array_fill(0, $slots, null);
    $tbl = "device{$devId}";
    $chk = $db->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'power'");
    if (!$chk || $chk->num_rows === 0) return $out;

    $endTs   = $t0 + $slots * $stepSec;
    $fromSql = date('Y-m-d H:i:s', $t0);
    $endSql  = date('Y-m-d H:i:s', $endTs);

    $rows = [];
    $res = $db->query("SELECT UNIX_TIMESTAMP(ts) AS t, power FROM `{$tbl}`
                       WHERE ts < '{$fromSql}' AND power IS NOT NULL ORDER BY ts DESC LIMIT 1");
    if ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
    $res = $db->query("SELECT UNIX_TIMESTAMP(ts) AS t, power FROM `{$tbl}`
                       WHERE ts >= '{$fromSql}' AND ts < '{$endSql}' AND power IS NOT NULL ORDER BY ts ASC");
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;

    $cnt = count($rows);
    for ($i = 0; $i < $cnt; $i++) {
        if ((float)$rows[$i]['power'] <= $thresh) continue;
        $pushTs = (int)$rows[$i]['t'];
        $from   = max($t0, $pushTs);
        $to     = isset($rows[$i + 1]) ? (int)$rows[$i + 1]['t'] : $endTs;
        $to     = min($to, $pushTs + PPC_HOLD_MAX, $endTs);
        if ($to <= $from) continue;
        $s1 = max(0, intdiv($from - $t0, $stepSec));
        $s2 = min($slots - 1, (int)ceil(($to - $t0) / $stepSec) - 1);
        for ($s = $s1; $s <= $s2; $s++) $out[$s] = $y;
    }
    return $out;
}

// Serie paska urzadzen + legenda
function ppc_dev_series($db, int $t0, int $stepSec, int $slots): array
{
    global $PPC_DEVS;
    $series = []; $legend = [];
    foreach ($PPC_DEVS as [$id, $thresh, $y, $color, $label]) {
        $series[] = ['type' => 'line', 'yaxis' => 2, 'line_width' => PPC_LW_DEV, 'color' => $color,
                     'data' => ppc_dev_slots($db, $id, (float)$thresh, $t0, $stepSec, $slots, (float)$y)];
        $legend[] = ['type' => 'line', 'line_width' => PPC_LW_DEV, 'color' => $color, 'label' => $label];
    }
    return [$series, $legend];
}

/**
 * OS USZTYWNIONA: domyslnie 0–9 200 W = 0–40 A. Skala stoi w miejscu miedzy odswiezeniami i miedzy
 * oknami swipe, wiec wysokosc przebiegu zawsze znaczy to samo i widac zapas do progow. Rozjezdza sie
 * tylko, gdy dane wyjda ponad — inaczej trace znikalyby poza obszarem wykresu wlasnie wtedy, gdy sa
 * najwazniejsze.
 */
function ppc_ymax(array ...$sets): float
{
    $peak = 0.0;
    foreach ($sets as $set) {
        foreach ($set as $v) if ($v !== null && $v > $peak) $peak = (float)$v;
    }
    return max(PPC_YFLOOR, ceil($peak * 1.15 / 500) * 500);
}

// Osie: 0 = kW (lewa), 1 = A (prawa, ta sama skala fizyczna), 2 = ukryta 0-100 pod pasek urzadzen
//
// Dane ida w WATACH, ale etykiety lewej osi sa mnozone przez `scale` do kW. W watach etykieta
// "9 000" ma 5 znakow i nie miescila sie w 45 px lewego marginesu — obcinalo ja do "000".
// Ticki sa dobierane "ladnie" (1000/2000/2500/5000), wiec po przeskalowaniu wychodza 0.0/1.0/2.5 —
// stad jedno miejsce po przecinku.
function ppc_yaxes(float $ymax): array
{
    return [
        ['color' => '#9a9a9a', 'decimals' => 1, 'scale' => 0.001, 'grids' => 10, 'min' => 0, 'max' => $ymax,
         'oy_label_below' => ['text' => 'kW', 'color' => '#9a9a9a']],
        ['color' => '#9a9a9a', 'decimals' => 0, 'grids' => 8, 'side' => 'right',
         'min' => 0, 'max' => $ymax / PWR_VOLT,
         'oy_label_below' => ['text' => 'A', 'color' => '#9a9a9a']],
        ['min' => 0, 'max' => 100, 'side' => 'right', 'hidden' => true],
    ];
}

/**
 * Odczyt mocy trzech faz w siatce slotow. Zwraca [$l1, $l2, $l3] — tablice $slots x (W|null).
 * $stepSec 10 = surowa probka na slot, 300 = MAX z 5 minut.
 */
function ppc_phase_data($db, int $t0, int $stepSec, int $slots): array
{
    $l1 = array_fill(0, $slots, null);
    $l2 = array_fill(0, $slots, null);
    $l3 = array_fill(0, $slots, null);
    $fromSql = date('Y-m-d H:i:s', $t0);
    $endSql  = date('Y-m-d H:i:s', $t0 + $slots * $stepSec);
    $res = $db->query("
        SELECT FLOOR((UNIX_TIMESTAMP(d300.ts) - {$t0}) / {$stepSec}) AS slot,
               ROUND(MAX(d300.power), 1) AS p1,
               ROUND(MAX(d301.power), 1) AS p2,
               ROUND(MAX(d302.power), 1) AS p3
        FROM device300 d300
        LEFT JOIN device301 d301 ON d301.ts = d300.ts
        LEFT JOIN device302 d302 ON d302.ts = d300.ts
        WHERE d300.ts >= '{$fromSql}' AND d300.ts < '{$endSql}'
        GROUP BY slot
        ORDER BY slot
    ");
    if ($res) while ($r = $res->fetch_assoc()) {
        $i = (int)$r['slot'];
        if ($i < 0 || $i >= $slots) continue;
        $l1[$i] = $r['p1'] !== null ? (float)$r['p1'] : null;
        $l2[$i] = $r['p2'] !== null ? (float)$r['p2'] : null;
        $l3[$i] = $r['p3'] !== null ? (float)$r['p3'] : null;
    }
    return [$l1, $l2, $l3];
}

// Gotowy wykres: pasek stopni + 3 fazy + pasek urzadzen + progi.
function ppc_render(array $cats, array $l1, array $l2, array $l3, array $devSeries, array $legend): string
{
    global $PPC_MARGIN;

    // PASEK STOPNI na samej gorze (y=99) — ten sam kolor co pasek godzinowy na wykresie cen, zeby ta
    // sama moc znaczyla wszedzie to samo. Liczony z NAJGORSZEJ fazy w slocie: wkladka dziala per faza.
    // Linie, nie bary: przy 900 slotach kolumna ma ~1 px i stackowane bary rozsypalyby sie na kreski.
    // Cena tego rozwiazania to 1-slotowa szpara w miejscu zmiany koloru — na 1 px niewidoczna.
    $worst = [];
    foreach ($l1 as $i => $v) {
        $m = null;
        foreach ([$v, $l2[$i] ?? null, $l3[$i] ?? null] as $p) {
            if ($p !== null && ($m === null || $p > $m)) $m = $p;
        }
        $worst[$i] = $m;
    }
    $tierSeries = [];
    // grubszy od paskow urzadzen; y=98, bo taka linia na y=99 wychodzilaby za gorna krawedz obszaru
    foreach (pwr_tier_bands($worst, 98.0) as $color => $data) {
        $tierSeries[] = ['type' => 'line', 'data' => $data, 'yaxis' => 2, 'color' => $color, 'line_width' => PPC_LW_TIER];
    }

    return chart_render([
        'width'          => PPC_W,
        'height'         => PPC_H,
        'margin'         => $PPC_MARGIN,
        'font_size_axis' => PPC_FONT_AXIS,
        'categories'     => $cats,
        'yaxis'         => ppc_yaxes(ppc_ymax($l1, $l2, $l3)),
        'h_annotations' => pwr_limit_lines(0),
        'series' => array_merge($tierSeries, [
            ['type' => 'line', 'data' => $l1, 'color' => '#e74c3c', 'line_width' => PPC_LW_PHASE, 'null_gap' => true],
            ['type' => 'line', 'data' => $l2, 'color' => '#27ae60', 'line_width' => PPC_LW_PHASE, 'null_gap' => true],
            ['type' => 'line', 'data' => $l3, 'color' => '#3498db', 'line_width' => PPC_LW_PHASE, 'null_gap' => true],
        ], $devSeries),
        'legend' => $legend,
    ]);
}
