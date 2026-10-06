#!/usr/bin/env php
<?php
/**
 * roleta_salon_otworz_po_sloncu.php — otworz rolete salonu gdy slonce zejdzie z tarasu
 * Lokalizacja: HOME_LAT/HOME_LON z config.inc.php.
 * Otwiera gdy azymut przekroczy AZ_OPEN (slonce za zachodnia sciana domu)
 * LUB elevation spadnie ponizej ELEV_MIN (zima — slonce nisko, nie swieci w taras).
 * TYLKO GEOMETRIA (decyzja usera 2026-09-08): automatyczne otwarcia rolety sa dwa — rano po wschodzie
 * (akcja 33) i tu, po zejsciu slonca z elewacji. Sciezka „30 min bez slonca" (flaga roleta_slonce)
 * USUNIETA: flaga we wrzesniu nie odroznia chmur od slonca (czujnik w cieniu grzeje sie z powietrzem),
 * a po zamknieciu przez akcje 110 otwieralaby rolete 2 min pozniej.
 *
 * DWA ZAKAZY (2026-08-29):
 *   - PO ZACHODZIE (elev <= ELEV_SET) nie otwieramy wcale. Wczesniej elev<=5 spelnialo warunek
 *     takze noca, wiec roleta potrafila pojechac w gore o 21:00, tuz przed tym, jak akcja 34
 *     zamykala ja z powrotem.
 *   - Przez MANUAL_BLOCK po recznym ruszeniu roleta (GUI albo scienny przycisk) nie ruszamy jej
 *     automatem. Skrypt byl od 2026-05-31 calkiem immunny na blokade manualna — teraz respektuje
 *     jej pierwsza godzine, zeby nie odwracac decyzji podjetej przed chwila przy oknie.
 *
 * Kalibracja AZ_OPEN: zwieksz (np. 250) -> otworzy sie pozniej; zmniejsz (np. 240) -> wczesniej.
 * 5° kroku = ok 20-30 min roznicy w godzinie otwarcia.
 *
 * Cron: co 2 min od 11 do 21:58
 * Helper: roleta_otwarta_data (anty-dublowanie, max raz dziennie)
 */

require_once __DIR__ . '/lib/_log.inc.php';

$LAT      = HOME_LAT;
$LON      = HOME_LON;
$AZ_OPEN  = 246.0;   // azymut, powyzej ktorego slonce zachodzi za zachodnia sciane
$ELEV_MIN = 5.0;     // wysokosc slonca, ponizej ktorej nie wpada juz w okna (np. zima)
$ELEV_SET = 0.0;     // ponizej = PO ZACHODZIE, wtedy nie otwieramy wcale (2026-08-29)
$WARM_TOL  = 1.0;    // st. C — o ile na dworze moze byc cieplej niz w salonie, a i tak otwieramy
$MANUAL_BLOCK = 3600;// s — cisza po recznym ruszeniu roleta (2026-08-29)

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

function val($q) { global $db; $r = $db->query($q); return ($r && $row = $r->fetch_row()) ? $row[0] : null; }
function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }
function setHelper($name, $v) {
    global $db;
    $vEsc = $db->real_escape_string($v);
    $db->query("INSERT INTO helpers (name, value) VALUES ('{$name}', '{$vEsc}') ON DUPLICATE KEY UPDATE value='{$vEsc}'");
    $db->query("UPDATE settings SET v=UNIX_TIMESTAMP() WHERE k='tymos_reload'");
}

// Manual lock IGNORED — ta akcja jest celowo odporna na recznie/scienne klikanie rolety.
// Otwarcie po sloncu i tak max raz dziennie (anti-dup ponizej + check pozycji), wiec OK.

// Anty-dublowanie: helper z data ostatniego automatycznego otwarcia
$otwarta_data = val("SELECT value FROM helpers WHERE name='roleta_otwarta_data' LIMIT 1");
if ($otwarta_data === null) { setHelper('roleta_otwarta_data', ''); $otwarta_data = ''; }
$today = date('Y-m-d');

// === NOAA Solar Position (uproszczona, dokladna do ~0.5 stopnia) ===
$now = new DateTime('now', new DateTimeZone('UTC'));
$doy = (int)$now->format('z') + 1;             // dzien roku (1-366)
$utc_hour = (float)$now->format('H') + (float)$now->format('i')/60.0 + (float)$now->format('s')/3600.0;

$gamma = 2 * M_PI / 365.0 * ($doy - 1 + ($utc_hour - 12) / 24.0);

// Deklinacja (rad)
$decl = 0.006918
      - 0.399912 * cos($gamma)     + 0.070257 * sin($gamma)
      - 0.006758 * cos(2*$gamma)   + 0.000907 * sin(2*$gamma)
      - 0.002697 * cos(3*$gamma)   + 0.001480 * sin(3*$gamma);

// Equation of Time (minuty)
$eot = 229.18 * (0.000075
      + 0.001868 * cos($gamma)     - 0.032077 * sin($gamma)
      - 0.014615 * cos(2*$gamma)   - 0.040849 * sin(2*$gamma));

// True solar time (godz)
$solar_time = $utc_hour + $LON / 15.0 + $eot / 60.0;
$hour_angle = deg2rad(15.0 * ($solar_time - 12.0));
$lat_rad = deg2rad($LAT);

// Wysokosc (elevation) i azymut
$sin_elev = sin($lat_rad) * sin($decl) + cos($lat_rad) * cos($decl) * cos($hour_angle);
$elev_rad = asin($sin_elev);
$elev_deg = rad2deg($elev_rad);

$cos_az = (sin($decl) - $sin_elev * sin($lat_rad)) / (cos($elev_rad) * cos($lat_rad));
$cos_az = max(-1.0, min(1.0, $cos_az));
$az_deg = rad2deg(acos($cos_az));
if ($hour_angle > 0) $az_deg = 360.0 - $az_deg;

logMsg("Slonce: az={$az_deg}° elev={$elev_deg}° | progi: AZ_OPEN={$AZ_OPEN}° ELEV_MIN={$ELEV_MIN}°");

// === ZAKAZ 1: po zachodzie nie otwieramy ===
if ($elev_deg <= $ELEV_SET) {
    logMsg("po zachodzie (elev=" . round($elev_deg, 1) . ") — nie otwieram");
    exit(0);
}

// === ZAKAZ 2: cisza po recznym ruszeniu roleta ===
// `roleta_manual_until` to koniec pelnej blokady 3 h, wiec moment klikniecia = until - 10800.
// Nas obowiazuje tylko pierwsza godzina z tej blokady.
$manual_until = (int) val("SELECT value FROM helpers WHERE name='roleta_manual_until' LIMIT 1");
if ($manual_until > 0) {
    $klik = $manual_until - 10800;
    $od_kliku = time() - $klik;
    if ($od_kliku >= 0 && $od_kliku < $MANUAL_BLOCK) {
        logMsg("reczny ruch " . round($od_kliku / 60) . " min temu — czekam do " . round($MANUAL_BLOCK / 60) . " min");
        exit(0);
    }
}

// === CIEPLO: czy otwarcie w ogole zaszkodzi? (2026-08-30) ===
// Roleta trzymana po zejsciu slonca ma sens tylko wtedy, gdy na dworze jest ISTOTNIE cieplej niz
// w salonie — inaczej niczego nie chroni, a dom stoi zaslepiony bez powodu. Dotad decydowal sztywny
// prog `salon < 24` w warunku akcji 95 i to on blokowal otwarcie 30.08: salon trzymal 24,2-24,6
// przez cale popoludnie, wiec skrypt nie byl wolany ani razu od 11:56, mimo ze slonce zeszlo
// z elewacji kilka godzin wczesniej.
//
// Teraz porownujemy DWA punkty: salon (dev684) i realny cien na dworze (dev10). Otwieramy, gdy
//   - w salonie jest juz chlodno (<= komfort_target), ALBO
//   - na dworze nie jest cieplej niz w salonie o wiecej niz WARM_TOL.
// 30.08 delta wynosila +0,5 st. — czyli tyle samo, wiec przy tolerancji 1 st. roleta by pojechala.
//
// BRAK ODCZYTU ktoregokolwiek punktu => NIE blokujemy otwarcia. Slonce i tak zeszlo z okien,
// a zaslepiony dom bez powodu jest gorszy niz pol stopnia zysku ciepla.
$salon   = val("SELECT temperature FROM device684 WHERE temperature IS NOT NULL ORDER BY ts DESC LIMIT 1");
$cien    = val("SELECT temperature FROM device10  WHERE temperature IS NOT NULL ORDER BY ts DESC LIMIT 1");
$komfort = val("SELECT value FROM helpers WHERE name='komfort_target' LIMIT 1");
$cieploOk = true;
if (is_numeric($salon) && is_numeric($cien)) {
    $chlodnoWSrodku = is_numeric($komfort) && ((float)$salon <= (float)$komfort);
    $naDworzeNieCieplej = ((float)$cien <= (float)$salon + $WARM_TOL);
    $cieploOk = $chlodnoWSrodku || $naDworzeNieCieplej;
    if (!$cieploOk) {
        logMsg(sprintf('za cieplo na dworze — salon %.1f, cien %.1f (delta +%.1f > %.1f) — czekam',
            $salon, $cien, $cien - $salon, $WARM_TOL));
        exit(0);
    }
}

// === Warunek: czy slonce zeszlo z okien? ===
// Otwieramy TYLKO po poludniu (azimuth > 180), aby nie reagowac rano
$po_poludniu = ($az_deg > 180);
$kat_ok  = ($az_deg >= $AZ_OPEN) || ($elev_deg <= $ELEV_MIN);
$warunek = $po_poludniu && $kat_ok;

if (!$warunek) {
    logMsg("warunek niespelniony — czekam (po_poludniu=" . (int)$po_poludniu . " kat_ok=" . (int)$kat_ok . ")");
    exit(0);
}

// Juz odpalono dzis?
if ($otwarta_data === $today) {
    logMsg("juz otwarte dzis ({$today})");
    exit(0);
}

// Roleta juz otwarta? (np. user otworzyl recznie)
$pozycja = val("SELECT JSON_EXTRACT(last_state, '\$.position') FROM devices WHERE id=21 LIMIT 1");
if ($pozycja !== null && (int)$pozycja > 10) {
    logMsg("roleta juz otwarta (pozycja={$pozycja}) — zapisuje data, ale nie wysylam MQTT");
    setHelper('roleta_otwarta_data', $today);
    exit(0);
}

// STEMPEL PRZED WYSLANIEM (2026-08-30). Ta akcja jest typu `script`, wiec wysyla MQTT wprost
// przez mosquitto_pub, a nie przez `mqtt_set` demona. Detektor recznego ruchu
// (roleta_detect_manual.php) rozpoznaje ruch systemowy po wpisie w `actions.last_triggered`
// dla akcji z `mqtt_set` na dev21 — nas ten matcher NIE lapal, wiec kazde otwarcie po sloncu
// bylo brane za pchniecie scienne i nakladalo 3-godzinna blokade manualna.
// Skutek byl dotkliwy: blokada zjadala okno akcji 34, wiec roleta nie zamykala sie o zachodzie
// (ostatnie zadzialanie 34 to 28.08, mimo otwarc 29. i 30.).
// Stemplujemy wiec `roleta_cmd_ts` tak samo, jak robi to API przy kliknieciu w widget — detektor
// widzi swiezy stempel (< 5 s) i wychodzi bez nakladania blokady.
$db->query("INSERT INTO helpers (name, value) VALUES ('roleta_cmd_ts', '" . time() . "')
            ON DUPLICATE KEY UPDATE value='" . time() . "'");

// Wyslij MQTT komende otwarcia
$cmd = "mosquitto_pub -h 127.0.0.1 -t 'zigbee2mqtt/Roleta/set' -m '{\"state\":\"OPEN\"}'";
$rc = 0;
exec($cmd, $output, $rc);
if ($rc !== 0) {
    tymos_log('ERROR', "mosquitto_pub failed (rc={$rc})");
    exit(1);
}

setHelper('roleta_otwarta_data', $today);
$az_r = round($az_deg, 1); $el_r = round($elev_deg, 1);
$powod = $kat_ok ? 'kat' : 'brak slonca';
logMsg(sprintf('OTWARTA (%s) — az=%s° elev=%s°, salon=%s cien=%s, data=%s',
    $powod, $az_r, $el_r, is_numeric($salon) ? $salon : '?', is_numeric($cien) ? $cien : '?', $today));
