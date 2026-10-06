#!/usr/bin/env php
<?php
/**
 * roleta_detect_manual.php — wykrywa fizyczny scienny przycisk rolety.
 *
 * Wywolywany przez akcje GUI typu state: trigger device21.motor_run_status,
 * value in [Forward, Reverse]. Tlumaczenie:
 *   - GUI klik (api/roleta_move|stop) zapisuje helper `roleta_cmd_ts = NOW()`.
 *     Roleta dostaje komende, motor rusza, Zigbee push wraca, akcja state
 *     fire'uje TEN skrypt po ~1-3 s. Jezeli `roleta_cmd_ts` swieze (<5s) —
 *     to byl GUI klik, blokada juz ustawiona przez API, nic nie rob.
 *   - W przeciwnym razie motor ruszyl bez naszej komendy = scienny przycisk
 *     albo komenda z innego zrodla. Ustaw `roleta_manual_until = NOW()+10800`
 *     i `roleta_manual_lock = 1`.
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

$cmd_ts = (int)($db->query("SELECT value FROM helpers WHERE name='roleta_cmd_ts' LIMIT 1")->fetch_row()[0] ?? 0);
$now = time();

if ($now - $cmd_ts <= 5) {
    // Znane zrodlo ruchu — nic nie rob. Stempluja sie tu DWA przypadki:
    //  1. klik w widgecie (api/roleta_move|stop) — blokada jest juz ustawiona w API,
    //  2. akcje typu `script`, ktore wysylaja MQTT wprost (roleta_salon_otworz_po_sloncu.php).
    //     Ich matcher `mqtt_set` ponizej NIE lapie, bo w `actions` maja `type: script` — bez tego
    //     stempla kazde otwarcie po sloncu bylo brane za pchniecie scienne i nakladalo blokade,
    //     ktora zjadala okno zamykania o zachodzie (akcja 34).
    exit(0);
}

// System auto-ruch (akcja demona 33/34/35/A wyslala mqtt_set do rolety) — NIE reczne.
// Demon ustawia actions.last_triggered=NOW() TUZ przed publish MQTT (tymos.py _fire_action),
// ~1-3s przed pushem motor_run_status. Jesli jakakolwiek akcja ruszajaca roleta
// (mqtt_set na dev21) odpalila sie w ostatnich 30s — to nasz auto-ruch, nie scienny przycisk.
// Tylko reczne akcje (GUI wyzej / scienny przycisk nizej) nakladaja blokade.
$sys = (int)($db->query(
    "SELECT COUNT(*) FROM actions
     WHERE last_triggered >= NOW() - INTERVAL 30 SECOND
       AND actions LIKE '%\"type\":\"mqtt_set\"%'
       AND actions LIKE '%\"dev_id\":\"21\"%'"
)->fetch_row()[0] ?? 0);
if ($sys > 0) {
    tymos_log('INFO', 'auto-ruch rolety (akcja systemowa) — blokada NIE nakladana');
    exit(0);
}

$until = $now + 10800;
$db->query("INSERT INTO helpers (name, value) VALUES ('roleta_manual_until', '{$until}') ON DUPLICATE KEY UPDATE value='{$until}'");
$db->query("INSERT INTO helpers (name, value) VALUES ('roleta_manual_lock', '1') ON DUPLICATE KEY UPDATE value='1'");
$db->query("UPDATE settings SET v=UNIX_TIMESTAMP() WHERE k='tymos_reload'");
tymos_log('INFO', 'wall switch detected — manual lock set 3h');
