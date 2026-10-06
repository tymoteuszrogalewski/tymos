#!/usr/bin/env php
<?php
/**
 * roleta_lock_clear.php — czysci flage manualnej blokady rolety po godzinie.
 *
 * Helper `roleta_manual_until` trzyma unix timestamp do kiedy obowiazuje blokada.
 * Helper `roleta_manual_lock` to flaga 0/1 uzywana w warunkach akcji w GUI
 * (np. "Roleta Zamknij Cieplo": warunek `roleta_manual_lock = 0`).
 *
 * Skrypt: gdy `roleta_manual_until <= NOW()` i `roleta_manual_lock != 0` — ustaw na 0.
 * Reload flag tymos_reload zeby helpers_cache w demonie zaktualizowal sie szybko.
 *
 * Cron: co 1 min, akcja GUI "system_roleta_lock_clear".
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

$row = $db->query("SELECT
    (SELECT value FROM helpers WHERE name='roleta_manual_until' LIMIT 1) AS until_ts,
    (SELECT value FROM helpers WHERE name='roleta_manual_lock'  LIMIT 1) AS lock_v")->fetch_assoc();

$until = (int)($row['until_ts'] ?? 0);
$lock  = (string)($row['lock_v'] ?? '0');

if ($until <= time() && $lock !== '0') {
    $db->query("INSERT INTO helpers (name, value) VALUES ('roleta_manual_lock', '0') ON DUPLICATE KEY UPDATE value='0'");
    $db->query("UPDATE settings SET v=UNIX_TIMESTAMP() WHERE k='tymos_reload'");
    tymos_log('INFO', 'manual lock expired — cleared');
}
