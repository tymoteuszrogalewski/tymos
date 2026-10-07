#!/usr/bin/env php
<?php
/**
 * ai_bufor.php — Grzalka buforowa - decyzja na podstawie cen dynamicznych (Pstryk)
 * grzalka 3kW == ~6,5'C/h
 * Cron: co 5 minut
 *
 * ZMIANA 2026-08-05 — poszerzenie pasma roboczego (kopia poprzedniej wersji: ai_bufor.2026-08-05.php)
 * Zmierzone: strata postojowa bufora ~95 W (2,3 kWh/dobe, UA ~3,0 W/K), czyli 15,8 zl/rok
 * za kazdy stopien sredniej temperatury. Cena prądu waha sie 0,22–2,50 zl/kWh. Wniosek:
 * trzymanie ciepla jest TANIE, brak zapasu jest DROGI — oplaca sie poszerzac pasmo, nie zwezac.
 *   1. SUFIT W GORE: w DIP_HOURS najtanszych godzinach najblizszej doby target T_DIP (63'C),
 *      niezaleznie od progow bezwzglednych. Wczesniej w „plasko drogie" dni (min doby 0,40-0,62)
 *      bufor stal na 58'C i wieczorny rozbior wymuszal dogrzewanie po 1,1 zl/kWh.
 *   2. PODLOGA W DOL: prog „grzej zawsze" 50 -> T_FLOOR (45'C). Bufor dojezdza wieczor bez
 *      awaryjnego grzania w szczycie cenowym.
 * Sufit 65'C zostaje twardym maksimum — STB grzalki wywala ~70'C.
 *
 * ZMIANA 2026-09-02 — strojenie samego DOLKA (kopia poprzedniej: ai_bufor.2026-09-02.php).
 * Trzy poprawki, wszystkie o tym KIEDY i DO ILU ladowac, zadna nie rusza pasma:
 *   1. START LADOWANIA WYCENIONY RAZEM ZE STRATA POSTOJOWA. Sposrod mozliwych startow w dolku
 *      bierzemy ten o najmniejszej sumie cen POMNIEJSZONEJ o TIE_GAIN za kazda godzine zwloki —
 *      godzina wczesniejszego napelnienia to ~0,1 kWh oddane w sciane zbiornika.
 *   2. SUFIT DOLKA WG ROZPIETOSCI DOBY (T_DIP_MIN..T_DIP). W dobie plaskiej cenowo nadmiarowy zapas
 *      wyparuje, zanim bedzie z czego zrobic uzytek — nie ma czego omijac.
 *   3. ZIMA PRZY MROZIE SUFIT NA MAKSIMUM. Strata postojowa idzie w kotlownie, czyli w bryle domu,
 *      i zastepuje cieplo, ktore piec i tak musialby wyprodukowac. Prad w dolku jest przy tym
 *      tanszy od pelletu (`pellet_prog_grzalki`), wiec to zamiana paliwa, nie strata.
 */

require_once __DIR__ . '/lib/_log.inc.php';

const T_FLOOR   = 45;   // podloga komfortu: ponizej tego grzej zawsze, niezaleznie od ceny (bylo 50)
const T_DIP     = 63;   // sufit w dobowym dolku cenowym (margines do STB ~70'C)
const T_MAX     = 65;   // twardy sufit dla TARGETU (liczonego z MIN(gora,dol))
// SUFIT NA SAMA GORE. `buforTemp` to MIN(gora, dol), a dol stale zostaje w tyle — wiec grzanie
// trwa, dopoki dol nie dogoni targetu, a GORA w tym czasie idzie w okolice progu STB.
// Zmierzone na 14 dniach: gora >65 C przez 74 h z 336, >68 C przez 15 h, rekord 69,7.
// Geometria tlumaczy dlaczego: obie grzalki sa PONIZEJ gornego czujnika (1/5 i 2/5 wysokosci,
// czujnik gorny na 3/4), a konwekcja pcha cieplo do gory. Glowica z bimetalem STB siedzi przy
// grzalce, czyli tam, gdzie nie mierzy zaden czujnik.
const TOP_MAX   = 67;   // C — gora bufora nigdy wyzej (3 C zapasu do STB ~70)
const TOP_HYST  = 2.0;  // C — wznawiamy dopiero po spadku o tyle ponizej TOP_MAX
const DIP_HOURS = 3;    // ile najtanszych godzin z najblizszej doby traktujemy jak dolek
const DIP_SPREAD = 0.30; // dolek ma sens tylko gdy w dobie jest o tyle drozsza godzina do ominiecia

// --- STROJENIE DOLKA 2026-09-02 (trzy niezalezne poprawki, wszystkie o CZASIE i WYSOKOSCI ladowania) ---
// 1) REMIS CENOWY NA KORZYSC POZNIEJSZEJ GODZINY. Grosz roznicy nie jest powodem, zeby grzac
//    wczesniej — bufor stygnie 95 W, wiec kazda godzina trzymania to ~0,1 kWh. Gdy w zasiegu
//    TIE_MAX_H jest godzina tansza lub rowna w granicach TIE_EPS, czekamy na nia.
// TIE_GAIN: ile „warta" jest godzina zwloki, wyrazona w tych samych jednostkach co suma cen.
// Strata postojowa 95 W = 0,095 kWh na godzine; odkupienie jej kosztuje ~0,7 zl/kWh, czyli ~0,066 zl.
// Dzielone przez 6 kWh na godzine grzania (DWIE grzalki 3 kW — zakladamy sprawne, tak samo jak
// robi to `heating_rate` w sliding window) = 0,011, zaokraglone w dol do 0,01, zeby nie przeplacac.
const TIE_GAIN      = 0.01;  // zl/kWh na kazda godzine pozniejszego startu
const TIE_MAX_H     = 6;     // h — najdalej tyle wolno przesunac ladowanie
const TIE_LAST_HOUR = 17;    // do tej godziny wlacznie wolno odkladac (wieczor ma zastac bufor pelny)
const TIE_MIN_TEMP  = 50;    // C — ponizej tego nie odkladamy nic, komfort wazniejszy od groszy
// 2) SUFIT DOLKA WG ROZPIETOSCI DOBY. Zapas ponad dobowy rozbior (~6,3 kWh) ma wartosc tylko wtedy,
//    gdy jest co ominac. W dzien plaski cenowo nadmiarowe kWh wyparuje przez sciane zbiornika,
//    zanim ktokolwiek je zuzyje — wtedy celujemy nizej.
const DIP_SPREAD_FULL = 0.80; // zl — przy takiej rozpietosci doby napelniamy do pelna (T_DIP)
const T_DIP_MIN       = 55;   // C — sufit dolka w dobie plaskiej cenowo
// 3) ZIMA PRZY MROZIE SUFIT W GORE. Strata postojowa idzie w kotlownie, czyli do wnetrza bryly,
//    i zastepuje cieplo, ktore i tak trzeba wyprodukowac. Prad w dolku (~0,45) jest przy tym
//    tanszy od pelletu (prog `pellet_prog_grzalki`), wiec to nie strata, tylko zamiana paliwa.
const WINTER_OUT_MAX  = 5.0;  // C — ponizej tej temp zewn strata postojowa przestaje byc strata
const MAX_RUN_MIN = 180;  // min — dluzsza ciagla praca grzalki = grzanie bez sprzezenia zwrotnego (zmierzony rekord normalny: 154 min)
const STALE_MIN   = 120;  // min — odczyt temp bufora starszy niz tyle = brak kontaktu z czujnikiem
                          // (zmierzone na 60 dniach: max normalny odstep raportu dev11/dev12 = 120 min,
                          //  bylo 75 min i 09.08 zablokowaloby grzanie bez powodu)
const NOGAIN_MIN  = 60;   // min — okno kontroli przyrostu przy ciaglym grzaniu
const NOGAIN_DT   = 1.0;  // C — tyle MUSI urosnac gora bufora w oknie NOGAIN_MIN
                          // (zmierzone: przy pelnej mocy gora rosnie 1 C na 3-6 min, wiec 60 min to
                          //  10-15x zapas — zaden normalny rozbior wody tego nie zeruje)
const LOCK_STEPS   = [15, 30, 60];  // min — eskalacja blokady przy powtorkach
const LOCK_RESET_H = 2;   // h normalnej pracy po wygasnieciu blokady -> licznik wraca do najkrotszej

// CENA DO DECYZJI = WYZSZA Z DWOCH. Pstryk potrafi na kilka godzin wystawic cene BEZ oplat
// operatora (najczesciej dla godzin jutrzejszych, zaraz po publikacji) i dopiero pozniej poprawic
// eksport. Taka zanizona cena wygladalaby jak okazja i kazalaby grzac do sufitu za pelna stawke.
// `full_price_pstryk_est` to nasze wlasne oszacowanie z fixingu TGE, policzone tym samym wzorem co
// rachunek — (fixing/1000 + 0,1801) * 1,23 — wiec obie liczby sa porownywalne. Bierzemy wyzsza:
// przy zdrowych danych to i tak Pstryk (roznica rzedu grosza), a przy wybrakowanych ratuje nas TGE.
// GREATEST z NULL daje NULL, stad COALESCE — brak ceny Pstryka nadal znaczy „nie grzej".
const PRICE = "GREATEST(full_price_pstryk, COALESCE(full_price_pstryk_est, full_price_pstryk))";

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

function val($r) { global $db; $q = $db->query($r); return ($q && $row = $q->fetch_row()) ? $row[0] : null; }
function setHelper($name, $v) {
    global $db;
    $db->query("UPDATE helpers SET value='{$v}' WHERE name='{$name}'");
    // sygnal do daemona — przeladuj helpers_cache (inaczej widzi stara wartosc do FORCE_RELOAD 30 min)
    $db->query("UPDATE settings SET v=UNIX_TIMESTAMP() WHERE k='tymos_reload'");
}
function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }

$curHour = (int)date('H');

// Prog grzalki z settings DB (fallback: 0.42)
$pelletProg = (float)(val("SELECT v FROM settings WHERE k='pellet_prog_grzalki' LIMIT 1") ?? '0.42');

// Piec wlaczony? (0=off/lato, 1=on/zima)
$piecOn = val("SELECT value FROM helpers WHERE name='piec_wlaczony' LIMIT 1") === '1';

// === BEZPIECZNIKI GRZALEK ===
// Trzy niezalezne powody, dla ktorych przestajemy grzac (kazdy = blokada z eskalacja 15/30/60 min):
//   1. praca bez przerwy > MAX_RUN_MIN — grzanie bez sprzezenia zwrotnego,
//   2. przy ciaglym grzaniu brak NOWYCH odczytow temp przez NOGAIN_MIN — padl czujnik,
//   3. przy ciaglym grzaniu temp stoi w miejscu (przyrost < NOGAIN_DT) — grzalka nie oddaje ciepla.
// Rozroznienie 3 od rozbioru wody: przy kapieli/pralce temp SPADA (dT < 0) i wtedy NIE blokujemy —
// blokujemy tylko stagnacje (0 <= dT < NOGAIN_DT), bo tak wyglada zamrozony odczyt i martwa grzalka.

function setHelperIns($name, $label, $v) {
    global $db;
    $db->query("INSERT INTO helpers (name, label, type, value) VALUES ('{$name}', '{$label}', 'text', '{$v}') "
             . "ON DUPLICATE KEY UPDATE value='{$v}'");
    $db->query("UPDATE settings SET v=UNIX_TIMESTAMP() WHERE k='tymos_reload'");
}

// Slad decyzji dolka do pozniejszej weryfikacji. Stdout skryptu nie leci NIGDZIE (akcja 36 jest
// typu script, demon nie zbiera wyjscia), a helper widac w admin -> Helpery. Do tabeli `log`
// piszemy TYLKO przy zmianie stanu — inaczej byloby 12 wpisow na godzine.
function dipMark($stan, $msg = null) {
    $prev = (string)val("SELECT value FROM helpers WHERE name='bufor_dip_stan' LIMIT 1");
    if ($prev === $stan) return;
    setHelperIns('bufor_dip_stan', 'Bufor - stan dolka cenowego', $stan);
    if ($msg !== null) tymos_log('INFO', "ai_bufor: {$msg}");
}

// Blokada z eskalacja: pierwszy raz 15 min, przy powtorce 30, potem 60. Po LOCK_RESET_H normalnej
// pracy od konca ostatniej blokady licznik wraca do 15 min (inaczej po tygodniu kazdy zgrzyt
// startowalby od godziny).
function lockout($powod, $opis) {
    $steps = LOCK_STEPS;
    $lvl  = (int)(val("SELECT value FROM helpers WHERE name='grzalka_lock_level' LIMIT 1") ?? 0);
    $last = (int)(val("SELECT value FROM helpers WHERE name='grzalka_lock_last' LIMIT 1") ?? 0);
    if ($last && (time() - $last) > LOCK_RESET_H * 3600) $lvl = 0;
    $mins  = $steps[min($lvl, count($steps) - 1)];
    $until = time() + $mins * 60;

    setHelperIns('grzalka_lockout_until', 'Grzalki - blokada do (epoch)', $until);
    setHelperIns('grzalka_lock_level',    'Grzalki - poziom eskalacji blokady', min($lvl + 1, count($steps) - 1));
    setHelperIns('grzalka_lock_last',     'Grzalki - koniec ostatniej blokady (epoch)', $until);
    setHelper('grzalka_decision', 0);
    setHelper('grzalka_target_temp', 0);

    $msg = "⚠️ Grzałki bufora WYŁĄCZONE: {$powod}.\n{$opis}\n"
         . "Blokada {$mins} min, do " . date('H:i', $until) . " (bez ciepłej wody do tego czasu).";
    $proc = popen('php /opt/tymos/actions/telegram_send.php > /dev/null 2>&1', 'w');
    if ($proc) { fwrite($proc, json_encode(['type' => 'text', 'msg' => $msg, 'chat' => 'alert'])); pclose($proc); }

    tymos_log('WARN', "ai_bufor: {$powod} - OFF + blokada {$mins} min do " . date('Y-m-d H:i:s', $until));
    logMsg("BEZPIECZNIK: {$powod} - OFF, blokada {$mins} min");
    exit(0);
}

$lockUntil = (int)(val("SELECT value FROM helpers WHERE name='grzalka_lockout_until' LIMIT 1") ?? 0);
if ($lockUntil > time()) {
    logMsg("BLOKADA grzalek do " . date('Y-m-d H:i:s', $lockUntil) . " - nie grzej");
    setHelper('grzalka_decision', 0);
    setHelper('grzalka_target_temp', 0);
    exit(0);
}

// --- 1. ciagla praca ponad MAX_RUN_MIN ---
foreach ([3 => 'Bufor Grzałka 1 (dolna, L3)', 1 => 'Bufor Grzałka 2 (górna, L1)'] as $gid => $glabel) {
    $probek = (int)val("SELECT COUNT(*) FROM device{$gid} WHERE ts >= NOW() - INTERVAL " . MAX_RUN_MIN . " MINUTE");
    if ($probek < 60) continue;   // za malo danych w oknie - nie zgaduj
    $przerwy = (int)val("SELECT COUNT(*) FROM device{$gid} WHERE ts >= NOW() - INTERVAL " . MAX_RUN_MIN . " MINUTE AND COALESCE(power,0) <= 50");
    if ($przerwy > 0) continue;   // byla przerwa - praca w normie
    lockout("{$glabel} grzeje bez przerwy ponad " . MAX_RUN_MIN . " min",
            "Rekord normalnej pracy to 154 min, więc to wygląda na grzanie bez sprzężenia zwrotnego "
          . "(zamrożony odczyt temperatury bufora albo zacięty przekaźnik).");
}

// --- 2 i 3. przy ciaglym grzaniu: brak nowych odczytow / brak przyrostu ---
$grzejeCiagle = false;
foreach ([3, 1] as $gid) {
    $probek = (int)val("SELECT COUNT(*) FROM device{$gid} WHERE ts >= NOW() - INTERVAL " . NOGAIN_MIN . " MINUTE");
    if ($probek < NOGAIN_MIN * 0.8) continue;   // dziura w danych o mocy - nie zgaduj
    $przerwy = (int)val("SELECT COUNT(*) FROM device{$gid} WHERE ts >= NOW() - INTERVAL " . NOGAIN_MIN . " MINUTE AND COALESCE(power,0) <= 50");
    if ($przerwy === 0) { $grzejeCiagle = true; break; }
}
if ($grzejeCiagle) {
    $ageTop = val("SELECT TIMESTAMPDIFF(MINUTE, ts, NOW()) FROM device12 WHERE temperature IS NOT NULL ORDER BY ts DESC LIMIT 1");
    if ($ageTop === null || (int)$ageTop > NOGAIN_MIN) {
        lockout("brak odczytu temperatury bufora podczas grzania",
                "Grzałki pracują od co najmniej " . NOGAIN_MIN . " min, a czujnik góry bufora milczy od "
              . ($ageTop === null ? 'zawsze' : "{$ageTop} min") . ". Przy grzaniu temperatura rośnie ~1°C na 4 min, "
              . "więc odczyt musiałby przyjść — wygląda na padnięty czujnik.");
    }
    // Zastoj = temp stoi W CALYM oknie (max - min < NOGAIN_DT). Samo porownanie pierwszy/ostatni
    // klamalo: 02.10 kapiel — 57,6 -> szczyt 59,6 -> spadek do 57,6 dalo dT=0 i blokade w trakcie rozbioru.
    $tFirst = val("SELECT temperature FROM device12 WHERE ts >= NOW() - INTERVAL " . NOGAIN_MIN . " MINUTE AND temperature IS NOT NULL ORDER BY ts ASC LIMIT 1");
    $tLast  = val("SELECT temperature FROM device12 WHERE temperature IS NOT NULL ORDER BY ts DESC LIMIT 1");
    $tRange = val("SELECT MAX(temperature) - MIN(temperature) FROM device12 WHERE ts >= NOW() - INTERVAL " . NOGAIN_MIN . " MINUTE AND temperature IS NOT NULL");
    if (is_numeric($tFirst) && is_numeric($tLast) && is_numeric($tRange)) {
        $dT = (float)$tLast - (float)$tFirst;
        if ($dT >= 0 && (float)$tRange < NOGAIN_DT) {
            lockout("temperatura bufora nie rośnie mimo grzania",
                    "Grzałki pracują od co najmniej " . NOGAIN_MIN . " min, a góra bufora stoi na "
                  . "{$tLast}°C (przyrost " . number_format($dT, 1) . "°C). Normalnie to ~1°C na 4 min. "
                  . "Możliwy zamrożony odczyt albo grzałka, która nie oddaje ciepła.");
        }
    }
}

// Ceny: biezaca godzina + nastepne 5h (window do nagrzania bufora moze trwac 2-3h)
$prices = [];
$r = $db->query("SELECT " . PRICE . " FROM energa WHERE ts >= DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') AND ts < DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') + INTERVAL 6 HOUR ORDER BY ts ASC");
while ($r && $row = $r->fetch_row()) $prices[] = (float)$row[0];

$curCost = $prices[0] ?? null;

// Brak ceny NIE konczy juz pracy skryptu. Kiedys znaczylo to „nie grzej", ale przy dluzszej awarii
// importu Pstryka bufor stygl do zera i konczylo sie zimna woda. Zamiast tego przechodzimy w TRYB
// AWARYJNY: grzejemy wylacznie do podlogi komfortu (T_FLOOR), czyli tyle, zeby dom mial cieply
// kran, i ani stopnia wiecej — bez cen nie wiemy, czy energia jest tania. Decyzja zapada nizej,
// gdy znamy juz swieza temperature bufora.
$noPrice = ($curCost === null);

// Przyszle ceny min (H+1, H+2, H+3)
$cost1h = $prices[1] ?? null;
$cost2h = $cost1h;
$cost3h = $cost1h;
if (isset($prices[2])) $cost2h = min($cost2h, $prices[2]);
$cost3h = $cost2h;
if (isset($prices[3])) $cost3h = min($cost3h, $prices[3]);

// Temp bufora
$buforTemp = (float)val("SELECT temperature FROM device12 ORDER BY ts DESC LIMIT 1");
$buforTempDol = val("SELECT temperature FROM device11 ORDER BY ts DESC LIMIT 1");

if ($buforTemp === 0.0 && val("SELECT COUNT(*) FROM device12") == 0) {
    logMsg("BLAD: brak danych bufor temp - nie grzej");
    setHelper('grzalka_decision', 0);
    setHelper('grzalka_target_temp', 0);
    exit(1);
}

// Odczyt przestarzaly = grzanie na oslep (czujka Zigbee moze wypasc z sieci) - nie grzej.
// Prog STALE_MIN (120) dobrany nad zmierzone maksimum normalnych odstepow raportow (120 min / 60 dni).
$maxAge = (int)(val("SELECT v FROM settings WHERE k='bufor_temp_max_age_min' LIMIT 1") ?? STALE_MIN);
$ageGora = val("SELECT TIMESTAMPDIFF(MINUTE, MAX(ts), NOW()) FROM device12");
if ($ageGora === null || (int)$ageGora > $maxAge) {
    logMsg("BLAD: temp bufora GORA przestarzala ({$ageGora} min > {$maxAge} min) - nie grzej");
    setHelper('grzalka_decision', 0);
    setHelper('grzalka_target_temp', 0);
    exit(0);
}

// temp_dol — jesli dostepna i swieza, uzyj MIN(gora,dol)
$ageDol = val("SELECT TIMESTAMPDIFF(MINUTE, MAX(ts), NOW()) FROM device11");
if ($ageDol === null || (int)$ageDol > $maxAge) {
    logMsg("UWAGA: temp bufora DOL przestarzala ({$ageDol} min) - pomijam");
    $buforTempDol = null;
}

$topTemp = $buforTemp;   // czysty odczyt GORY (dev12) — przed podmiana na MIN(gora,dol)

// Temperatura zewnetrzna do decyzji „zima czy nie" — dev10 „cien" (rurka na drzewie, bezwladnosc,
// stad 240 min swiezosci, tak samo jak STALE_MIN_BY_DEV w daemonie). Brak swiezego odczytu = null,
// czyli zimowy sufit sie NIE podnosi (fail-safe w strone nizszej temperatury).
$outTemp = val("SELECT temperature FROM device10 WHERE ts >= NOW() - INTERVAL 240 MINUTE AND temperature IS NOT NULL ORDER BY ts DESC LIMIT 1");
$outTemp = is_numeric($outTemp) ? (float)$outTemp : null;

if ($buforTempDol !== null && is_numeric($buforTempDol)) {
    $buforTempDol = (float)$buforTempDol;
    if ($buforTempDol < $buforTemp) {
        logMsg("buforTemp=MIN(gora={$buforTemp},dol={$buforTempDol}) → {$buforTempDol}C");
        $buforTemp = $buforTempDol;
    } else {
        logMsg("buforTemp=MIN(gora={$buforTemp},dol={$buforTempDol}) → {$buforTemp}C (gora zimniejsza)");
    }
} else {
    logMsg("UWAGA: buforTempDol={$buforTempDol} (nie liczba, uzywam tylko gory)");
}

// === SUFIT NA GORZE BUFORA — sprawdzany PRZED cala logika cenowa ===
// Musi byc tutaj, a nie na koncu skryptu: sciezki „czekam na dolek" i „bufor juz cieply" konczy
// sie wlasnym exit(), wiec warunek postawiony nizej nigdy by nie zadzialal (zlapane w tescie).
// Histereza przez helper `bufor_top_cutoff` — raz zatrzymani, czekamy az gora spadnie o TOP_HYST,
// zeby nie przelaczac grzalek co cykl przy samym progu.
$topCut   = ((string)val("SELECT value FROM helpers WHERE name='bufor_top_cutoff' LIMIT 1") === '1');
$topLimit = $topCut ? (TOP_MAX - TOP_HYST) : TOP_MAX;
if ($topTemp >= $topLimit) {
    setHelperIns('bufor_top_cutoff', 'Bufor - grzanie wstrzymane sufitem gory', 1);
    setHelper('grzalka_decision', 0);
    setHelper('grzalka_target_temp', 0);
    logMsg("SUFIT GORY: gora={$topTemp}C >= {$topLimit}C — nie grzej (dol=" . ($buforTempDol ?? '?') . ")");
    exit(0);
}
if ($topCut) {
    setHelperIns('bufor_top_cutoff', 'Bufor - grzanie wstrzymane sufitem gory', 0);
    logMsg("SUFIT GORY: gora={$topTemp}C ponizej " . (TOP_MAX - TOP_HYST) . "C — zwalniam blokade");
}

// === TRYB AWARYJNY: brak cen Pstryka (i naszego oszacowania z TGE) ===
// Trzymamy sama podloge komfortu. Helper `bufor_bez_cen` = 1 mowi reszcie systemu (i logom),
// ze decyzja nie ma nic wspolnego z cenami; watchdog `ai_pstryk_watchdog.php` osobno powiadamia
// o awarii importu.
if ($noPrice) {
    $decision = ($buforTemp < T_FLOOR) ? 1 : 0;
    setHelperIns('bufor_bez_cen', 'Bufor - praca bez cen (tryb awaryjny)', 1);
    setHelper('grzalka_decision', $decision);
    setHelper('grzalka_target_temp', T_FLOOR);
    logMsg("BRAK CENY: H={$curHour} — tryb awaryjny, target=" . T_FLOOR . "C, bufor={$buforTemp}C, decyzja={$decision}");
    tymos_log('WARN', "ai_bufor: brak cen — grzeje tylko do podlogi " . T_FLOOR . "C (bufor {$buforTemp}C)");
    exit(0);
}
if ((int)(val("SELECT value FROM helpers WHERE name='bufor_bez_cen' LIMIT 1") ?? 0) === 1) {
    setHelperIns('bufor_bez_cen', 'Bufor - praca bez cen (tryb awaryjny)', 0);
    logMsg('ceny wrocily — koniec trybu awaryjnego');
}

if ($cost1h === null) {
    logMsg("UWAGA: brak przyszlych cen — decyzja bez lookahead");
}

// === KROK 1: ustal target na podstawie ceny ===
$targetTemp = 0;
$decision = 1;

if ($curCost < 0) {
    $targetTemp = 65;   // zbite z 70: zabezpieczenie grzalki (STB) fabryczne ~70C — trzymamy margines
} elseif ($curCost < 0.25) {
    $targetTemp = 60;   // zbite z 65
} elseif ($curCost < $pelletProg) {
    $targetTemp = $piecOn ? 60 : 55;   // lato (piec off): gorny target 60->55 = mniej strat postojowych; zima: 60 (pojemnosc na CO)
} else {
    $targetTemp = T_FLOOR;             // drogo — trzymamy tylko podloge komfortu
}

// === KROK 1b: gdy piec wylaczony, szukaj relatywnego dolka ===
if (!$piecOn && $targetTemp <= T_FLOOR) {
    $minFuture8h = val("SELECT MIN(" . PRICE . ") FROM energa WHERE ts > DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') AND ts <= DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') + INTERVAL 8 HOUR AND full_price_pstryk IS NOT NULL");
    if ($minFuture8h !== null && is_numeric($minFuture8h)) {
        if ($curCost <= (float)$minFuture8h + 0.05) {
            logMsg("PIEC_OFF DOLEK: curCost={$curCost} ~ min8h={$minFuture8h} — target 50→55");
            $targetTemp = 55;
        }
    } else {
        logMsg("PIEC_OFF: brak przyszlych cen 8h — target 50→55");
        $targetTemp = 55;
    }
}

// === KROK 1c: DOLEK DOBOWY — sufit wzgledny zamiast progow bezwzglednych ===
// Jestesmy w jednej z DIP_HOURS najtanszych godzin najblizszej doby? Napelniamy bufor do T_DIP.
// To lapie dni, w ktorych CALA doba jest droga (min 0,40-0,62 zl) i progi 0,25/0,42 nigdy nie odpalaja
// — wtedy bufor stal na 58'C, a wieczorny rozbior wymuszal dogrzewanie po 1,1 zl/kWh.
$dipNow = false;
if ($piecOn && $curCost >= $pelletProg) {
    // Zima przy drogim pradzie: bufor napelnia piec pelletem, nie ma po co grzac elektrycznie.
    logMsg("DOLEK: pominiety (piec ON i cena {$curCost} >= prog pelletu {$pelletProg})");
} else {
    // Okno WYSRODKOWANE: 12h wstecz + 12h w przod. Patrzenie tylko w przod nie dziala po poludniu —
    // ceny na jutro Pstryk publikuje ok. 14:00, wiec o 13:00 horyzont mialby 11h i dolek by przepadl.
    // Przeszlosc jest w bazie zawsze, wiec okno jest pelne o kazdej porze.
    $win = "ts >= DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') - INTERVAL 12 HOUR"
         . " AND ts < DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') + INTERVAL 12 HOUR"
         . " AND full_price_pstryk IS NOT NULL";
    $cheaper = val("SELECT COUNT(*) FROM energa WHERE {$win} AND " . PRICE . " < {$curCost}");
    $horizon = val("SELECT COUNT(*) FROM energa WHERE {$win}");
    $max24h  = val("SELECT MAX(" . PRICE . ") FROM energa WHERE {$win}");

    // Okno musi byc w miare pelne — inaczej „3 najtansze" nic nie znaczy.
    if ($horizon !== null && (int)$horizon >= 18 && $cheaper !== null && $max24h !== null) {
        $spread = (float)$max24h - $curCost;
        if ((int)$cheaper < DIP_HOURS && $spread >= DIP_SPREAD) {
            // SUFIT DOLKA: rosnie liniowo z rozpietoscia doby, od T_DIP_MIN przy DIP_SPREAD
            // do T_DIP przy DIP_SPREAD_FULL. Zapas ponad dobowy rozbior ma wartosc tylko wtedy,
            // gdy jest jaka droga godzine ominac. Zima przy mrozie od razu maksimum.
            $dipTarget = T_DIP_MIN + (T_DIP - T_DIP_MIN) * (($spread - DIP_SPREAD) / (DIP_SPREAD_FULL - DIP_SPREAD));
            $dipTarget = (int)round(min($dipTarget, T_DIP));
            $why = "spread " . round($spread, 2) . " zl";
            if ($piecOn && $outTemp !== null && $outTemp <= WINTER_OUT_MAX) {
                $dipTarget = T_MAX;
                $why = "zima, dwor {$outTemp}C — strata idzie w kotlownie, nie w powietrze";
            }

            // REMIS CENOWY: jesli ladowanie zaczete POZNIEJ kosztuje tyle samo, zacznij pozniej —
            // bufor stygnie 95 W, wiec kazda godzina wczesniejszego napelnienia to ~0,1 kWh w sciane.
            // Porownujemy SUMY za caly czas grzania (jak sliding window nizej), a nie pojedyncze
            // godziny: przy 2-3 h ladowania pozniejszy start wpycha koniec w drozsze godziny.
            // Tolerancja TIE_EPS na kazda godzine zwloki = tyle wlasnie warta jest oszczedzona strata.
            $tieNeed = max(1, (int)ceil(($dipTarget - $buforTemp) / 13.0));
            if ($tieNeed > count($prices)) $tieNeed = count($prices);
            $tieSum = function ($start) use ($prices, $tieNeed) {
                $x = 0.0;
                for ($i = $start; $i < $start + $tieNeed; $i++) $x += $prices[$i];
                return $x;
            };
            // Wybieramy start o najmniejszym KOSZCIE SKORYGOWANYM: suma cen minus zysk ze zwloki.
            // Samo „najtaniej" wybieraloby zawsze teraz albo najtansza godzine, ignorujac to, ze
            // wczesniej napelniony bufor stygnie dluzej; sam bonus za zwloke pchalby ladowanie
            // w nieskonczonosc. Roznica dwoch skladnikow trafia w rzeczywiste optimum.
            $tieNow   = $tieSum(0);
            $tieStart = 0;
            $tieBest  = $tieNow;
            for ($st = 1; $st + $tieNeed <= count($prices) && $st <= TIE_MAX_H; $st++) {
                if ($curHour + $st > TIE_LAST_HOUR) break;
                $score = $tieSum($st) - TIE_GAIN * $st;
                if ($score < $tieBest) { $tieBest = $score; $tieStart = $st; }
            }
            if ($tieStart > 0 && $buforTemp >= TIE_MIN_TEMP) {
                dipMark("odklad:{$tieStart}", sprintf("dolek odlozony o %dh (start %02d:00, suma %.3f vs %.3f teraz)",
                        $tieStart, ($curHour + $tieStart) % 24, $tieSum($tieStart), $tieNow));
                logMsg(sprintf("DOLEK: odkladam o %dh — start o %02d:00, suma za %dh = %.3f (skorygowana %.3f) vs %.3f teraz, bufor %sC dowiezie",
                       $tieStart, ($curHour + $tieStart) % 24, $tieNeed, $tieSum($tieStart), $tieBest, $tieNow, $buforTemp));
            } else {
                $dipNow = true;
                dipMark("grzej:{$dipTarget}", "dolek — grzeje do {$dipTarget}C ({$why})");
                if ($targetTemp < $dipTarget) {
                    logMsg("DOLEK DOBOWY: {$cheaper} tanszych godzin w oknie +/-12h, {$why} — target {$targetTemp}→{$dipTarget}");
                    $targetTemp = $dipTarget;
                }
            }
        }
    } else {
        logMsg("DOLEK: horyzont cen tylko {$horizon}h — pomijam");
    }
}
if (!$dipNow) dipMark('brak');                  // poza dolkiem: helper czyszczony, bez wpisu do log
if ($targetTemp > T_MAX) $targetTemp = T_MAX;   // twardy sufit STB

// === KROK 1.5: lookahead 12h — jesli bedzie bardzo tanio, czekaj z minimum T_FLOOR ===
$bestFuture12h = val("SELECT MIN(" . PRICE . ") FROM energa WHERE ts > DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') AND ts <= DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') + INTERVAL 12 HOUR AND full_price_pstryk IS NOT NULL");
$lookahead12h = false;
if ($bestFuture12h !== null && is_numeric($bestFuture12h)) {
    $bf = (float)$bestFuture12h;
    if ($bf < -0.10) $lookahead12h = true;
    if (!$piecOn && !$lookahead12h && ($curCost - $bf >= 0.20)) $lookahead12h = true;
}
if ($lookahead12h && $dipNow) {
    logMsg("LOOKAHEAD 12h: pominiety — jestesmy w dobowym dolku, taniej juz nie bedzie");
} elseif ($lookahead12h) {
    if ($curCost < 0) {
        logMsg("LOOKAHEAD 12h: min={$bestFuture12h} ale curCost={$curCost} < 0 — grzeje (cena juz ujemna)");
    } elseif ($curCost < (float)$bestFuture12h) {
        logMsg("LOOKAHEAD 12h: min={$bestFuture12h} ale curCost={$curCost} juz tansze — grzeje normalnie");
    } elseif ($buforTemp >= T_FLOOR) {
        logMsg("LOOKAHEAD 12h: min={$bestFuture12h} — czekam na dolek (bufor={$buforTemp}C >= " . T_FLOOR . ")");
        $targetTemp = T_FLOOR;
        $decision = -2;
        setHelper('grzalka_target_temp', $targetTemp);
        setHelper('grzalka_decision', $decision);
        exit(0);
    } else {
        logMsg("LOOKAHEAD 12h: min={$bestFuture12h} — dolek bedzie, ale bufor={$buforTemp}C < " . T_FLOOR . ", grzeje do " . T_FLOOR);
        $targetTemp = T_FLOOR;
    }
}

// === KROK 2: czy czekac? (jesli w najblizszych h bedzie taniej o min 0.05 PLN) ===
$waitThresh = 0.05;
$targetOrig = $targetTemp;

if ($buforTemp < 40) {
    if ($targetTemp < T_FLOOR) $targetTemp = T_FLOOR;
} elseif ($buforTemp < T_FLOOR) {
    // ponizej T_FLOOR zawsze grzej (podloga komfortu), niezaleznie od cen
} elseif ($dipNow) {
    // Dolek dobowy — nie ma na co czekac, taniej w tej dobie nie bedzie.
    logMsg("WINDOW: pominiety — dobowy dolek, grzeje teraz");
} else {
    // SLIDING WINDOW: znajdz N kolejnych godzin o najmniejszej SUMIE cen,
    // gdzie N = czas potrzebny na nagrzanie bufora do targetu (2 grzalki = ~13C/h).
    // Optymalizacja sumy a nie pojedynczej minimum — gdy nagrzanie trwa 2h,
    // "ta + nastepna" moga byc tansze niz "nastepna + jeszcze nastepna".
    $heating_rate = 13.0;  // °C/h dla 2 grzalek 6kW przy buforze 400L
    $hours_needed = max(1, (int)ceil(($targetOrig - $buforTemp) / $heating_rate));
    if ($hours_needed > count($prices)) $hours_needed = count($prices);
    if ($hours_needed >= 1 && count($prices) > $hours_needed) {
        $cur_sum = 0;
        for ($i = 0; $i < $hours_needed; $i++) $cur_sum += $prices[$i];
        $best_sum = $cur_sum;
        $best_start = 0;
        $running = $cur_sum;
        for ($s = 1; $s + $hours_needed <= count($prices); $s++) {
            $running = $running - $prices[$s-1] + $prices[$s+$hours_needed-1];
            if ($running < $best_sum) {
                $best_sum = $running;
                $best_start = $s;
            }
        }
        // Czekaj jesli w innym oknie zaoszczedzimy >= waitThresh per godzine (suma proporcjonalna)
        if ($best_start > 0 && ($cur_sum - $best_sum) >= $waitThresh * $hours_needed) {
            $decision = -1;
            logMsg("WINDOW {$hours_needed}h: best_start=+{$best_start}h sum={$best_sum} vs now={$cur_sum} — czekam");
        } else {
            logMsg("WINDOW {$hours_needed}h: now optimal (best_start=+{$best_start}h sum_now={$cur_sum} sum_best={$best_sum})");
        }
    }
}

// === KROK 3: bufor juz cieplky — nie trzeba grzac ===
if ($buforTemp >= $targetOrig) {
    $decision = ($curCost >= $pelletProg) ? -3 : 0;
    $targetTemp = 0;
}

// === Ustaw helpery TymOS ===
setHelper('grzalka_target_temp', $targetOrig);
setHelper('grzalka_decision', $decision);

logMsg("H={$curHour} cena={$curCost} bufor={$buforTemp}C target={$targetTemp}C decision={$decision}");
