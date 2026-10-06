#!/usr/bin/env php
<?php
/**
 * pstryk_import.php — Pstryk API (pricing + meter_values + cost) + TGE Fixing I
 * Zapisuje do tabeli: energa
 * Harmonogram: co 2h parzyste o :05 (cron.d/tymos)
 */

require_once __DIR__ . '/lib/_log.inc.php';

const FETCH_TRIES     = 3;   // ile prob HTTP w jednym biegu
const RETRY_GAP       = 5;   // sekundy miedzy probami
const FAIL_RUNS_ALERT = 3;   // po ilu nieudanych BIEGACH pod rzad wystawic ERROR (alert Telegram)

$PSTRYK_API_KEY = PSTRYK_API_KEY;

// Stawki taryfowe Energa (per rok), BRUTTO zl/kWh = cena energii brutto (z VAT i akcyza) + 1,23 x (skladnik zmienny
// sieciowy + jakosciowa 0,0331 + OZE 0,0073 + kogeneracja 0,0030). Zrodlo 2026: Taryfa ENERGA-OBROT dla grup G od
// 1.01.2026 (G11 0,4968 netto / 0,6172 brutto; G12r szczyt 0,6667 / 0,8262, pozaszczyt 0,3017 / 0,3772) oraz
// Wyciag z Taryfy Energa-Operator 2026 (zmienny: G11 0,3485; G12r 0,3640 / 0,0882; staly 3-faz: G11 11,77,
// G12r 20,17, G11f 55,51 zl/mc; mocowa >2800 kWh/rok 24,05; abonament 0,74). Zweryfikowane 2026-09-09.
// 2025: oszacowanie (2026 x 0,9), tylko listopad-grudzien 2025 — do podmiany, gdy bedzie taryfa 2025.
$RATES = [
    2025 => [
        'g11'             => 0.98946,
        'g12r_cheap'      => 0.48637,
        'g12r_expensive'  => 1.19575,
        'fix_g11f'        => 80.30,      // netto zl/mc: 55,51 + 24,05 + 0,74
        'fix_g11'         => 36.49,      // 11,70 + 24,05 + 0,74
        'fix_g12r'        => 44.96,      // 20,17 + 24,05 + 0,74 (przyjeto 2026)
    ],
    2026 => [
        'g11'             => 1.0992,
        'g12r_cheap'      => 0.5391,     // II strefa: 13-16, 22-7 + CALE soboty, niedziele, swieta
        'g12r_expensive'  => 1.3273,     // I strefa: 7-13, 16-22 w dni robocze
        'fix_g11f'        => 80.30,
        'fix_g11'         => 36.56,      // 11,77 + 24,05 + 0,74
        'fix_g12r'        => 44.96,      // 20,17 + 24,05 + 0,74  — G12r ma WYZSZY skladnik staly niz G11!
    ],
];

// Stawki i oplaty stale/godzine dla KONKRETNEJ godziny (rok i miesiac wiersza, nie "dzis" — wczesniej import
// bral date('Y') i date('t'), przez co wiersze ze stycznia liczone w lutym mialy zle stale, a stare wiersze
// nigdy nie widzialy zmienionych stawek).
function tariff_for(string $ts_local): array {
    global $RATES;
    $y = (int)substr($ts_local, 0, 4);
    $r = $RATES[$y] ?? $RATES[max(array_keys($RATES))];
    $hours = (int)date('t', strtotime($ts_local)) * 24;
    $r['fix_g11f_h'] = $r['fix_g11f'] * 1.23 / $hours;
    $r['fix_g11_h']  = $r['fix_g11']  * 1.23 / $hours;
    $r['fix_g12r_h'] = $r['fix_g12r'] * 1.23 / $hours;
    return $r;
}

// Dni ustawowo wolne (PL) — w G12r cala doba w II strefie. Wielkanoc: algorytm gregorianski (bez ext. calendar).
function pl_holidays(int $y): array {
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    $easter = mktime(12, 0, 0, $month, $day, $y);
    $days = ["$y-01-01", "$y-01-06", "$y-05-01", "$y-05-03", "$y-08-15", "$y-11-01", "$y-11-11", "$y-12-25", "$y-12-26"];
    if ($y >= 2025) $days[] = "$y-12-24";                 // Wigilia wolna od 2025
    foreach ([0, 1, 49, 60] as $off) $days[] = date('Y-m-d', $easter + $off * 86400);   // Wielkanoc, Pon. Wlk., Zielone Sw., Boze Cialo
    return $days;
}
function g12r_is_offpeak(string $ts_local): bool {
    $t = strtotime($ts_local);
    $hour = (int)date('G', $t);
    if (($hour >= 0 && $hour <= 6) || ($hour >= 13 && $hour <= 15) || $hour >= 22) return true;   // 22-7, 13-16
    $dow = (int)date('N', $t);
    if ($dow >= 6) return true;                                                                     // sobota, niedziela
    return in_array(date('Y-m-d', $t), pl_holidays((int)date('Y', $t)), true);
}

// Daty
$yesterday = gmdate('Y-m-d', time() - 86400);
$today     = gmdate('Y-m-d');
$tomorrow  = gmdate('Y-m-d', time() + 86400);

// DB
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

function db_query(string $sql): bool {
    global $db;
    $ok = $db->query($sql);
    if (!$ok) tymos_log('ERROR', "SQL: {$db->error} | " . substr($sql, 0, 200));
    return (bool)$ok;
}

/**
 * G12r „jak pod G12r" (2026-09-07, user): energia GRZALEK BUFORA (dev1 + dev3) liczona ZAWSZE po taryfie
 * pozaszczytowej, niezaleznie od godziny. Dzis grzalki chodza w dolkach Pstryka (np. 11-12), ktore w G12r
 * sa szczytem — uczciwe porownanie zaklada, ze pod G12r TymOS przesunalby je na 13-15 / 22-6.
 * kWh grzalek z stat1/stat3 (5-min power_avg, 12 wierszy/h): srednia mocy w godzinie / 1000.
 * Liczniki energy Sonoffa sa zamrozone (bug FW), stad calkowanie mocy. Brak wierszy = 0 (bez korekty).
 * Korekta = min(kwh_grzalek, kwh_domu) * (stawka_szczyt - stawka_pozaszczyt), tylko w godzinach szczytu.
 */
function grzalki_kwh(string $ts_local): float {
    global $db;
    $h0 = $db->real_escape_string(substr($ts_local, 0, 13) . ':00:00');
    // COALESCE osobno na kazda grzalke: stat3 zaczyna sie 02.2026, stat1 dopiero 03.2026 — bez tego NULL + liczba = NULL.
    $r = $db->query("SELECT COALESCE((SELECT AVG(power_avg) FROM stat1 WHERE ts >= '$h0' AND ts < '$h0' + INTERVAL 1 HOUR), 0)
                          + COALESCE((SELECT AVG(power_avg) FROM stat3 WHERE ts >= '$h0' AND ts < '$h0' + INTERVAL 1 HOUR), 0) AS w");
    $w = ($r && ($x = $r->fetch_row())) ? $x[0] : null;
    return $w === null ? 0.0 : round((float)$w / 1000, 4);
}
function g12r_grz_cost(float $cost_g12r, float $kwh, float $kwh_grz, bool $offpeak, float $cheap, float $expensive): float {
    if ($offpeak || $kwh_grz <= 0) return $cost_g12r;
    return round($cost_g12r - min($kwh_grz, $kwh) * ($expensive - $cheap), 4);
}
// Komplet kosztow godziny dla G11 i G12r (import i --recalc-all licza JEDNA funkcja).
function tariff_costs(string $ts_local, float $kwh): array {
    $r  = tariff_for($ts_local);
    $op = g12r_is_offpeak($ts_local);
    $cost_g11  = round($kwh * $r['g11'] + $r['fix_g11_h'], 4);
    $cost_g12r = round($kwh * ($op ? $r['g12r_cheap'] : $r['g12r_expensive']) + $r['fix_g12r_h'], 4);
    $kwh_grz   = grzalki_kwh($ts_local);
    $cost_g12r_grz = g12r_grz_cost($cost_g12r, $kwh, $kwh_grz, $op, $r['g12r_cheap'], $r['g12r_expensive']);
    return [$cost_g11, $cost_g12r, $kwh_grz, $cost_g12r_grz];
}

function utc_to_local(string $utc_str): string {
    $dt = new DateTime($utc_str, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Europe/Warsaw'));
    return $dt->format('Y-m-d H:00:00');
}

/**
 * Pobiera dane z API Pstryka. FETCH_TRIES prob w jednym biegu z odstepem RETRY_GAP —
 * `HTTP 0` (zerwane polaczenie / DNS / timeout) to zwykle mrugniecie sieci i druga proba przechodzi.
 * Nieudana proba loguje tylko WARN; ERROR (= alert na Telegram) wystawia dopiero licznik
 * nieudanych BIEGOW w pstryk_fail_bump() ponizej.
 */
function pstryk_fetch(string $url, string $api_key): ?array {
    for ($try = 1; $try <= FETCH_TRIES; $try++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ["Authorization: $api_key", "User-Agent: TymOS/1.0"],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            tymos_log('WARN', "Pstryk API HTTP {$code} (proba {$try}/" . FETCH_TRIES . ")");
            if ($body) file_put_contents('/tmp/tymos/pstryk_raw.json', $body);
        } else {
            $data = json_decode($body, true);
            if (isset($data['frames'])) return $data;
            tymos_log('WARN', 'Pstryk API: brak frames w odpowiedzi (proba ' . $try . '/' . FETCH_TRIES
                            . ') — raw dump: /tmp/tymos/pstryk_raw.json');
            file_put_contents('/tmp/tymos/pstryk_raw.json', $body);
        }
        if ($try < FETCH_TRIES) sleep(RETRY_GAP);
    }
    return null;
}

/**
 * Licznik nieudanych BIEGOW w `settings` (przezywa restart Pi, akcja chodzi co 30 min).
 * ERROR leci dokladnie przy FAIL_RUNS_ALERT-tym nieudanym biegu pod rzad (~1,5 h ciszy z API),
 * potem juz nie — jedna awaria = jeden alert.
 */
function pstryk_fail_bump(): void {
    global $db;
    $r     = $db->query("SELECT v FROM settings WHERE k='pstryk_fail_runs'");
    $n     = ($r && $row = $r->fetch_row()) ? (int)$row[0] + 1 : 1;
    $since = date('H:i');
    if ($n === 1) {
        $db->query("REPLACE INTO settings (k,v) VALUES ('pstryk_fail_since','" . $db->real_escape_string($since) . "')");
    } else {
        $s = $db->query("SELECT v FROM settings WHERE k='pstryk_fail_since'");
        if ($s && $row = $s->fetch_row()) $since = $row[0];
    }
    $db->query("REPLACE INTO settings (k,v) VALUES ('pstryk_fail_runs','{$n}')");

    if ($n === FAIL_RUNS_ALERT) {
        tymos_log('ERROR', "Pstryk API nieosiagalne — {$n} nieudane biegi pod rzad (od {$since})");
    }
}

/** Sukces po serii porazek — zeruje licznik i domyka sprawe jednym INFO. */
function pstryk_fail_clear(): void {
    global $db;
    $r = $db->query("SELECT v FROM settings WHERE k='pstryk_fail_runs'");
    $n = ($r && $row = $r->fetch_row()) ? (int)$row[0] : 0;
    if ($n === 0) return;
    if ($n >= FAIL_RUNS_ALERT) tymos_log('INFO', "Pstryk API wrocil po {$n} nieudanych biegach");
    $db->query("UPDATE settings SET v='0' WHERE k='pstryk_fail_runs'");
}

// ===== PSTRYK: jedno zapytanie (yesterday → tomorrow) =====
echo "Fetch Pstryk (pricing+meter_values+cost)...\n";
$url = "https://api.pstryk.pl/integrations/meter-data/unified-metrics/"
     . "?metrics=pricing,meter_values,cost"
     . "&resolution=hour"
     . "&window_start={$yesterday}T00:00:00Z"
     . "&window_end={$tomorrow}T23:59:59Z";

// --recalc-all: przelicz cost_full_g11, cost_full_g12r, kwh_grzalki, cost_full_g12r_grz dla CALEJ historii (bez API),
// stawki i oplaty stale wg roku/miesiaca wiersza, strefy G12r z weekendami i swietami. cost_full_pstryk (z API) nietkniety.
// Uzyte 2026-09-07 (grzalki) i 2026-09-09 (weekendy, stawki z taryf 2026, staly G12r).
if (in_array('--recalc-all', $argv ?? [], true)) {
    $res = $db->query("SELECT ts, kwh_pstryk FROM energa WHERE kwh_pstryk IS NOT NULL ORDER BY ts");
    $n = 0;
    while ($r = $res->fetch_assoc()) {
        [$c11, $c12, $kg, $c12g] = tariff_costs($r['ts'], (float)$r['kwh_pstryk']);
        db_query("UPDATE energa SET cost_full_g11=$c11, cost_full_g12r=$c12, kwh_grzalki=$kg, cost_full_g12r_grz=$c12g WHERE ts='{$r['ts']}'");
        $n++;
    }
    echo "recalc-all: $n godzin\n";
    exit(0);
}

// --backfill-net YYYY-MM-DD: dociagnij z API cost_energy_net_pstryk od podanej daty do dzis, tygodniami (2026-10-01).
$bi = array_search('--backfill-net', $argv ?? [], true);
if ($bi !== false) {
    $from = $argv[$bi + 1] ?? '2026-03-01';
    $n = 0;
    for ($d = $from; $d <= $today; $d = date('Y-m-d', strtotime("$d +7 day"))) {
        $end = min(date('Y-m-d', strtotime("$d +6 day")), $today);
        $bd = pstryk_fetch("https://api.pstryk.pl/integrations/meter-data/unified-metrics/?metrics=cost&resolution=hour"
                         . "&window_start={$d}T00:00:00Z&window_end={$end}T23:59:59Z", $PSTRYK_API_KEY);
        if (!$bd) { echo "$d: fetch failed\n"; continue; }
        foreach ($bd['frames'] as $f) {
            if (!isset($f['metrics']['cost']['energy_cost_net'])) continue;
            $ts = utc_to_local($f['start']);
            $v  = round((float)$f['metrics']['cost']['energy_cost_net'], 6);
            db_query("UPDATE energa SET cost_energy_net_pstryk=$v WHERE ts='$ts'");
            $n++;
        }
        echo "$d..$end ok\n";
        sleep(1);
    }
    echo "backfill-net: $n godzin\n";
    exit(0);
}

$data = pstryk_fetch($url, $PSTRYK_API_KEY);
if (!$data) {
    tymos_log('WARN', 'Pstryk fetch failed — pomijam sekcje Pstryk');
    pstryk_fail_bump();
    goto tge;
}
pstryk_fail_clear();

// Sprawdz czy jutrzejsze ceny to same zera (API nie zna jeszcze cen)
$tomorrow_prices = [];
foreach ($data['frames'] as $frame) {
    if (str_starts_with($frame['start'], $tomorrow)) {
        $tomorrow_prices[] = $frame['metrics']['pricing']['full_price'] ?? 0;
    }
}
$tomorrow_all_zero = empty($tomorrow_prices) || !array_filter($tomorrow_prices, fn($p) => $p != 0);

$count_price = 0;
$count_kwh = 0;

foreach ($data['frames'] as $frame) {
    $start    = $frame['start'];
    $ts_local = utc_to_local($start);
    $ts_date  = substr($ts_local, 0, 10);
    $pricing  = $frame['metrics']['pricing']      ?? [];
    $meter    = $frame['metrics']['meter_values']  ?? [];
    $cost     = $frame['metrics']['cost']          ?? [];

    // --- PRICING ---
    $full_price = $pricing['full_price'] ?? null;
    $is_cheap   = $pricing['is_cheap']   ?? null;

    if ($full_price !== null) {
        // Przyszle ceny (jutro+) = NULL jesli jutrzejsze sa samymi zerami
        if ($ts_date > $today && $tomorrow_all_zero) {
            db_query("INSERT INTO energa (ts, full_price_pstryk) VALUES ('$ts_local',NULL) ON DUPLICATE KEY UPDATE full_price_pstryk=NULL");
        // Pusty frame DST (full_price=0 + is_cheap=null) -> NULL
        } elseif ($full_price == 0 && $is_cheap === null) {
            db_query("INSERT INTO energa (ts, full_price_pstryk) VALUES ('$ts_local',NULL) ON DUPLICATE KEY UPDATE full_price_pstryk=NULL");
        } else {
            $fp = round((float)$full_price, 6);
            db_query("INSERT INTO energa (ts, full_price_pstryk) VALUES ('$ts_local',$fp) ON DUPLICATE KEY UPDATE full_price_pstryk=$fp");
            $count_price++;
        }
    }

    // --- KWH + COST ---
    $kwh = $meter['energy_active_import_register'] ?? null;
    if ($kwh !== null) {
        $kwh          = round((float)$kwh, 6);
        $cost_pstryk  = round((float)($cost['energy_balance_value'] ?? 0), 4);
        // Sam koszt energii netto (TGE, bez dystrybucji/marzy/akcyzy/VAT) — pod gwarancje Pstryka 0,50 zl/kWh netto
        $cost_net     = isset($cost['energy_cost_net']) ? round((float)$cost['energy_cost_net'], 6) : 'NULL';
        [$cost_g11, $cost_g12r, $kwh_grz, $cost_g12r_grz] = tariff_costs($ts_local, $kwh);

        db_query("INSERT INTO energa (ts, kwh_pstryk, cost_full_pstryk, cost_energy_net_pstryk, cost_full_g11, cost_full_g12r, kwh_grzalki, cost_full_g12r_grz)
                  VALUES ('$ts_local',$kwh,$cost_pstryk,$cost_net,$cost_g11,$cost_g12r,$kwh_grz,$cost_g12r_grz)
                  ON DUPLICATE KEY UPDATE kwh_pstryk=$kwh, cost_full_pstryk=$cost_pstryk, cost_energy_net_pstryk=$cost_net, cost_full_g11=$cost_g11, cost_full_g12r=$cost_g12r,
                                          kwh_grzalki=$kwh_grz, cost_full_g12r_grz=$cost_g12r_grz");
        $count_kwh++;
    }
}

echo "Pstryk: $count_price cen, $count_kwh kWh\n";

// ===== TGE FIXING I =====
tge:

// 3 sesje: przedwczoraj/wczoraj/dzis -> dostawa wczoraj/dzis/jutro
$tge_sessions = [
    gmdate('d-m-Y', time() - 172800),
    gmdate('d-m-Y', time() - 86400),
    gmdate('d-m-Y'),
];

foreach ($tge_sessions as $session) {
    echo "Fetch TGE Fixing I (session $session)...\n";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://tge.pl/energia-elektryczna-rdn?dateShow=$session&type=1",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'TymOS/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    if (!$html) {
        tymos_log('WARN', "TGE fetch failed session {$session}");
        sleep(3);
        continue;
    }

    // Parsuj tbody
    if (!preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $m)) {
        echo "TGE: brak tbody (session $session)\n";
        sleep(3);
        continue;
    }

    $tbody = $m[1];
    preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $tbody, $rows);
    $count_tge = 0;

    foreach ($rows[1] as $row) {
        // Szukaj etykiety YYYY-MM-DD_HXX
        if (!preg_match('/(\d{4}-\d{2}-\d{2})_H(\d{2})/', $row, $lm)) continue;
        $delivery_date = $lm[1];
        $hour = (int)$lm[2];

        // H01 = 00:00-01:00, H24 = 23:00-24:00 -> ts = (hour-1):00:00
        $ts_hour = sprintf('%02d', $hour - 1);
        $ts = "$delivery_date $ts_hour:00:00";

        // Fixing I — miedzy komentarzami
        if (!preg_match('/Fixing I start -->(.*?)<!-- Fixing I end/s', $row, $fm)) continue;
        $fixing_raw = strip_tags($fm[1]);
        // TGE separator tysiecy = spacja ("-1 300,00") — PHP (float) urywa parsing na spacji
        $fixing_raw = preg_replace('/\s+/', '', trim($fixing_raw));
        $fixing_raw = str_replace(',', '.', $fixing_raw);

        if ($fixing_raw === '' || !preg_match('/\d/', $fixing_raw)) {
            db_query("INSERT INTO energa (ts, fixing1_tge, full_price_pstryk_est) VALUES ('$ts',NULL,NULL) ON DUPLICATE KEY UPDATE fixing1_tge=NULL, full_price_pstryk_est=NULL");
        } else {
            $fixing = (float)$fixing_raw;
            // PLN/MWh -> PLN/kWh
            $price_kwh = round($fixing / 1000, 6);
            // (fixing1 + dystrybucja 0.0951 + marza 0.08 + akcyza 0.005) * VAT 1.23
            $price_est = round(($fixing / 1000 + 0.1801) * 1.23, 6);
            db_query("INSERT INTO energa (ts, fixing1_tge, full_price_pstryk_est) VALUES ('$ts',$price_kwh,$price_est) ON DUPLICATE KEY UPDATE fixing1_tge=$price_kwh, full_price_pstryk_est=$price_est");
            $count_tge++;
        }
    }

    echo "TGE session $session: $count_tge fixingow\n";
    sleep(3);
}

$db->close();
echo "Done.\n";
