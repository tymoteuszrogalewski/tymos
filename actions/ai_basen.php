#!/usr/bin/env php
<?php
/**
 * ai_basen.php — pompa filtracyjna basenu (dev1036 "Basen Pompa") wg harmonogramu + override.
 * Cron co minute. Zastapil sztywne akcje 101/102 (te wylaczone).
 *
 * Helper force_basen:
 *   'auto' — pompa wg okien czasowych (domyslnie)
 *   'on'   — wymus ON (np. rozprowadzenie chemii; user zdejmuje recznie)
 *   'off'  — wymus OFF (kapiel; po zdjeciu -> auto wraca do harmonogramu)
 *
 * Okna auto (godziny pracy pompy): 08:00-10:30 i 12:00-15:00 (~5,5 h/dobe).
 * Bramka available: nie ruszamy urzadzenia offline (unika spamu MQTT "failed to send").
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'ai_basen: DB ' . $db->connect_error); exit(1); }
function val($q) { global $db; $r = $db->query($q); return ($r && $row = $r->fetch_row()) ? $row[0] : null; }
function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }
function dev_state($id) { return strtoupper((string)val("SELECT JSON_UNQUOTE(JSON_EXTRACT(last_state,'$.state')) FROM devices WHERE id={$id}")); }
function dev_set($id, $payload) {
    $name = val("SELECT device FROM devices WHERE id={$id}");
    if (!$name) return;
    exec('mosquitto_pub -h localhost -t ' . escapeshellarg("zigbee2mqtt/{$name}/set") . ' -m ' . escapeshellarg($payload) . ' > /dev/null 2>&1');
}

$POOL_ID = 1036;

// Okna auto w minutach od polnocy [start, koniec). SYNC z basen_state.inc.php (status widgetu).
$windows = [[8 * 60, 10 * 60 + 30], [12 * 60, 15 * 60]];
$nowMin  = (int)date('G') * 60 + (int)date('i');
$inWindow = false;
foreach ($windows as $w) { if ($nowMin >= $w[0] && $nowMin < $w[1]) { $inWindow = true; break; } }

$force = val("SELECT value FROM helpers WHERE name='force_basen' LIMIT 1");

// Blokada mrozowa (ai_mroz.php): przy przymrozku harmonogram NIE uruchamia pompy, a jesli akurat
// chodzi — gasi ja. Reczne `force_basen='on'` zostaje nietkniete: user swiadomie wymusza prace
// (np. rozprowadzenie chemii) i to on decyduje, czy ryzykuje.
$mroz = ((string)val("SELECT value FROM helpers WHERE name='mroz_blokada' LIMIT 1") === '1');
if ($mroz && $force !== 'on') $force = 'off';
if ($force === 'on')       $want = true;
elseif ($force === 'off')  $want = false;
else                       $want = $inWindow;   // auto

$pumpOn    = (dev_state($POOL_ID) === 'ON');
$pumpAvail = (val("SELECT available FROM devices WHERE id={$POOL_ID}") === '1');

if ($pumpAvail) {
    if ($want  && !$pumpOn) { logMsg("BASEN: pompa ON (" . ($force === 'on' ? 'force' : 'harmonogram') . ")"); dev_set($POOL_ID, '{"state":"ON"}'); }
    if (!$want && $pumpOn)  { logMsg("BASEN: pompa OFF (" . ($force === 'off' ? 'force kapiel' : 'poza oknem') . ")"); dev_set($POOL_ID, '{"state":"OFF"}'); }
}

// === OSWIETLENIE BASENU (dev1035) — override z widgetu (helper force_basen_swiatlo) ===
// auto = harmonogram akcji 99/100 steruje (ai_basen NIE rusza). on/off = wymus i trzymaj co cykl.
// Edge: po zdjeciu force poza harmonogramem swiatlo zostaje w ost. stanie do nast. akcji 99/100 — user moze dac OFF.
$LIGHT_ID   = 1035;
$fbs        = val("SELECT value FROM helpers WHERE name='force_basen_swiatlo' LIMIT 1");
$lightOn    = (dev_state($LIGHT_ID) === 'ON');
$lightAvail = (val("SELECT available FROM devices WHERE id={$LIGHT_ID}") === '1');
if ($lightAvail && $fbs !== 'auto' && $fbs !== null) {
    if ($fbs === 'on'  && !$lightOn) { logMsg("BASEN SWIATLO: ON (force)");  dev_set($LIGHT_ID, '{"state":"ON"}'); }
    if ($fbs === 'off' &&  $lightOn) { logMsg("BASEN SWIATLO: OFF (force)"); dev_set($LIGHT_ID, '{"state":"OFF"}'); }
}

logMsg(sprintf("force=%s okno=%s -> want=%s | pompa=%s%s | swiatlo=%s(%s)",
    $force ?? 'auto', $inWindow ? 'TAK' : 'nie', $want ? 'ON' : 'OFF',
    $pumpOn ? 'ON' : 'OFF', $pumpAvail ? '' : '/offline',
    $fbs ?? 'auto', $lightOn ? 'ON' : 'OFF'));
