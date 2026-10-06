#!/usr/bin/env php
<?php
/**
 * ai_wymienniki.php — WYMIENNIKI WODA-POWIETRZE (chlodnice latem / nagrzewnice zima), POZA reku
 * (reku ma ai_rekuperator.php). Ta sama jednostka chlodzi (woda studnia) lub grzeje (woda bufor).
 * Cron co minute. Bramka nadrzedna: helper tryb_klimatu='chlodz' (inaczej wszystko OFF).
 *
 * === CHŁODNICA #1 (SALON) — dziala ===
 *   Zawor  dev1042 (MINI-ZBRBS, state OPEN/CLOSE) + Wentylatory dev1045 (S60 ON/OFF).
 *   Termostat na komfort (helper komfort_target = C), NIEZALEZNY od reku (wlasne zrodlo zimna = studnia).
 *   ON (zawor OPEN + wentylatory ON) gdy salon >= C+0.5; OFF (zawor CLOSE + wentylatory OFF) gdy salon <= C.
 *   Histereza (miedzy progami trzymaj stan wg biezacego). Publikuje tylko przy zmianie (idempotentnie).
 *
 * === WENTYLATOR FREE-COOLING (dev1044) — dziala ===
 *   fi100 w przepuscie za lodowka (~90 m3/h), wciaga chlodne powietrze z zewn. ON gdy salon>komfort
 *   I dwor(cien dev10) < salon. Histereza. Niezalezny od reku i chlodnicy wodnej (3. droga chlodzenia).
 *
 * === CHŁODNICA #2 (NAWIEW/POSTREKU) — zawor dev1052 obslugiwany (sprzet wpinany fizycznie na dniach) ===
 *   Zawor dev1052 (MINI-ZBRBS OPEN/CLOSE) = woda studnia na chlodnice nawiewu reku (wlasny elektrozawor).
 *   Chlodzi az WSZYSTKIE punkty osiagna cel: salon<=C, srednia wywiewow (dev527 extract)<=C+NV_MARGIN,
 *   dev849 <=C+LIWIA_MARGIN. Dopoki ktorykolwiek powyzej -> chlodzi. Punkt bez danych pomijany.
 *   TODO: czujnik w korytarzu poddasza; widget wielopunktowy; nagrzewanie zima (bufor, zawor 3-drogowy reczny).
 *   (Smart-ramp reku gdy nawiew-po-postreku < wywiew -> patrz ai_rekuperator.php, tez TODO.)
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'ai_wymienniki: DB ' . $db->connect_error); exit(1); }
function val($q) { global $db; $r = $db->query($q); return ($r && $row = $r->fetch_row()) ? $row[0] : null; }
function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }
function dev_state($id) { return strtoupper((string)val("SELECT JSON_UNQUOTE(JSON_EXTRACT(last_state,'$.state')) FROM devices WHERE id={$id}")); }
function dev_set($id, $payload) {
    $name = val("SELECT device FROM devices WHERE id={$id}");
    if (!$name) return;
    exec('mosquitto_pub -h localhost -t ' . escapeshellarg("zigbee2mqtt/{$name}/set") . ' -m ' . escapeshellarg($payload) . ' > /dev/null 2>&1');
}

// Odczyt tylko gdy SWIEZY. Czujka Zigbee moze wypasc z sieci, a ostatnia wartosc zostaje w bazie
// i wyglada jak zywa — sterowanie na takim odczycie to sterowanie na oslep (awaria 2026-08-19).
// FRESH_MIN dobrany nad zmierzone maksimum normalnych odstepow raportow (60 dni): salon/reku/dev849
// <= 75 min. Zwrot null = brak kontaktu -> logika wyzej sama gasi urzadzenia (want=false).
// Wylaczeniem i powiadomieniem zajmuje sie ai_failsafe.php, tutaj tylko NIE STERUJEMY na starych danych.
const FRESH_MIN = 120;

// === NOCNA CISZA (2026-08-30) ===
// Po 22:00 wentylatory maja milczec, o ile nie jest naprawde goraco. Chodzi o to, zeby nie szumialy
// przez cala noc, gdy do celu brakuje juz tylko ulamka stopnia — w sypialni slychac i chlodnice,
// i przewiew za lodowka. Powyzej progu chlodzenie dziala normalnie: upal wygrywa z cisza.
// Bramka NIE dotyczy zaworow (1042 nie halasuje sam z siebie, a nawiew 1052 jedzie z reku, ktory
// i tak chodzi) — gasimy tylko to, co realnie szumi: wentylatory salonu i przewiew kuchni.
// BRAK ODCZYTU salonu albo komfortu => ciszy NIE wlaczamy, bo nie wiemy, czy jest blisko celu;
// przy braku danych i tak nizej wychodzi want=false, wiec urzadzenia i tak nie ruszaja.
const NIGHT_FROM   = 22;    // godzina, od ktorej obowiazuje cisza
const NIGHT_TO     = 6;     // godzina, o ktorej cisza sie konczy
const NIGHT_MARGIN = 1.0;   // ile stopni nad komfortem uznajemy jeszcze za "wystarczajaco dobrze"
function fresh($tab, $col, $max = FRESH_MIN) {
    $age = val("SELECT TIMESTAMPDIFF(MINUTE, MAX(ts), NOW()) FROM {$tab} WHERE {$col} IS NOT NULL");
    if ($age === null || (int)$age > $max) return null;
    return val("SELECT {$col} FROM {$tab} WHERE {$col} IS NOT NULL ORDER BY ts DESC LIMIT 1");
}

$tryb     = val("SELECT value FROM helpers WHERE name='tryb_klimatu' LIMIT 1");
$coolMode = ($tryb === 'chlodz');

// === CHŁODNICA #1 SALON ===
$salon     = fresh('device684', 'temperature');
$C         = val("SELECT value FROM helpers WHERE name='komfort_target' LIMIT 1");
$hour       = (int)date('G');
$isNight    = ($hour >= NIGHT_FROM || $hour < NIGHT_TO);
$nightQuiet = $isNight && is_numeric($salon) && is_numeric($C)
              && ((float)$salon <= (float)$C + NIGHT_MARGIN);

$valveOpen  = (dev_state(1042) === 'OPEN');
$fansOn     = (dev_state(1045) === 'ON');
$active     = $fansOn;   // wskaznik "aktualnie chlodzimy" (histereza)
// Bramka available: NIE ruszamy urzadzen offline (i tak nie da sie sterowac; unika spamu Z2M
// "failed to send" co minute, gdy np. zawor jeszcze niepodlaczony/offline).
$valveAvail = (val("SELECT available FROM devices WHERE id=1042") === '1');
$fansAvail  = (val("SELECT available FROM devices WHERE id=1045") === '1');
// CHŁODNICA #2 NAWIEW (postreku): zawor dev1052 (woda studnia na chlodnice nawiewu reku).
$nvValveOpen = (dev_state(1052) === 'OPEN');
$nvAvail     = (val("SELECT available FROM devices WHERE id=1052") === '1');
$extract     = fresh('device527', 'extract_air_temperature');   // srednia wywiewow = mieszany powrot z domu

if ($coolMode && is_numeric($salon) && is_numeric($C)) {
    $salon = (float)$salon; $C = (float)$C;
    $want = $active ? ($salon > $C) : ($salon >= $C + 0.5);   // start C+0.5, stop C
} else {
    $want = false;   // nie-chlodz / brak danych -> wylacz wszystko
}

// Cisza nocna PRZED recznym override — swiadome wymuszenie z widgetu ma byc silniejsze.
if ($nightQuiet) $want = false;

// RECZNY override z widgetu panel_heat (helper tryb_chlodnice): on=wymus chlodzenie salon,
// off=wymus stop (zawor CLOSE + wentylatory OFF), auto=logika termostatu wyzej.
$trybChlodnice = val("SELECT value FROM helpers WHERE name='tryb_chlodnice' LIMIT 1");
if ($trybChlodnice === 'on')       $want = true;
elseif ($trybChlodnice === 'off')  $want = false;

// === CHŁODNICA #2 NAWIEW (postreku) — zawor dev1052 (woda studnia na chlodnice nawiewu reku) ===
// Chlodzi DOPOKI ktorykolwiek punkt powyzej celu; stop gdy WSZYSTKIE osiagniete:
//   salon <= C, srednia wywiewow (dev527 extract) <= C+NV_MARGIN, dev849 <= C+LIWIA_MARGIN.
// Histereza +0.5 (anti-migotanie). Punkt bez danych = pomijany. Docelowo widget wielopunktowy.
$liwia        = fresh('device849', 'temperature');
$NV_MARGIN    = 1.0;   // "prawie docelowa" dla sredniej wywiewow
$LIWIA_MARGIN = 0.0;   // dev849 <= C (komfort, bez zapasu)
if ($coolMode && is_numeric($salon) && is_numeric($C) && is_numeric($extract)) {
    $extractF = (float)$extract;
    $sNeed = $nvValveOpen ? ($salon    > $C)              : ($salon    >= $C + 0.5);
    $eNeed = $nvValveOpen ? ($extractF > $C + $NV_MARGIN) : ($extractF >= $C + $NV_MARGIN + 0.5);
    $lNeed = false;
    if (is_numeric($liwia)) {
        $liwiaF = (float)$liwia;
        $lNeed = $nvValveOpen ? ($liwiaF > $C + $LIWIA_MARGIN) : ($liwiaF >= $C + $LIWIA_MARGIN + 0.5);
    }
    $wantNV = $sNeed || $eNeed || $lNeed;
} else {
    $wantNV = false;
}
// Osobny override nawiewu (widget: Chłodnica nawiew)
$trybChlodniceNawiew = val("SELECT value FROM helpers WHERE name='tryb_chlodnice_nawiew' LIMIT 1");
if ($trybChlodniceNawiew === 'on')       $wantNV = true;
elseif ($trybChlodniceNawiew === 'off')  $wantNV = false;

if ($want) {
    if ($valveAvail && !$valveOpen) { logMsg("SALON: OTWIERAM zawor (salon={$salon} >= C+0.5)"); dev_set(1042, '{"state":"OPEN"}'); }
    if ($fansAvail  && !$fansOn)    { logMsg("SALON: wentylatory ON"); dev_set(1045, '{"state":"ON"}'); }
} else {
    if ($valveAvail && $valveOpen)  { logMsg("SALON: ZAMYKAM zawor"); dev_set(1042, '{"state":"CLOSE"}'); }
    if ($fansAvail  && $fansOn)     { logMsg("SALON: wentylatory OFF"); dev_set(1045, '{"state":"OFF"}'); }
}

// Zawor NAWIEW (dev1052)
if ($nvAvail) {
    if ($wantNV && !$nvValveOpen)  { logMsg("NAWIEW: OTWIERAM zawor (salon={$salon} extract={$extract} liwia={$liwia} C={$C})"); dev_set(1052, '{"state":"OPEN"}'); }
    if (!$wantNV && $nvValveOpen)  { logMsg("NAWIEW: ZAMYKAM zawor"); dev_set(1052, '{"state":"CLOSE"}'); }
}

// === WENTYLATOR FREE-COOLING „Kuchnia Przewiew" (dev1044) — fi80 w przepuscie za lodowka ===
// Nastawione na ZYSK LODOWKI + bank chlodu: chlodzi kuchnie/dom pokad na dworze chlodniej niz salon,
// az salon spadnie do FC_FLOOR (~20°C = jesien/zimna noc -> starczy). Odczepione od komfortu (latem nie wyziebi salonu).
$fcOn    = (dev_state(1044) === 'ON');
$fcAvail = (val("SELECT available FROM devices WHERE id=1044") === '1');
// T_out = cien (dev10 realne powietrze) swiezy <1h, fallback reku outdoor (dev527)
// dev10 jest bateryjny na slabej galezi mesha — zmierzony rekord normalnej przerwy 208 min,
// wiec prog 4 h (przy 1 h gasilby free-cooling srednio raz w tygodniu bez powodu).
$cienRow = $db->query("SELECT temperature, UNIX_TIMESTAMP(ts) AS ts FROM device10 WHERE temperature IS NOT NULL ORDER BY ts DESC LIMIT 1")->fetch_assoc();
$Tout = ($cienRow && (time() - (int)$cienRow['ts'] < 240 * 60)) ? (float)$cienRow['temperature'] : null;
if ($Tout === null) { $t = val("SELECT outdoor_air_temperature FROM device527 WHERE outdoor_air_temperature IS NOT NULL ORDER BY ts DESC LIMIT 1"); $Tout = is_numeric($t) ? (float)$t : null; }

$FC_FLOOR = 20.0;   // dolny limit salonu — ponizej gasimy (nie przeziebiamy domu; jesien/zimna noc)
if ($coolMode && is_numeric($salon) && is_numeric($Tout)) {
    $salonF = (float)$salon;
    // hold: dopoki dwor ~chlodniejszy (do +0.5) i salon nad podloga; start: dwor wyraznie chlodniejszy i salon > podloga+1
    $wantFC = $fcOn ? ($Tout <= $salonF + 0.5 && $salonF >  $FC_FLOOR)
                    : ($Tout <= $salonF - 0.5 && $salonF >= $FC_FLOOR + 1.0);
} else {
    $wantFC = false;
}
if ($nightQuiet) $wantFC = false;

// RECZNY override z widgetu panel_heat (helper tryb_freecool): on/off wymusza smigielko dev1044, auto=logika wyzej.
$trybFreecool = val("SELECT value FROM helpers WHERE name='tryb_freecool' LIMIT 1");
if ($trybFreecool === 'on')       $wantFC = true;
elseif ($trybFreecool === 'off')  $wantFC = false;
if ($fcAvail) {
    if ($wantFC && !$fcOn)  { logMsg("FREE-COOL: wentylator ON (dwor={$Tout} < salon={$salon})"); dev_set(1044, '{"state":"ON"}'); }
    if (!$wantFC && $fcOn)  { logMsg("FREE-COOL: wentylator OFF"); dev_set(1044, '{"state":"OFF"}'); }
}

logMsg(sprintf("tryb=%s salon=%s C=%s dwor=%s%s | SALON %s (zawor=%s%s fans=%s%s) | FREE-COOL %s (fan=%s%s)",
    $tryb ?? '?', is_numeric($salon) ? $salon : '?', is_numeric($C) ? $C : '?', is_numeric($Tout) ? $Tout : '?',
    $nightQuiet ? ' | CISZA NOCNA' : ($isNight ? ' | noc, ale za cieplo' : ''),
    $want ? 'CHLODZ' : 'off',
    $valveOpen ? 'OPEN' : 'CLOSE', $valveAvail ? '' : '/offline',
    $fansOn ? 'ON' : 'OFF', $fansAvail ? '' : '/offline',
    $wantFC ? 'ON' : 'off', $fcOn ? 'ON' : 'OFF', $fcAvail ? '' : '/offline'));

// Snapshot stanow do klimat_ai — historia „kiedy chodzilo" dla wykresu w widgecie pogodowym (panel_home).
// Stany czytane na starcie tego przebiegu (last_state), wiec opoznienie <= 1 min.
$db->query(sprintf("INSERT INTO klimat_ai (chlodnica_salon, nawiew, freecool) VALUES (%d, %d, %d)",
    $fansOn ? 1 : 0, $nvValveOpen ? 1 : 0, $fcOn ? 1 : 0));

logMsg(sprintf("NAWIEW %s (zawor=%s%s | salon=%s extract=%s liwia=%s C=%s)",
    $wantNV ? 'CHLODZ' : 'off', $nvValveOpen ? 'OPEN' : 'CLOSE', $nvAvail ? '' : '/offline',
    is_numeric($salon) ? $salon : '?', is_numeric($extract) ? $extract : '?', is_numeric($liwia) ? $liwia : '?', is_numeric($C) ? $C : '?'));
