#!/usr/bin/env php
<?php
/**
 * irrigation.php — podlewanie ogrodu, MASZYNA STANU (tick co minute, BEZ sleep na caly cykl).
 * Akcja cron 63 "Podlewanie" (* * * * *). Odporne na pad pradu:
 *  - plan = ABSOLUTNE okna czasowe stref, zapisany w helperze irrigation_state (JSON).
 *  - kazdy tick patrzy ktora strefa ma okno TERAZ -> ona ON, reszta OFF.
 *  - pad pradu: zawory same OFF (power_on_behavior=off); po powrocie tick wznawia strefe
 *    ktorej okno wciaz trwa, strefy z minionym oknem pomija, po ostatnim oknie konczy
 *    (zero lania po nocy, zero kolizji z nastepnym rankiem/wieczorem).
 *  - GWARANCJA JEDNEGO ZAWORU: wlaczajac strefe najpierw OFF wszystkich innych kanalow
 *    obu sterownikow + zwloka na rozwarcie przekaznikow, dopiero potem ON docelowej
 *    (pelne cisnienie na jeden zraszacz, zasilacz zasila tylko jeden elektrozawor).
 *  - switch OFF (irrigation_enabled) -> gasi wszystko i czysci stan (stop teraz, <=1 min).
 * Bez lock-file: kazdy tick jest krotki, demon i tak nie odpala akcji czesciej niz co ~55s.
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

function helper($db, $name) {
    $r = $db->query("SELECT value FROM helpers WHERE name='" . $db->real_escape_string($name) . "'");
    return ($r && $row = $r->fetch_assoc()) ? $row['value'] : null;
}
function setHelper($db, $name, $value) {
    $db->query("UPDATE helpers SET value='" . $db->real_escape_string($value) . "' WHERE name='" . $db->real_escape_string($name) . "'");
}
function logMsg($db, $msg) {
    $db->query("INSERT INTO log (level, source, message) VALUES ('INFO', 'podlewanie', '" . $db->real_escape_string($msg) . "')");
    fwrite(STDOUT, $msg . "\n");
}

// Stan pojedynczego kanalu "DEVID:state_lN" z devices.last_state
function chOn($db, $ch) {
    list($id, $fld) = explode(':', $ch, 2);
    $r = $db->query("SELECT last_state FROM devices WHERE id=" . (int)$id);
    if (!$r || !($row = $r->fetch_assoc())) return false;
    $ls = json_decode($row['last_state'] ?? '{}', true) ?: [];
    return strtoupper($ls[$fld] ?? '') === 'ON';
}
function chSet($db, $ch, $val) {   // $val = 'ON' | 'OFF'
    list($id, $fld) = explode(':', $ch, 2);
    $r = $db->query("SELECT device FROM devices WHERE id=" . (int)$id);
    if (!$r || !($row = $r->fetch_assoc())) return;
    $payload = json_encode([$fld => $val]);
    exec("mosquitto_pub -h localhost -t " . escapeshellarg("zigbee2mqtt/{$row['device']}/set") . " -m " . escapeshellarg($payload));
}

// Wszystkie kanaly (l1-l4) sterownikow uzytych w strefach panelu podlewania.
function allChannels($db) {
    $ids = [];
    $r = $db->query("SELECT DISTINCT dev_channel FROM irrigation_zones WHERE dev_channel IS NOT NULL");
    if ($r) while ($row = $r->fetch_assoc()) { $p = explode(':', $row['dev_channel'], 2); if (count($p) === 2) $ids[(int)$p[0]] = true; }
    $chs = [];
    foreach (array_keys($ids) as $id) for ($n = 1; $n <= 4; $n++) $chs[] = "{$id}:state_l{$n}";
    return $chs;
}

// GWARANCJA JEDNEGO ZAWORU: najpierw OFF wszystkich innych kanalow, zwloka, potem ON $target.
// $target = null -> wszystko OFF.
function enforceSingle($db, $allCh, $target) {
    $turnedOff = false;
    foreach ($allCh as $ch) {
        if ($ch === $target) continue;
        if (chOn($db, $ch)) { chSet($db, $ch, 'OFF'); $turnedOff = true; }
    }
    if ($target === null) return;
    if ($turnedOff) usleep(800000);                    // ~0.8s na rozwarcie przekaznikow zanim zasilimy target
    if (!chOn($db, $target)) chSet($db, $target, 'ON'); // ON docelowej (idempotentnie / wznowienie po pradzie)
}

$now     = time();
// Tryb: auto|manual|off (helper irrigation_mode). Fallback do starego irrigation_enabled gdy pusty (migracja).
$irrMode = helper($db, 'irrigation_mode');
if ($irrMode === null || $irrMode === '') $irrMode = (helper($db, 'irrigation_enabled') === '1') ? 'auto' : 'off';
$stateRaw = helper($db, 'irrigation_state');
$state    = $stateRaw ? (json_decode($stateRaw, true) ?: null) : null;
$allCh    = allChannels($db);

// --- OFF: gasi wszystko i czysci (auto-plan + reczny wybor) ---
if ($irrMode === 'off') {
    if ($state || helper($db, 'irrigation_manual_zone')) {
        enforceSingle($db, $allCh, null);
        setHelper($db, 'irrigation_state', '');
        setHelper($db, 'irrigation_manual_zone', '');
        setHelper($db, 'irrigation_running_zone', '');
        logMsg($db, 'Podlewanie OFF - stop');
    }
    exit(0);
}

// --- MANUAL: auto-plan wygaszony; utrzymuj recznie wybrana sekcje (1 zawor, bez limitu, odporne na pad pradu) ---
if ($irrMode === 'manual') {
    if ($state) setHelper($db, 'irrigation_state', '');   // sprzataj resztki auto-planu
    $manual = helper($db, 'irrigation_manual_zone');
    if ($manual) {
        enforceSingle($db, $allCh, $manual);              // OFF inne -> zwloka -> ON wybranej (co tick = wznowienie po pradzie)
        $nm = null;
        $r = $db->query("SELECT name FROM irrigation_zones WHERE dev_channel='" . $db->real_escape_string($manual) . "' LIMIT 1");
        if ($r && $row = $r->fetch_assoc()) $nm = $row['name'];
        if (helper($db, 'irrigation_running_zone') !== ($nm ?? $manual)) setHelper($db, 'irrigation_running_zone', $nm ?? $manual);
    } else {
        enforceSingle($db, $allCh, null);                 // manual bez wyboru -> wszystko off
        if (helper($db, 'irrigation_running_zone') !== '') setHelper($db, 'irrigation_running_zone', '');
    }
    exit(0);
}

// --- AUTO ---
// Wejscie z trybu manual: zgas ewentualny reczny zawor i wyczysc wybor (raz).
if (helper($db, 'irrigation_manual_zone')) {
    enforceSingle($db, $allCh, null);
    setHelper($db, 'irrigation_manual_zone', '');
    setHelper($db, 'irrigation_running_zone', '');
}

// --- START nowego planu: trafiona minuta rano/wieczor i brak aktywnego planu ---
if (!$state) {
    $nowHM   = date('H:i', $now);
    $morning = helper($db, 'irrigation_morning_hour') ?? '06:00';
    $evening = helper($db, 'irrigation_evening_hour') ?? '20:00';
    $mode = null;
    if ($nowHM === $morning) $mode = 'rano';
    if ($nowHM === $evening) $mode = 'wieczor';
    if ($mode === null) exit(0);

    // Bramka temperatury. Zawory na zewnatrz — otwarcie ich przy mrozie to rozsadzone zlaczki,
    // a woda w gruncie i tak nie idzie do roslin. Trzy warunki, kazdy konczy cykl:
    //   a) brak SWIEZEGO odczytu dworu = nie wiemy, jaka jest temperatura -> nie podlewamy wcale.
    //      dev10 jest bateryjny na slabej galezi mesha (zmierzony rekord przerwy 208 min) -> prog 4 h.
    //   b) rano: minimum nocne ponizej progu przymrozkowego (domyslnie 5 C),
    //   c) wieczor: biezaca temp ponizej EVENING_MIN_C — woda zostaje na noc w wezach i gruncie,
    //      a prognozy nocnej nie mamy, wiec biezaca temp o 20:00 jest jedynym sensownym proxy.
    //      Praktycznie wylacza to podlewanie od pazdziernika, w sezonie nigdy nie zadziala.
    $EVENING_MIN_C = 10.0;
    $OUT_MAX_AGE   = 240;   // min

    // Wspolna blokada mrozowa (ai_mroz.php, helper `mroz_blokada`) — szeroka histereza 2/7 °C.
    // Wlasne progi ponizej zostaja: dotycza pojedynczego okna (zimny poranek/wieczor), a ta
    // blokada jest stanem calego ogrodu i obejmuje tez pompe.
    if ((string)helper($db, 'mroz_blokada') === '1') {
        logMsg($db, 'Blokada mrozowa aktywna - nie podlewam');
        exit(0);
    }

    $ageOut = $db->query("SELECT TIMESTAMPDIFF(MINUTE, MAX(ts), NOW()) AS a FROM device10 WHERE temperature IS NOT NULL")->fetch_assoc()['a'] ?? null;
    if ($ageOut === null || (int)$ageOut > $OUT_MAX_AGE) {
        logMsg($db, "Brak swiezej temp dworu (" . ($ageOut === null ? 'brak danych' : "{$ageOut} min") . ") - nie podlewam");
        exit(0);
    }

    if ($mode === 'rano') {
        $thr = (float)(helper($db, 'irrigation_frost_threshold') ?? 5);
        $r = $db->query("SELECT MIN(temperature) AS tmin FROM device10 WHERE ts >= CURDATE() AND HOUR(ts) < 5 AND temperature IS NOT NULL");
        if ($r && $row = $r->fetch_assoc()) {
            if ($row['tmin'] !== null && (float)$row['tmin'] < $thr) { logMsg($db, "Przymrozki: min noc {$row['tmin']}C (prog {$thr}C) - pomijam rano"); exit(0); }
        }
    } else {
        $tNow = $db->query("SELECT temperature FROM device10 WHERE temperature IS NOT NULL ORDER BY ts DESC LIMIT 1")->fetch_assoc()['temperature'] ?? null;
        if ($tNow !== null && (float)$tNow < $EVENING_MIN_C) {
            logMsg($db, "Zimny wieczor: {$tNow}C (prog {$EVENING_MIN_C}C) - pomijam wieczor");
            exit(0);
        }
    }

    // Zbuduj plan = absolutne okna czasowe stref. Filtr dnia tygodnia: maska `days` (7 znakow 0/1,
    // poz. 0=Pon..6=Nd wg date('N')); domyslnie '1111111' = wszystkie dni. Strefa z 0 na dzis -> pomijana.
    $dow = (int)date('N');   // 1=Pon .. 7=Nd
    $zones = [];
    $r = $db->query("SELECT name, dev_channel, duration_min, days FROM irrigation_zones WHERE enabled=1 AND dev_channel IS NOT NULL AND (mode='" . $db->real_escape_string($mode) . "' OR mode='oba') ORDER BY sort_order, id");
    if ($r) while ($row = $r->fetch_assoc()) {
        $mask = $row['days'] ?? '1111111';
        if (strlen($mask) !== 7) $mask = '1111111';
        if ($mask[$dow - 1] !== '1') continue;   // dzis ta strefa nie podlewa
        $zones[] = $row;
    }
    if (empty($zones)) exit(0);

    $plan = []; $t = $now;
    foreach ($zones as $z) {
        $dur = max(1, (int)$z['duration_min']) * 60;
        $plan[] = ['ch' => $z['dev_channel'], 'name' => $z['name'], 'start' => $t, 'end' => $t + $dur];
        $t += $dur;
    }
    $state = ['mode' => $mode, 'plan' => $plan];
    setHelper($db, 'irrigation_state', json_encode($state));
    logMsg($db, "Start podlewania ({$mode}): " . count($plan) . " stref, do " . date('H:i', $t));
    // fall-through -> zastosowanie stanu ponizej
}

// --- ZASTOSUJ plan: znajdz strefe ktorej okno trwa TERAZ ---
$plan    = $state['plan'];
$lastEnd = $plan[count($plan) - 1]['end'];

if ($now >= $lastEnd) {                                 // caly plan za nami -> koniec
    enforceSingle($db, $allCh, null);
    setHelper($db, 'irrigation_state', '');
    setHelper($db, 'irrigation_running_zone', '');
    logMsg($db, 'Podlewanie zakonczone');
    exit(0);
}

$active = null;
foreach ($plan as $z) { if ($now >= $z['start'] && $now < $z['end']) { $active = $z; break; } }

if ($active === null) {                                 // luka miedzy oknami (nie powinno) -> wszystko OFF
    enforceSingle($db, $allCh, null);
    exit(0);
}

enforceSingle($db, $allCh, $active['ch']);              // OFF inne -> zwloka -> ON aktywnej (1 zawor)
if (helper($db, 'irrigation_running_zone') !== $active['name']) setHelper($db, 'irrigation_running_zone', $active['name']);
exit(0);
