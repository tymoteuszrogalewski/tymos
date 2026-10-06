<?php
/**
 * inc/power_limits.inc.php — progi obciazenia JEDNEJ FAZY, wspolne dla wszystkich wykresow mocy.
 *
 * Fizycznym limitem instalacji jest wkladka przedlicznikowa **32 A gG na faze**, a nie moc umowna
 * (taryfa G nie ma skladnika mocowego, wiec przekroczenie 16,5 kW nic nie kosztuje). Wartosci przy 230 V:
 *
 * LINIE (progi rysowane na wykresach mocy):
 *   5 500 W = 23,9 A  zielony   — moc umowna 16,5 kW rozlozona na 3 fazy
 *   5 900 W = 25,7 A  zolty     — cel na obciazenia godzinowe (~80% wkladki, margines na starzenie stopu)
 *   7 400 W = 32,2 A  czerwony  — nominal wkladki
 *
 * KOLOR PASKA = NAJWYZSZA PRZEKROCZONA LINIA, nie strefa pod nia. Pierwsza wersja robila odwrotnie
 * (czerwony = "do 7 400 W") i user slusznie czytal czerwony pasek jako "przekroczylem czerwona",
 * podczas gdy pik siedzial pod nia. NIE WRACAC do starego mapowania.
 *   ≤ 23,9 A         zielony  #4caf50  ponizej mocy umownej
 *   23,9-25,7 A      zolty    #ffeb3b  wiecej niz umowa, ale wciaz w celu roboczym
 *   25,7-32,2 A      pomarancz #ff9800 mieszcze sie w zakresie topika
 *   32,2-40,0 A      czerwony #ff1744  przekroczony nominal wkladki — pojedyncze piki OK, czesto = myslec o wiekszej mocy
 *   > 40,0 A         magenta  #e040fb  1,25x wkladki: prog, od ktorego topik realnie sie degraduje — skladac wniosek
 *
 * Dlaczego margines: stop w przewezeniu wkladki degraduje sie metalurgicznie przy kazdej dlugiej
 * pracy blisko nominalu i efektywny prad zadzialania SPADA z latami — stad "same" przepalajace sie topiki.
 *
 * Jedno zrodlo prawdy: `card_pstryk` (pasek godzinowy) i `card_pstryk_load` (linie progow) czytaja stad.
 */

const PWR_VOLT = 230.0;

// Progi rysowane jako linie poziome
$_PWR_LINES = [
    ['y' => 5500.0, 'color' => '#4caf50'],
    ['y' => 5900.0, 'color' => '#ffeb3b'],
    ['y' => 7400.0, 'color' => '#ff1744'],
];

// Stopnie paska. Kolejnosc rosnaca; ostatni wpis bez 'max' = wszystko powyzej.
// `thick` = grubosc kreski w jednostkach osi (0-100). Rosnie z kazdym progiem, wiec przekroczenie
// widac nie tylko po kolorze, ale i po tym, ze kreska jest grubsza — czytelne takze bez patrzenia
// na odcien. Pasek rosnie W GORE od wspolnej podstawy PWR_BAND_BASE.
$_PWR_TIERS = [
    ['max' => 5500.0, 'color' => '#4caf50', 'thick' => 1.0],
    ['max' => 5900.0, 'color' => '#ffeb3b', 'thick' => 2.0],
    ['max' => 7400.0, 'color' => '#ff9800', 'thick' => 3.0],
    ['max' => 9200.0, 'color' => '#ff1744', 'thick' => 4.0],
    ['max' => null,   'color' => '#e040fb', 'thick' => 5.0],
];
// Dolna krawedz paska. 95 + najgrubsza kreska (5) = 100, czyli gorna krawedz obszaru wykresu.
const PWR_BAND_BASE = 95.0;

// Grubosc kreski dla mocy jednej fazy (jednostki osi 0-100)
function pwr_tier_thick(float $w): float
{
    global $_PWR_TIERS;
    foreach ($_PWR_TIERS as $t) {
        if ($t['max'] === null || $w <= $t['max']) return (float)$t['thick'];
    }
    return 10.0;
}

// Kolor progu dla mocy jednej fazy
function pwr_tier_color(float $w): string
{
    global $_PWR_TIERS;
    foreach ($_PWR_TIERS as $t) {
        if ($t['max'] === null || $w <= $t['max']) return $t['color'];
    }
    return '#e040fb';
}

// Progi jako 'h_annotations' do chart_render
function pwr_limit_lines(int $yaxis = 0): array
{
    global $_PWR_LINES;
    $out = [];
    foreach ($_PWR_LINES as $l) {
        $out[] = ['y' => $l['y'], 'yaxis' => $yaxis, 'color' => $l['color'], 'dash' => 4, 'gap' => 6];
    }
    return $out;
}

/**
 * Pasek stopni: z tablicy mocy najgorszej fazy robi [kolor => tablica $y|null] — jedna seria na kolor.
 * Uzywaja tego wszystkie trzy wykresy, zeby ta sama moc zawsze dawala ten sam kolor.
 */
function pwr_tier_bands(array $worst, float $y): array
{
    $out = [];
    $n = count($worst);
    foreach ($worst as $i => $w) {
        if ($w === null) continue;
        $c = pwr_tier_color((float)$w);
        if (!isset($out[$c])) $out[$c] = array_fill(0, $n, null);
        $out[$c][$i] = $y;
    }
    return $out;
}

// Podpis progow pod tytulem karty: "5,5 kW / 23,9 A · ..."
function pwr_limit_note(): string
{
    global $_PWR_LINES;
    $parts = [];
    foreach ($_PWR_LINES as $l) {
        $parts[] = '<span style="color:' . $l['color'] . '">'
                 . number_format($l['y'] / 1000, 1, ',', '') . ' kW / '
                 . number_format($l['y'] / PWR_VOLT, 1, ',', '') . ' A</span>';
    }
    return implode(' <span style="opacity:.4">·</span> ', $parts);
}

/**
 * Godzinowe maksimum obciazenia NAJGORSZEJ FAZY dla podanej daty (device300/301/302 = L1/L2/L3).
 *
 * Swiadomie NIE suma trzech faz: wkladka dziala na kazda faze OSOBNO, wiec o bezpieczenstwie decyduje
 * najbardziej obciazona faza. Suma domu 15 kW przy asymetrii moze oznaczac 32 A na jednej fazie.
 *
 * Zwraca tablice 24 elementow: W albo null (godzina bez danych / jeszcze nie nadeszla).
 */
function pwr_worst_phase_hourly($db, string $date): array
{
    $out = array_fill(0, 24, null);
    if (!$db) return $out;
    $res = $db->query("
        SELECT HOUR(d300.ts) AS h,
               GREATEST(COALESCE(MAX(d300.power), 0),
                        COALESCE(MAX(d301.power), 0),
                        COALESCE(MAX(d302.power), 0)) AS mx
        FROM device300 d300
        LEFT JOIN device301 d301 ON d301.ts = d300.ts
        LEFT JOIN device302 d302 ON d302.ts = d300.ts
        WHERE DATE(d300.ts) = '" . $db->real_escape_string($date) . "'
        GROUP BY HOUR(d300.ts)
    ");
    if ($res) while ($r = $res->fetch_assoc()) {
        $h = (int)$r['h'];
        if ($h >= 0 && $h < 24 && $r['mx'] !== null) $out[$h] = (float)$r['mx'];
    }
    return $out;
}
