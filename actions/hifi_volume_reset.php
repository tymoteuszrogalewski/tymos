#!/usr/bin/env php
<?php
/**
 * hifi_volume_reset.php — glosnosc startowa wiezy Technics. Cron co minute (akcja system_hifi_volume_reset).
 *
 * Po co (2026-10-08, user): ktos slucha glosno, wieza sie wylacza, a wieczorem wlacza sie TV (dzwiek
 * przez OPT) albo cos innego — i nagle huk. Wieza ma zawsze startowac z glosnoscia START_VOL.
 *
 * Jak:
 *   1. W CZUWANIU (settings:/system/hostPower = false) ustawiamy START_VOL z wyprzedzeniem — wieza
 *      wlacza sie juz z ta glosnoscia, bez glosnych pierwszych sekund.
 *   2. Przy PRZEJSCIU czuwanie -> wlaczona ustawiamy START_VOL jeszcze raz, na wypadek gdyby wieza przy
 *      wlaczaniu przywracala wlasna, stara glosnosc. Tu opoznienie do minuty (cron).
 * Poprzedni stan zasilania w helperze hifi_host_power.
 *
 * Brak odczytu (wieza nie odpowiada, np. odlaczona od pradu): NIC nie robimy i nie zmieniamy zapamietanego
 * stanu — po powrocie zadziala zwykla logika.
 */

require_once __DIR__ . '/lib/_log.inc.php';
require_once '/opt/tymos/tymos/inc/hifi.inc.php';

const START_VOL = 16;   // glosnosc startowa (skala 0-100)

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'hifi_volume_reset: DB ' . $db->connect_error); exit(1); }
function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }

$pw  = hifi_get('settings:/system/hostPower');
$vol = hifi_get('player:volume');
if ($pw === null || $vol === null) { logMsg('wieza nie odpowiada - nic nie robie'); exit(0); }

$on   = (bool)($pw['bool_'] ?? true);
$v    = (int)($vol['i32_'] ?? -1);
$r    = $db->query("SELECT value FROM helpers WHERE name='hifi_host_power' LIMIT 1");
$prev = ($r && $row = $r->fetch_row()) ? $row[0] : null;   // '1' / '0' / null (pierwsze uruchomienie)

$now = $on ? '1' : '0';
if ($prev !== $now) {
    $db->query("INSERT INTO helpers (name, label, type, value) VALUES ('hifi_host_power', 'HiFi - zasilanie (ostatni odczyt)', 'text', '{$now}') "
             . "ON DUPLICATE KEY UPDATE value='{$now}'");
}

$why = null;
if (!$on && $v !== START_VOL)                 $why = 'czuwanie';
elseif ($on && $prev === '0' && $v !== START_VOL) $why = 'wlaczenie';

if ($why === null) { logMsg(($on ? 'wlaczona' : 'czuwanie') . ", glosnosc {$v} - bez zmian"); exit(0); }

$ok = hifi_set('player:volume', ['type' => 'i32_', 'i32_' => START_VOL]);
logMsg("{$why}: glosnosc {$v} -> " . START_VOL . ($ok ? '' : ' (BLAD zapisu)'));
if (!$ok) tymos_log('WARN', "hifi_volume_reset: nie udalo sie ustawic glosnosci ({$why})");
