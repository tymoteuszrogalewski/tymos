#!/usr/bin/env php
<?php
/**
 * alarm_sim_obecnosc.php — symulacja obecnosci w trybie alarmu NIKOGO (poziom 2).
 * Cron: co 1 minute (akcja TymOS typu script).
 *
 * Swiatlo w przedsionku (dev 870) chodzi cyklem ON_MIN / OFF_MIN w kolko, zeby z zewnatrz
 * dom nie wygladal na pusty. Dziala WYLACZNIE przy `alarm_zazbrojony = 2`:
 *   poziom 0 (rozbrojony) i 1 (NOC, ktos jest w domu) — nic nie robimy.
 *
 * Faza liczona od `settings.sim_obecnosc_start_ts` (moment wejscia w poziom 2), nie od
 * uruchomienia skryptu — dzieki temu restart demona, Pi czy skryptu nie resetuje cyklu.
 *
 * Helper `sim_obecnosc` (0/1) mowi reszcie systemu „to swieci celowo". Bez niego akcja 15
 * „Przedsionek Swiatlo Wylacz" (cron co minute: swiatlo ON + brak ruchu 60 s => OFF) gasilaby
 * symulacje w ciagu minuty. Akcja 15 ma warunek `sim_obecnosc = 0`.
 *
 * UWAGA: helpery siedza w cache demona, wiec po KAZDEJ zmianie `sim_obecnosc` ustawiamy flage
 * `settings.tymos_reload` — inaczej warunek akcji 15 czytalby stara wartosc do 30 min.
 * To 2 reloady na cykl, koszt pomijalny.
 */

require_once __DIR__ . '/lib/_log.inc.php';

$DEV     = 870;   // Przedsionek Swiatlo (ZBMINIR2)
$ON_MIN  = SIM_OBECNOSC_ON_MIN;    // minut swiecenia (config.inc.php)
$OFF_MIN = SIM_OBECNOSC_OFF_MIN;   // minut przerwy

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'alarm_sim_obecnosc: DB ' . $db->connect_error); exit(1); }

function val($q) { global $db; $r = $db->query($q); return ($r && $row = $r->fetch_row()) ? $row[0] : null; }
function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }

function setting_set($k, $v) {
    global $db;
    $db->query("INSERT INTO settings (k, v) VALUES ('" . $db->real_escape_string($k) . "', '"
             . $db->real_escape_string((string)$v) . "') ON DUPLICATE KEY UPDATE v = VALUES(v)");
}

// Helper + flaga reload razem — nigdy osobno, patrz naglowek.
function sim_flag_set($v) {
    global $db;
    $db->query("UPDATE helpers SET value='{$v}' WHERE name='sim_obecnosc'");
    $db->query("UPDATE settings SET v=UNIX_TIMESTAMP() WHERE k='tymos_reload'");
}

function dev_set($id, $payload) {
    $name = val("SELECT device FROM devices WHERE id={$id}");
    if (!$name) return;
    exec('mosquitto_pub -h localhost -t ' . escapeshellarg("zigbee2mqtt/{$name}/set")
        . ' -m ' . escapeshellarg($payload) . ' > /dev/null 2>&1');
}

$level = (int) val("SELECT value FROM helpers WHERE name='alarm_zazbrojony' LIMIT 1");
$sim   = (int) val("SELECT value FROM helpers WHERE name='sim_obecnosc' LIMIT 1");
$state = val("SELECT JSON_UNQUOTE(JSON_EXTRACT(last_state, '$.state')) FROM devices WHERE id={$DEV} LIMIT 1");
$avail = (int) val("SELECT available FROM devices WHERE id={$DEV} LIMIT 1");

// --- poza trybem NIKOGO: posprzataj po sobie raz i wyjdz ---
if ($level !== 2) {
    if ($sim === 1) {
        sim_flag_set(0);
        setting_set('sim_obecnosc_start_ts', '');
        if ($avail && $state === 'ON') dev_set($DEV, '{"state":"OFF"}');
        logMsg("koniec symulacji (poziom alarmu {$level}) -> swiatlo OFF");
    }
    exit(0);
}

// --- wejscie w tryb NIKOGO: zapamietaj poczatek cyklu ---
$start = (int) val("SELECT v FROM settings WHERE k='sim_obecnosc_start_ts' LIMIT 1");
if ($start <= 0) {
    $start = time();
    setting_set('sim_obecnosc_start_ts', $start);
    logMsg("start symulacji obecnosci (cykl {$ON_MIN}/{$OFF_MIN} min)");
}

$cycle = ($ON_MIN + $OFF_MIN) * 60;
$phase = (time() - $start) % $cycle;
$want  = ($phase < $ON_MIN * 60);

if ($sim !== ($want ? 1 : 0)) sim_flag_set($want ? 1 : 0);

// Bramka `available` jak w pozostalych enforce-cronach: offline urzadzenie pomijamy,
// zeby nie spamowac MQTT „failed to send" co minute.
if (!$avail) { logMsg("dev{$DEV} offline — pomijam"); exit(0); }

$curOn = ($state === 'ON');
if ($want && !$curOn)  { dev_set($DEV, '{"state":"ON"}');  logMsg(sprintf("swiatlo ON  (faza %d/%d min)", intdiv($phase, 60), $ON_MIN)); }
if (!$want && $curOn)  { dev_set($DEV, '{"state":"OFF"}'); logMsg(sprintf("swiatlo OFF (faza %d/%d min)", intdiv($phase, 60) - $ON_MIN, $OFF_MIN)); }

exit(0);
