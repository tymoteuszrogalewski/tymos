#!/usr/bin/env php
<?php
/**
 * smieci_check.php — czy smieci sa wystawione przed wywozem (2026-11-12, user zapomnial).
 *
 * Bez rozpoznawania obiektow: porownujemy KONTURY (krawedzie) wycinka "miejsca na smieci" — trawa na
 * prawo od podjazdu, przy drodze, tuz nad dachem Skody na kamerze parkingu — z PULA pustych wycinkow
 * ze wszystkich godzin kilku ostatnich dni bez wywozu (bierzemy najbardziej podobny). Kosz/worek
 * przerywa linie trawa/droga i dodaje wlasny obrys W JEDNYM MIEJSCU; swiatlo, cien i latarnia
 * zmieniaja kontury slabo i rozlanie. Dlatego liczy sie najgorsze okno OKNO_W x OKNO_H, nie caly wycinek.
 * (2026-11-19: porownanie kolorow z ta sama godzina dawalo 10-60% roznicy na pustym — odrzucone.
 * Test na 116 pustych probkach 15-18.11 dzien+noc: max 17,5%; worek papieru 27%.)
 * Tylko parking_hd: na doorbell_hd miejsce zaslania Renault.
 *
 * Tryby (argument):
 *   baseline — akcja cron co godzine: dopisuje wycinek do puli, ALE tylko gdy miejsce na pewno puste
 *              (poza oknem wywozu: od 0:00 dnia przed do 18:00 dnia wywozu — od 2026-11-19 user moze
 *              wystawic juz rano dnia przed). Pula: PULA_DNI ostatnich dni z zapisem, starsze kasowane.
 *   check    — akcja cron 21:00, 22:00, 6:00: przed 12:00 sprawdza wywoz DZIS, od 12:00 JUTRO.
 *   person   — akcja na wykrycie osoby na parkingu (dev 144): tylko w dzien wywozu 4:00-8:00, raz.
 *   stan     — akcja cron co 10 min: ikonka na kamerze — od 0:00 dnia przed wywozem sam kosz w kolorze
 *              frakcji (trzeba wystawic), po wykryciu kosz + zielony haczyk (2026-11-23, user). Bez Telegrama. 2 kolejne trafienia = flaga "stoi" (wieczorny alarm milknie).
 *              Po wykryciu ikonka trzyma sie, az smieciarka zabierze (2 kolejne puste pomiary = flaga
 *              "zabrane"), najpozniej do 23:59 dnia PO wywozie (zima smieciarka bywa dzien pozniej).
 *              Wynik w TMP/smieci_stan.json, czyta go api smieci_state.
 *              Od 2026-11-25 (falszywy haczyk: pusty kosz po szkle uznany za zmieszane na jutro):
 *              - po odbiorze poprzedniego wywozu trafienia na nastepny licza sie dopiero, gdy miejsce bylo
 *                puste PUSTE_PO_ODBIORZE pomiarow z rzedu (kosz musi zniknac i pojawic sie znowu);
 *              - haczyk sie cofa: PUSTE_ODWOLAJ pustych pomiarow z rzedu przed wywozem kasuje flage "stoi";
 *              - szybka zamiana koszy (bez pustej chwili) = user klika 2x pulsujacy kosz (api smieci_ack,
 *                flaga smieci_reczne_<dt>, kamera jej nie odwoluje).
 *
 * Wynik "pusto" = Telegram Wazne ze zdjeciem. Wynik "stoi" = flaga na ten wywoz, dalsze sprawdzenia milkna.
 * Popiol, wodomierz, tekstylia, gabaryty — ignorowane (TYPY).
 * Strojenie: kazdy pomiar laduje w /root/smieci_probki/pomiary.csv + szersze wycinki jpg (tymczasowo).
 */

require_once __DIR__ . '/lib/_log.inc.php';

// Typy z waste_calendar, ktore wystawiamy na trawe (kosz pionowy albo worki).
const TYPY = ['zmieszane', 'bio', 'plastik', 'papier', 'szklo'];

// Wycinek [x, y, w, h] w pikselach pelnej klatki parking_hd (2688x1520): pas drogi + skraj trawy
// nad dachem Skody, tam gdzie stoja smieci. Nizej zaczyna sie dach auta (stoi roznie).
const KAMERA  = 'parking_hd';
const WYCINEK = [1500, 150, 260, 62];
const PROBKA  = [1450, 150, 470, 180];   // szerszy kadr do probek (strojenie), nie do porownania

const SKALA_W     = 128;  // wycinek zmniejszany do tej szerokosci (szum, wydajnosc)
const PROG_SOBEL  = 80;   // |gx|+|gy| po rozmyciu, powyzej = krawedz
const OKNO_W      = 24;   // okno, w ktorym szukamy skupionej zmiany konturow (px po zmniejszeniu)
const OKNO_H      = 20;
const PROG_UDZIAL = 0.22; // udzial zmienionych krawedzi w najgorszym oknie, powyzej = cos stoi
const PULA_DNI    = 3;    // ile ostatnich dni z zapisem bazy bierzemy do porownania
const TRZYMAJ_DNI = 7;    // starsze bazy kasowane
const KLATKI      = 3;    // ile klatek co KLATKI_ODSTEP s; bierzemy MINIMUM (przejezdzajace auto/przechodzien)
const KLATKI_ODSTEP = 4;
const PUSTE_PO_ODBIORZE = 3; // tyle pustych pomiarow stan (co 10 min) z rzedu po odbiorze = poprzedni kosz schowany
const PUSTE_ODWOLAJ     = 2; // tyle pustych z rzedu po wykryciu (przed wywozem) = haczyk odwolany

const GO2RTC  = 'http://127.0.0.1:1984/api/frame.jpeg?src=';
const BAZA    = '/var/lib/tymos/smieci';   // pula baz: parking_hd_RRRRMMDD_HH.png (przezywa reboot)
const PROBKI  = '/root/smieci_probki';     // tymczasowo do strojenia progow
const TMP     = '/tmp/tymos';              // Apache /snap/ -> tu (zdjecie do Telegrama)

$tryb = $argv[1] ?? '';
if (!in_array($tryb, ['baseline', 'check', 'person', 'stan'], true)) {
    fwrite(STDERR, "uzycie: smieci_check.php baseline|check|person|stan\n");
    exit(1);
}

@mkdir(BAZA, 0777, true);
@mkdir(PROBKI, 0777, true);

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_errno) { tymos_log('ERROR', 'smieci_check: brak DB'); exit(1); }

function wywoz($db, $dt) {
    $in = "'" . implode("','", TYPY) . "'";
    $res = $db->query("SELECT type FROM waste_calendar WHERE dt='" . $db->real_escape_string($dt) . "' AND type IN ($in)");
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = $r['type'];
    return $out;
}

$dzis   = date('Y-m-d');
$jutro  = date('Y-m-d', strtotime('+1 day'));
$godz   = (int)date('G');

// ---------- klatki i kontury ----------

function klatka() {
    $jpg = @file_get_contents(GO2RTC . KAMERA);
    if ($jpg === false || strlen($jpg) < 1000) return null;
    $im = @imagecreatefromstring($jpg);
    return $im ?: null;
}

// Wycinek zmniejszony do SKALA_W — to on idzie do puli (png) i do porownania.
function wytnij($im) {
    [$x, $y, $w, $h] = WYCINEK;
    $sh = (int)round($h * SKALA_W / $w);
    $out = imagecreatetruecolor(SKALA_W, $sh);
    imagecopyresampled($out, $im, 0, 0, $x, $y, SKALA_W, $sh, $w, $h);
    return $out;
}

// Mapa krawedzi [y][x] => 1 (Sobel na rozmytej szarosci). null = szara/pusta klatka (zerwany strumien).
function kontury($wyc) {
    $im = imagecreatetruecolor(imagesx($wyc), imagesy($wyc));
    imagecopy($im, $wyc, 0, 0, 0, 0, imagesx($wyc), imagesy($wyc));
    imagefilter($im, IMG_FILTER_GAUSSIAN_BLUR);
    $w = imagesx($im); $h = imagesy($im); $g = []; $s = 0; $s2 = 0;
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $c = imagecolorat($im, $x, $y);
        $v = 0.3 * (($c >> 16) & 255) + 0.59 * (($c >> 8) & 255) + 0.11 * ($c & 255);
        $g[$y][$x] = $v; $s += $v; $s2 += $v * $v;
    }
    $n = $w * $h;
    if (sqrt(max(0, $s2 / $n - ($s / $n) ** 2)) < 4) return null;
    $e = [];
    for ($y = 1; $y < $h - 1; $y++) for ($x = 1; $x < $w - 1; $x++) {
        $gx = ($g[$y-1][$x+1] + 2 * $g[$y][$x+1] + $g[$y+1][$x+1]) - ($g[$y-1][$x-1] + 2 * $g[$y][$x-1] + $g[$y+1][$x-1]);
        $gy = ($g[$y+1][$x-1] + 2 * $g[$y+1][$x] + $g[$y+1][$x+1]) - ($g[$y-1][$x-1] + 2 * $g[$y-1][$x] + $g[$y-1][$x+1]);
        if (abs($gx) + abs($gy) > PROG_SOBEL) $e[$y][$x] = 1;
    }
    return ['e' => $e, 'w' => $w, 'h' => $h];
}

function rozszerz($e) {   // krawedz +-1 px: drobne przesuniecie obrazu to nie zmiana
    $d = [];
    foreach ($e as $y => $row) foreach ($row as $x => $_)
        for ($dy = -1; $dy <= 1; $dy++) for ($dx = -1; $dx <= 1; $dx++) $d[$y + $dy][$x + $dx] = 1;
    return $d;
}

// Udzial zmienionych krawedzi (nowe w $a albo zniklo z $b) w najgorszym oknie OKNO_W x OKNO_H.
function roznica($a, $b) {
    $da = rozszerz($a['e']); $db = rozszerz($b['e']);
    $w = $a['w']; $h = $a['h'];
    $I = array_fill(0, $h + 1, array_fill(0, $w + 1, 0));   // obraz calkowy zmian
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $z = (isset($a['e'][$y][$x]) && !isset($db[$y][$x])) || (isset($b['e'][$y][$x]) && !isset($da[$y][$x])) ? 1 : 0;
        $I[$y+1][$x+1] = $z + $I[$y][$x+1] + $I[$y+1][$x] - $I[$y][$x];
    }
    $best = 0;
    for ($y = 0; $y + OKNO_H <= $h; $y++) for ($x = 0; $x + OKNO_W <= $w; $x++) {
        $c = $I[$y+OKNO_H][$x+OKNO_W] - $I[$y][$x+OKNO_W] - $I[$y+OKNO_H][$x] + $I[$y][$x];
        if ($c > $best) $best = $c;
    }
    return $best / (OKNO_W * OKNO_H);
}

// Pula: bazy z PULA_DNI ostatnich dni, w ktorych byl zapis (przerwy na wywoz nie oprozniaja puli).
function pula() {
    $pliki = glob(BAZA . '/' . KAMERA . '_????????_??.png') ?: [];
    $dni = array_unique(array_map(fn($f) => substr(basename($f, '.png'), -11, 8), $pliki));
    rsort($dni);
    $dni = array_slice($dni, 0, PULA_DNI);
    $out = [];
    foreach ($pliki as $f) {
        if (!in_array(substr(basename($f, '.png'), -11, 8), $dni, true)) continue;
        $im = @imagecreatefrompng($f);
        $k = $im ? kontury($im) : null;
        if ($k) $out[] = $k;
    }
    return $out;
}

function wynik_pula($k, $pula) {
    if (!$k || !$pula) return null;
    $min = null;
    foreach ($pula as $b) { $u = roznica($k, $b); if ($min === null || $u < $min) $min = $u; }
    return $min;
}

// Probka do strojenia: szerszy kadr w pelnej rozdzielczosci.
function probka($im, $nazwa) {
    [$x, $y, $w, $h] = PROBKA;
    $c = imagecrop($im, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
    if ($c) imagejpeg($c, PROBKI . "/{$nazwa}_" . KAMERA . '.jpg', 90);
}

function pomiar_csv($tryb, $udzial, $info) {
    $f = PROBKI . '/pomiary.csv';
    $nowy = !is_file($f);
    $l = ($nowy ? "czas;tryb;kamera;udzial;info\n" : '')
       . date('Y-m-d H:i:s') . ";$tryb;" . KAMERA . ';' . ($udzial === null ? '' : round($udzial, 4)) . ";$info\n";
    @file_put_contents($f, $l, FILE_APPEND);
}

// ---------- baseline ----------

// Wywoz $d wykryty jako wystawiony, ale smieciarka jeszcze nie zabrala (kamera dalej cos widzi).
function czeka_na_odbior($db, $d) {
    return wywoz($db, $d) && is_file(TMP . "/smieci_ok_{$d}") && !is_file(TMP . "/smieci_zabrane_{$d}");
}
$wczoraj = date('Y-m-d', strtotime('-1 day'));

// Poprzedni wywoz (do 3 dni przed $dt) z wystawionym koszem, po ktorym miejsce jeszcze nie bylo puste
// (brak flagi smieci_pusto_po_X). Dopoki jest, trafienia na $dt sie nie licza: pusty kosz po poprzednim
// wywozie (szklo rano, bio dzien przed zmieszanymi) wyglada dla kamery tak samo jak nowy (2026-11-25).
function blokada_poprzedni($dt) {
    for ($i = 1; $i <= 3; $i++) {
        $x = date('Y-m-d', strtotime("$dt -$i day"));
        if (is_file(TMP . "/smieci_ok_{$x}")) return is_file(TMP . "/smieci_pusto_po_{$x}") ? null : $x;
    }
    return null;
}

if ($tryb === 'baseline') {
    // Zamrozone tez, dopoki po wywozie cos stoi — inaczej pusty kosz trafilby do puli jako "puste miejsce".
    $zamrozone = wywoz($db, $jutro) || ($godz < 18 && wywoz($db, $dzis))
              || czeka_na_odbior($db, $dzis) || czeka_na_odbior($db, $wczoraj);
    $im = klatka();
    if (!$im) { pomiar_csv('baseline', null, 'brak klatki'); exit(0); }
    probka($im, date('Ymd_H'));
    $wyc = wytnij($im);
    $k = kontury($wyc);
    $udz = wynik_pula($k, pula());   // na pustym powinno byc nisko — kontrola progu
    pomiar_csv('baseline', $udz, $zamrozone ? 'zamrozone' : ($k ? 'zapis' : 'szara klatka'));
    if (!$zamrozone && $k) {
        imagepng($wyc, BAZA . '/' . KAMERA . '_' . date('Ymd_H') . '.png');
        $granica = date('Ymd', strtotime('-' . TRZYMAJ_DNI . ' days'));
        foreach (glob(BAZA . '/' . KAMERA . '_????????_??.png') ?: [] as $f)
            if (substr(basename($f, '.png'), -11, 8) < $granica) @unlink($f);
    }
    exit(0);
}

// ---------- check / person / stan ----------

// Stan dla ikonki: json zapisywany atomowo, api smieci_state tylko go czyta.
function zapisz_stan($st) {
    $f = TMP . '/smieci_stan.json';
    @file_put_contents("$f.tmp", json_encode($st + ['ts' => time()]));
    @rename("$f.tmp", $f);
}

if ($tryb === 'person') {
    if ($godz < 4 || $godz >= 8) exit(0);
    $dt = $dzis;
} elseif ($tryb === 'stan') {
    // Kolejnosc: dzisiejszy wywoz jeszcze nie wykryty (rano) -> dzisiejszy czeka na smieciarke
    // -> jutrzejszy -> wczorajszy czeka na smieciarke (zima bywa dzien pozniej).
    $odbior = false;
    if ($godz < 12 && wywoz($db, $dzis) && !is_file(TMP . "/smieci_ok_{$dzis}")) $dt = $dzis;
    elseif (czeka_na_odbior($db, $dzis))   { $dt = $dzis;    $odbior = true; }
    elseif (wywoz($db, $jutro))            { $dt = $jutro; }
    elseif (czeka_na_odbior($db, $wczoraj)) { $dt = $wczoraj; $odbior = true; }
    else $dt = $jutro;
} else {
    $dt = $godz >= 12 ? $jutro : $dzis;
}
$typy = wywoz($db, $dt);
if (!$typy) {
    if ($tryb === 'stan') zapisz_stan(['dt' => null, 'typy' => [], 'stoi' => false, 'trafien' => 0]);
    exit(0);
}

$flaga_ok = TMP . "/smieci_ok_{$dt}";
$old = @json_decode(@file_get_contents(TMP . '/smieci_stan.json'), true) ?: [];
if ($tryb === 'stan' && is_file($flaga_ok) && empty($odbior) && is_file(TMP . "/smieci_reczne_{$dt}")) {
    // Potwierdzone recznie (klik na ekranie) — kamera tego nie odwoluje.
    zapisz_stan(['dt' => $dt, 'typy' => $typy, 'stoi' => true, 'trafien' => 2, 'reczne' => true,
                 'pustych' => (int)($old['pustych'] ?? 0)]);
    exit(0);
}
if ($tryb !== 'stan' && is_file($flaga_ok) && empty($odbior)) exit(0);    // juz widzielismy wystawione
if ($tryb === 'person') {
    $flaga_p = TMP . "/smieci_person_{$dt}";
    if (is_file($flaga_p)) exit(0);                    // rano sprawdzamy na osobe tylko raz
    @touch($flaga_p);
}

$pula = pula();
$min = null; $ostatnia = null;
for ($i = 0; $i < KLATKI; $i++) {
    if ($i) sleep(KLATKI_ODSTEP);
    $im = klatka();
    if (!$im) continue;
    $ostatnia = $im;
    $u = wynik_pula(kontury(wytnij($im)), $pula);
    if ($u !== null && ($min === null || $u < $min)) $min = $u;
    if ($i === 0 && $tryb !== 'stan') probka($im, date('Ymd_His') . "_{$tryb}");
}
$stoi = $min !== null && $min > PROG_UDZIAL;
$opis = ($min === null ? '?' : round($min * 100) . '%') . ', pula ' . count($pula);
pomiar_csv($tryb, $min, implode('+', $typy) . " $dt pula=" . count($pula));

// Puste pomiary stan z rzedu, niezaleznie od wywozu (brak obrazu = bez zmian).
$pustych = $min === null ? (int)($old['pustych'] ?? 0) : ($stoi ? 0 : (int)($old['pustych'] ?? 0) + 1);
if ($tryb === 'stan' && !empty($odbior)) {
    // Czekamy na smieciarke: ikonka zostaje, az 2 kolejne pomiary nic nie widza (brak obrazu = nic nie zmieniamy).
    $pudla = ($min !== null && !$stoi) ? ((($old['dt'] ?? '') === $dt ? (int)($old['pudla'] ?? 0) : 0) + 1) : 0;
    if ($pudla >= 2) {
        @touch(TMP . "/smieci_zabrane_{$dt}");
        tymos_log('INFO', "smieci_check(stan): zabrane ($opis) " . implode('+', $typy) . " $dt");
    }
    zapisz_stan(['dt' => $dt, 'typy' => $typy, 'stoi' => $pudla < 2, 'trafien' => 2, 'pudla' => $pudla,
                 'zabrane' => $pudla >= 2, 'pustych' => $pustych]);
    exit(0);
}
if ($tryb === 'stan') {
    $poprz = blokada_poprzedni($dt);
    if ($poprz && $pustych >= PUSTE_PO_ODBIORZE) {
        // Miejsce dluzej puste = kosz po poprzednim wywozie schowany (smieciarka zabrala na pewno).
        if (!is_file(TMP . "/smieci_zabrane_{$poprz}")) @touch(TMP . "/smieci_zabrane_{$poprz}");
        @touch(TMP . "/smieci_pusto_po_{$poprz}");
        tymos_log('INFO', "smieci_check(stan): po wywozie $poprz miejsce puste ($opis), czekamy na " . implode('+', $typy) . " $dt");
        $poprz = null;
    }
    if (is_file($flaga_ok)) {
        // Wykryte wczesniej, ale miejsce znow puste: falszywe trafienie albo kosz schowany — haczyk odwolany.
        if ($pustych >= PUSTE_ODWOLAJ) {
            @unlink($flaga_ok);
            tymos_log('INFO', "smieci_check(stan): wystawione ODWOLANE ($opis, $pustych puste z rzedu) " . implode('+', $typy) . " $dt");
            zapisz_stan(['dt' => $dt, 'typy' => $typy, 'stoi' => false, 'trafien' => 0, 'pustych' => $pustych]);
        } else {
            zapisz_stan(['dt' => $dt, 'typy' => $typy, 'stoi' => true, 'trafien' => 2, 'pustych' => $pustych]);
        }
        exit(0);
    }
    // Jedno trafienie moze byc cieniem albo przechodniem — flaga dopiero po 2 kolejnych.
    $traf = ($stoi && !$poprz) ? ((($old['dt'] ?? '') === $dt ? (int)($old['trafien'] ?? 0) : 0) + 1) : 0;
    if ($traf >= 2) {
        @touch($flaga_ok);
        if ($ostatnia) probka($ostatnia, date('Ymd_His') . '_stan_ok');
        tymos_log('INFO', "smieci_check(stan): wystawione ($opis) na " . implode('+', $typy) . " $dt");
    }
    zapisz_stan(['dt' => $dt, 'typy' => $typy, 'stoi' => $traf >= 2, 'trafien' => $traf, 'pustych' => $pustych,
                 'blokada' => $poprz]);
    exit(0);
}

$poprz = $stoi ? blokada_poprzedni($dt) : null;
if ($stoi && !$poprz) {
    @touch($flaga_ok);
    tymos_log('INFO', "smieci_check($tryb): wystawione ($opis) na " . implode('+', $typy) . " $dt");
    exit(0);
}

$kiedy = $dt === $dzis ? 'Dziś' : 'Jutro';
$co    = implode(' + ', $typy);
$msg   = $poprz
    ? "🗑️ {$kiedy} wywóz: {$co} — przy drodze coś stoi, ale to może być pusty kosz po wywozie "
      . date('d.m', strtotime($poprz)) . ". Jeśli {$co} wystawione — dotknij 2× kosz na ekranie."
    : ($min !== null
    ? "🗑️ {$kiedy} wywóz: {$co} — nic nie stoi na trawie. Wystaw!"
    : "🗑️ {$kiedy} wywóz: {$co} — nie mogę sprawdzić (brak obrazu albo pustej bazy). Sprawdź sam.");
tymos_log('INFO', "smieci_check($tryb): " . ($poprz ? "stoi, ale blokada po $poprz" : ($min !== null ? 'pusto' : 'nieznane')) . " ($opis)");

// Zdjecie: pelna klatka parkingu, zeby od razu bylo widac miejsce.
$payload = ['type' => 'text', 'msg' => $msg, 'chat' => 'alert', 'alarm' => true];
if ($ostatnia) {
    $nazwa = 'smieci_alert.jpg';
    imagejpeg($ostatnia, TMP . "/{$nazwa}.tmp", 85);
    @rename(TMP . "/{$nazwa}.tmp", TMP . "/{$nazwa}");
    $payload = ['type' => 'photo', 'url' => 'https://127.0.0.1/snap/' . $nazwa, 'caption' => $msg,
                'nodelay' => true, 'chat' => 'alert', 'alarm' => true];
}
$proc = popen('php /opt/tymos/actions/telegram_send.php > /dev/null 2>&1', 'w');
if ($proc) { fwrite($proc, json_encode($payload)); pclose($proc); }
