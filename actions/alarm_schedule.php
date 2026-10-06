#!/usr/bin/env php
<?php
/**
 * alarm_schedule.php on|off — automat nocnego poziomu NOC (helper alarm_zazbrojony = 1).
 *
 * on  (akcja cron wieczorem): 0 -> 1
 * off (akcja cron rano):      1 -> 0
 * Godziny sa w harmonogramie akcji w bazie, nie w kodzie.
 *
 * Poziomu 2 (NIKOGO) NIE RUSZA w zadna strone: gdy user wyjechal, automat nie ma prawa
 * ani oslabic alarmu rano, ani udawac ze go zalaczyl wieczorem. Rozbrojenie z poziomu 2
 * robi wylacznie czlowiek przyciskiem w kiosku.
 *
 * Idempotentne — mozna odpalic wielokrotnie, poza przejsciem 0->1 / 1->0 nic nie robi.
 */

require_once __DIR__ . '/lib/_log.inc.php';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['on', 'off'], true)) { tymos_log('ERROR', "alarm_schedule: zly tryb '{$mode}' (on|off)"); exit(1); }

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'alarm_schedule: DB ' . $db->connect_error); exit(1); }

$r   = $db->query("SELECT value FROM helpers WHERE name='alarm_zazbrojony' LIMIT 1");
$cur = ($r && $row = $r->fetch_row()) ? (int)$row[0] : 0;

if ($mode === 'on' && $cur === 0) {
    $db->query("UPDATE helpers SET value='1' WHERE name='alarm_zazbrojony'");
    tymos_log('INFO', 'Alarm NOC zazbrojony automatycznie');
    echo date('c') . " alarm 0 -> 1 (NOC)\n";
} elseif ($mode === 'off' && $cur === 1) {
    $db->query("UPDATE helpers SET value='0' WHERE name='alarm_zazbrojony'");
    tymos_log('INFO', 'Alarm NOC rozbrojony automatycznie');
    echo date('c') . " alarm 1 -> 0 (rozbrojony)\n";
} else {
    echo date('c') . " alarm bez zmian (poziom {$cur}, tryb {$mode})\n";
}
exit(0);
