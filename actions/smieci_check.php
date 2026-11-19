#!/usr/bin/env php
<?php
/**
 * smieci_check.php — czy smieci sa wystawione przed wywozem (2026-11-12, user zapomnial).
 *
 * Bez rozpoznawania obiektow: porownujemy wycinek "miejsca na smieci" (trawa na prawo od podjazdu,
 * przy drodze) z obrazem bazowym z TEJ SAMEJ GODZINY (to samo swiatlo latarni / slonca).
 * Cos "wyroslo" na trawie = wystawione. Dwie kamery: parking_hd (z przodu, auto moze zaslonic)
 * i doorbell_hd (z boku, skraj kadru).
 *
 * Tryby (argument):
 *   baseline — akcja cron co godzine: zapisuje wycinki jako baze dla danej godziny, ALE tylko gdy
 *              miejsce na pewno puste (poza oknem wywozu: od 0:00 dnia przed do 18:00 dnia wywozu —
 *              od 2026-11-19 user moze wystawic juz rano dnia przed, wiec cala ta doba jest zamrozona).
 *   check    — akcja cron 21:00, 22:00, 6:00: przed 12:00 sprawdza wywoz DZIS, od 12:00 JUTRO.
 *   person   — akcja na wykrycie osoby na parkingu (dev 144): tylko w dzien wywozu 4:00-8:00, raz.
 *   stan     — akcja cron co 10 min: ikonka na kamerze (kosz w kolorze frakcji + zielony haczyk), gdy
 *              wystawione. Bez Telegrama. 2 kolejne trafienia = flaga "stoi" (wieczorny alarm milknie).
 *              Wynik w TMP/smieci_stan.json, czyta go api smieci_state.
 *
 * Wynik "pusto" = Telegram Wazne ze zdjeciem. Wynik "stoi" = flaga na ten wywoz, dalsze sprawdzenia milkna.
 * Popiol, wodomierz, tekstylia, gabaryty — ignorowane (TYPY).
 * Strojenie: kazdy pomiar laduje w /root/smieci_probki/pomiary.csv + wycinki jpg (tymczasowo).
 */

require_once __DIR__ . '/lib/_log.inc.php';

// Typy z waste_calendar, ktore wystawiamy na trawe (kosz pionowy albo worki).
const TYPY = ['zmieszane', 'bio', 'plastik', 'papier', 'szklo'];

// Wycinki [x, y, w, h] w pikselach pelnej klatki HD. Gorna czesc obejmuje kawalek drogi,
// bo wysoki kosz wystaje ponad trawe. parking: od x=1450, zeby nie lapac kosza sasiada na drodze.
// doorbell: od x=2300, zeby nie lapac Renault na podjezdzie.
const KAMERY = [
    'parking_hd'  => [1450, 150, 470, 180],
    'doorbell_hd' => [2300, 1000, 250, 200],
];

const SKALA_W     = 96;   // wycinek zmniejszany do tej szerokosci przed porownaniem (szum, wydajnosc)
const PROG_PIKSEL = 70;   // suma |dR|+|dG|+|dB| po wyrownaniu jasnosci, powyzej = piksel zmieniony
const PROG_UDZIAL = 0.025; // udzial zmienionych pikseli, powyzej = cos stoi (test 12.11: worek ~2%, kosz ~4%; stroic z pomiary.csv)
const KLATKI      = 3;    // ile klatek co KLATKI_ODSTEP s; bierzemy MINIMUM (przejezdzajace auto/przechodzien)
const KLATKI_ODSTEP = 4;

const GO2RTC  = 'http://127.0.0.1:1984/api/frame.jpeg?src=';
const BAZA    = '/var/lib/tymos/smieci';   // bazy godzinowe (male jpg, przezywaja reboot)
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
$hh     = date('H');

// ---------- klatki i porownanie ----------

function klatka($cam) {
    $jpg = @file_get_contents(GO2RTC . $cam);
    if ($jpg === false || strlen($jpg) < 1000) return null;
    $im = @imagecreatefromstring($jpg);
    return $im ?: null;
}

function wytnij($im, $cam) {
    [$x, $y, $w, $h] = KAMERY[$cam];
    $sh = (int)round($h * SKALA_W / $w);
    $out = imagecreatetruecolor(SKALA_W, $sh);
    imagecopyresampled($out, $im, 0, 0, $x, $y, SKALA_W, $sh, $w, $h);
    return $out;
}

function srednie($im) {
    $w = imagesx($im); $h = imagesy($im); $s = [0, 0, 0];
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $c = imagecolorat($im, $x, $y);
        $s[0] += ($c >> 16) & 255; $s[1] += ($c >> 8) & 255; $s[2] += $c & 255;
    }
    $n = $w * $h;
    return [max(1, $s[0] / $n), max(1, $s[1] / $n), max(1, $s[2] / $n)];
}

// Udzial zmienionych pikseli. Jasnosc kazdego kanalu wyrownana do bazy (latarnia/zachmurzenie).
function roznica($a, $baza) {
    $w = imagesx($a); $h = imagesy($a);
    if ($w !== imagesx($baza) || $h !== imagesy($baza)) return null;
    $ma = srednie($a); $mb = srednie($baza);
    $k = [$mb[0] / $ma[0], $mb[1] / $ma[1], $mb[2] / $ma[2]];
    $zm = 0;
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $c = imagecolorat($a, $x, $y); $d = imagecolorat($baza, $x, $y);
        $s = abs((($c >> 16) & 255) * $k[0] - (($d >> 16) & 255))
           + abs((($c >> 8) & 255) * $k[1] - (($d >> 8) & 255))
           + abs(($c & 255) * $k[2] - ($d & 255));
        if ($s > PROG_PIKSEL) $zm++;
    }
    return $zm / ($w * $h);
}

// Probka do strojenia: wycinek w pelnej rozdzielczosci (nie zmniejszony).
function probka($im, $cam, $nazwa) {
    [$x, $y, $w, $h] = KAMERY[$cam];
    $c = imagecrop($im, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
    if ($c) imagejpeg($c, PROBKI . "/{$nazwa}_{$cam}.jpg", 90);
}

function pomiar_csv($tryb, $cam, $udzial, $info) {
    $f = PROBKI . '/pomiary.csv';
    $nowy = !is_file($f);
    $l = ($nowy ? "czas;tryb;kamera;udzial;info\n" : '')
       . date('Y-m-d H:i:s') . ";$tryb;$cam;" . ($udzial === null ? '' : round($udzial, 4)) . ";$info\n";
    @file_put_contents($f, $l, FILE_APPEND);
}

// ---------- baseline ----------

if ($tryb === 'baseline') {
    // Okno wywozu: od 0:00 dnia przed do 18:00 dnia wywozu baza zamrozona (moze cos stac).
    $zamrozone = wywoz($db, $jutro) || ($godz < 18 && wywoz($db, $dzis));
    foreach (array_keys(KAMERY) as $cam) {
        $im = klatka($cam);
        if (!$im) { pomiar_csv('baseline', $cam, null, 'brak klatki'); continue; }
        $wyc = wytnij($im, $cam);
        probka($im, $cam, date('Ymd_H'));
        $plik = BAZA . "/{$cam}_{$hh}.png";
        $udz = is_file($plik) ? roznica($wyc, imagecreatefrompng($plik)) : null;
        pomiar_csv('baseline', $cam, $udz, $zamrozone ? 'zamrozone' : 'zapis');
        if (!$zamrozone) imagepng($wyc, $plik);
    }
    exit(0);
}

// ---------- check / person ----------

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
    // Dzis do 12:00 (po wywozie kosz jeszcze stoi pusty — to juz nie "wystawione"), potem jutro.
    $dt = ($godz < 12 && wywoz($db, $dzis)) ? $dzis : $jutro;
} else {
    $dt = $godz >= 12 ? $jutro : $dzis;
}
$typy = wywoz($db, $dt);
if (!$typy) {
    if ($tryb === 'stan') zapisz_stan(['dt' => null, 'typy' => [], 'stoi' => false, 'trafien' => 0]);
    exit(0);
}

$flaga_ok = TMP . "/smieci_ok_{$dt}";
if ($tryb === 'stan' && is_file($flaga_ok)) {
    zapisz_stan(['dt' => $dt, 'typy' => $typy, 'stoi' => true, 'trafien' => 2]);
    exit(0);
}
if (is_file($flaga_ok)) exit(0);                       // juz widzielismy wystawione
if ($tryb === 'person') {
    $flaga_p = TMP . "/smieci_person_{$dt}";
    if (is_file($flaga_p)) exit(0);                    // rano sprawdzamy na osobe tylko raz
    @touch($flaga_p);
}

$wyniki = [];   // cam => min udzial (null = brak bazy/klatki)
$ostatnie = []; // cam => pelna klatka (do zdjecia)
foreach (array_keys(KAMERY) as $cam) {
    $plik = BAZA . "/{$cam}_{$hh}.png";
    if (!is_file($plik)) { $wyniki[$cam] = null; pomiar_csv($tryb, $cam, null, "brak bazy $hh"); continue; }
    $baza = imagecreatefrompng($plik);
    $min = null;
    for ($i = 0; $i < KLATKI; $i++) {
        if ($i) sleep(KLATKI_ODSTEP);
        $im = klatka($cam);
        if (!$im) continue;
        $ostatnie[$cam] = $im;
        $wyc = wytnij($im, $cam);
        $u = roznica($wyc, $baza);
        if ($u !== null && ($min === null || $u < $min)) $min = $u;
        if ($i === 0 && $tryb !== 'stan') probka($im, $cam, date('Ymd_His') . "_{$tryb}");
    }
    $wyniki[$cam] = $min;
    pomiar_csv($tryb, $cam, $min, implode('+', $typy) . " $dt");
}

$znane = array_filter($wyniki, fn($u) => $u !== null);
$stoi  = array_filter($znane, fn($u) => $u > PROG_UDZIAL);
$opis  = implode(', ', array_map(fn($c, $u) => $c . '=' . ($u === null ? '?' : round($u * 100) . '%'),
                                 array_keys($wyniki), $wyniki));

if ($tryb === 'stan') {
    // Jedno trafienie moze byc cieniem albo przechodniem — flaga dopiero po 2 kolejnych.
    $old = @json_decode(@file_get_contents(TMP . '/smieci_stan.json'), true) ?: [];
    $traf = $stoi ? ((($old['dt'] ?? '') === $dt ? (int)($old['trafien'] ?? 0) : 0) + 1) : 0;
    if ($traf >= 2) {
        @touch($flaga_ok);
        foreach ($ostatnie as $cam => $im) probka($im, $cam, date('Ymd_His') . '_stan_ok');
        tymos_log('INFO', "smieci_check(stan): wystawione ($opis) na " . implode('+', $typy) . " $dt");
    }
    zapisz_stan(['dt' => $dt, 'typy' => $typy, 'stoi' => $traf >= 2, 'trafien' => $traf]);
    exit(0);
}

if ($stoi) {
    @touch($flaga_ok);
    tymos_log('INFO', "smieci_check($tryb): wystawione ($opis) na " . implode('+', $typy) . " $dt");
    exit(0);
}

$kiedy = $dt === $dzis ? 'Dziś' : 'Jutro';
$co    = implode(' + ', $typy);
$msg   = $znane
    ? "🗑️ {$kiedy} wywóz: {$co} — nic nie stoi na trawie. Wystaw!"
    : "🗑️ {$kiedy} wywóz: {$co} — nie mogę sprawdzić (brak obrazu bazowego). Sprawdź sam.";
tymos_log('INFO', "smieci_check($tryb): " . ($znane ? 'pusto' : 'nieznane') . " ($opis)");

// Zdjecie: pelna klatka parkingu (albo dzwonka), zeby od razu bylo widac miejsce.
$foto = $ostatnie['parking_hd'] ?? ($ostatnie['doorbell_hd'] ?? null);
$payload = ['type' => 'text', 'msg' => $msg, 'chat' => 'alert', 'alarm' => true];
if ($foto) {
    $nazwa = 'smieci_alert.jpg';
    imagejpeg($foto, TMP . "/{$nazwa}.tmp", 85);
    @rename(TMP . "/{$nazwa}.tmp", TMP . "/{$nazwa}");
    $payload = ['type' => 'photo', 'url' => 'https://127.0.0.1/snap/' . $nazwa, 'caption' => $msg,
                'nodelay' => true, 'chat' => 'alert', 'alarm' => true];
}
$proc = popen('php /opt/tymos/actions/telegram_send.php > /dev/null 2>&1', 'w');
if ($proc) { fwrite($proc, json_encode($payload)); pclose($proc); }
