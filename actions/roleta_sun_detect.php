#!/usr/bin/env php
<?php
/**
 * roleta_sun_detect.php — wykrywa faktyczne naslonecznienie tarasu (strona pld).
 *
 * Problem: akcja "Roleta Zamknij Cieplo" zamykala rolete gdy w salonie >=22C,
 * nawet gdy na dworze nie bylo slonca (pochmurno) — niepotrzebne zamkniecie.
 *
 * Pomysl: porownanie dwoch czujnikow zewnetrznych — slonce vs cien.
 *   - Taras Temperatura (dev 1043) — goly czujnik na kijku 1,5 m, CELOWO w pelnym sloncu:
 *     metalowa koncowka lapie solar gain i idzie powyzej temperatury powietrza.
 *   - Patio Temperatura (dev 10) — czujnik na drzewie w rurce otwartej z gory i z dolu
 *     (oslona radiacyjna z ciagiem kominowym): slonce nigdy na niego nie pada, mierzy
 *     realna temperature powietrza w cieniu. Od 26.08.2026 w tej lokalizacji.
 *
 * Gdy slonce realnie grzeje taras: (slonce - cien) rosnie. Pochmurno: roznica ~0.
 * Flaga `roleta_slonce` (0/1) uzywana jako warunek w akcjach "Roleta Zamknij Cieplo" (35)
 * i "Roleta Zamknij Goraco Rano" (110).
 *
 * Bezwladnosc cienia: rurka wygladza odczyt, wiec przy przejsciu chmury slonce spada od razu
 * a cien stoi — diff chwilowo siada. Histereza to wchlania, dlatego nie mieszamy tu dev527.
 * dev527 (czerpnia reku) NIE jest uzywany: rura sie nagrzewa, to nie byl czysty cien.
 * Histereza zeby flaga nie migala przy przechodzacych chmurach:
 *   ON  gdy diff >= SUN_ON  (3.0 C)
 *   OFF gdy diff <= SUN_OFF (2.0 C)
 *   pomiedzy — trzymaj poprzedni stan.
 *
 * Progi 3.0/2.0 przeniesione 1:1 z poprzedniej pary (dev1043 - dev527), gdzie zmierzono
 *   18-25.08.2026: sloneczne popoludnie 3.0-4.8, pochmurno/rano/wieczorem 0-1.5, noc ~-0.5.
 *   Nowa para (cien w rurce na drzewie) ma dopiero 26-27.08: pochmurne 26.08 dalo 1.0-2.6
 *   (flaga slusznie 0), sloneczny 27.08 od 08:00 juz 3.2. DO STROJENIA po ~5 dniach danych.
 *
 * BRAMKA DZIENNA: w nocy slonce nie grzeje, a diff potrafi skoczyc na artefakcie odczytu
 * (zmierzone 25.08 03:40 = +4.8). Poza sunrise..sunset flaga jest twardo zerowana.
 *
 * Reload flag tymos_reload ustawiana TYLKO przy zmianie flagi (bez spamu, jak lock_clear).
 *
 * Cron: co 1 min, akcja GUI "system_roleta_sun_detect".
 */

require_once __DIR__ . '/lib/_log.inc.php';

const SUN_ON  = 3.0;  // diff (slonce - cien) >= tyle -> slonce
const SUN_OFF = 2.0;  // diff <= tyle                 -> brak slonca
const SUN_LAT = HOME_LAT;
const SUN_LON = HOME_LON;

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

function set_flag($new) {
    global $db;
    $cur = (string)($db->query("SELECT value FROM helpers WHERE name='roleta_slonce' LIMIT 1")->fetch_row()[0] ?? '0');
    if ($new === $cur) return $cur;
    $db->query("INSERT INTO helpers (name, value) VALUES ('roleta_slonce', '{$new}') ON DUPLICATE KEY UPDATE value='{$new}'");
    $db->query("UPDATE settings SET v=UNIX_TIMESTAMP() WHERE k='tymos_reload'");
    return $cur;
}

// === FLAGA GEOMETRII `roleta_po_geometrii` (2026-09-08, user): 1 = slonce zeszlo juz z elewacji
// (po poludniu az >= AZ_OPEN albo elev <= ELEV_MIN — te same progi, co w roleta_salon_otworz_po_sloncu.php),
// 0 = rano/w dzien. Akcja 110 „Zamknij Slonce" ma warunek =0: po geometrii automat juz NIE zamyka
// (otwarcie po geometrii ma zostac do zachodu). Reset na 0 robi sie sam rano (az < 180).
// Liczone TU, bo ten skrypt chodzi co minute caly dzien; formula NOAA 1:1 z skryptu otwierania.
const AZ_OPEN  = 246.0;
const ELEV_MIN = 5.0;
function sun_az_elev(): array {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $doy = (int)$now->format('z') + 1;
    $uh  = (float)$now->format('H') + (float)$now->format('i') / 60.0 + (float)$now->format('s') / 3600.0;
    $g   = 2 * M_PI / 365.0 * ($doy - 1 + ($uh - 12) / 24.0);
    $decl = 0.006918 - 0.399912 * cos($g) + 0.070257 * sin($g) - 0.006758 * cos(2*$g) + 0.000907 * sin(2*$g)
          - 0.002697 * cos(3*$g) + 0.001480 * sin(3*$g);
    $eot  = 229.18 * (0.000075 + 0.001868 * cos($g) - 0.032077 * sin($g) - 0.014615 * cos(2*$g) - 0.040849 * sin(2*$g));
    $ha   = deg2rad(15.0 * ($uh + SUN_LON / 15.0 + $eot / 60.0 - 12.0));
    $lat  = deg2rad(SUN_LAT);
    $sinE = sin($lat) * sin($decl) + cos($lat) * cos($decl) * cos($ha);
    $elev = asin($sinE);
    $cosA = max(-1.0, min(1.0, (sin($decl) - $sinE * sin($lat)) / (cos($elev) * cos($lat))));
    $az   = rad2deg(acos($cosA));
    if ($ha > 0) $az = 360.0 - $az;
    return [$az, rad2deg($elev)];
}
[$az, $elev] = sun_az_elev();
$geo = ($az > 180 && ($az >= AZ_OPEN || $elev <= ELEV_MIN)) ? '1' : '0';
$geoCur = (string)($db->query("SELECT value FROM helpers WHERE name='roleta_po_geometrii' LIMIT 1")->fetch_row()[0] ?? '');
if ($geo !== $geoCur) {
    $db->query("INSERT INTO helpers (name, value, label, type) VALUES ('roleta_po_geometrii', '{$geo}', 'Roleta: slonce za elewacja', 'text') ON DUPLICATE KEY UPDATE value='{$geo}'");
    $db->query("UPDATE settings SET v=UNIX_TIMESTAMP() WHERE k='tymos_reload'");
    tymos_log('INFO', sprintf('roleta_po_geometrii -> %s (az %.0f, elev %.1f)', $geo, $az, $elev));
}

// Poza dniem slonecznym nie ma czego wykrywac — zeruj flage i wyjdz.
$sun = date_sun_info(time(), SUN_LAT, SUN_LON);
if (time() < $sun['sunrise'] + 1800 || time() > $sun['sunset'] - 1800) {
    if (set_flag('0') === '1') tymos_log('INFO', 'roleta_slonce -> cien (poza dniem)');
    exit(0);
}

// Odczyty MUSZA byc swieze. Czujka Zigbee moze wypasc z sieci, a ostatnia wartosc zostaje w
// last_state i wyglada jak zywa — flaga wyliczona z takiej pary zamykalaby (albo trzymala otwarta)
// rolete na oslep. dev1043 na 30 dniach: max NORMALNY odstep raportu 68 min (305 min tylko w awarii
// Zigbee 19.08) -> prog 120 min. dev10 (cien) tez bateryjny i raportuje rzadziej, w rurce ma
// duza bezwladnosc -> prog 240 min, tak jak CIEN_MAX w ai_mroz.php.
function val($q) { global $db; $r = $db->query($q); return ($r && $row = $r->fetch_row()) ? $row[0] : null; }
$slonce = val("SELECT temperature FROM device1043 WHERE temperature IS NOT NULL AND ts >= NOW() - INTERVAL 120 MINUTE ORDER BY ts DESC LIMIT 1");
$cien   = val("SELECT temperature FROM device10 WHERE temperature IS NOT NULL AND ts >= NOW() - INTERVAL 240 MINUTE ORDER BY ts DESC LIMIT 1");

// Brak swiezego odczytu -> nie ruszaj flagi (unikamy falszywego przelaczenia na zlych danych)
if (!is_numeric($slonce) || !is_numeric($cien)) { exit(0); }

$diff = (float)$slonce - (float)$cien;

$new = null;                       // null = bez zmiany (strefa histerezy)
if ($diff >= SUN_ON)  $new = '1';
if ($diff <= SUN_OFF) $new = '0';
if ($new === null) exit(0);

$cur = set_flag($new);
if ($new !== $cur) {
    $state = $new === '1' ? 'SLONCE' : 'cien';
    tymos_log('INFO', sprintf('roleta_slonce -> %s (slonce %.1f - cien %.1f = %.1f)', $state, $slonce, $cien, $diff));
}
