#!/usr/bin/env php
<?php
/**
 * ai_pralka_zawor.php — zawor wody pralki (dev1185 „Pralka zawór”, MINI-ZBRBS):
 * OPEN = ciepla woda z bufora, CLOSE = zimna.
 * Pierwsze 15 min prania ciepla (pranie glowne nalewa wode do ~6 min), potem zimna do konca (plukania).
 * Po koncu prania z powrotem ciepla — stan spoczynkowy, gotowy na nastepne pranie.
 * Harmonogram: akcja GUI „Pralka Zawór — ciepła/zimna”, cron co 1 minute.
 *
 * START = 3 kolejne minuty z moca > 30 W (gniazdko Pralka dev19). Pojedyncze piki przy
 * programowaniu (np. start opozniony: 06:44 blip 39 W, potem 6 W plasko, prawdziwy start 08:22)
 * NIE startuja odliczania. Pralka czekajaca na start opozniony stoi na ~6 W.
 * KONIEC = moc <= 3 W przez 5 min (jak ai_pralka_done.php) albo 60 min bez > 30 W
 * (pralka zostala na 6 W, np. znow zaprogramowana na pozniej).
 * Brak/stary odczyt mocy (> 120 s) = nie ruszac zaworu.
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

function val($q) { global $db; $r = $db->query($q); return ($r && $x = $r->fetch_row()) ? $x[0] : null; }

$DEV       = 19;    // gniazdko pralki
$ZAWOR     = 1185;  // Pralka zawór
$WORK      = 30;    // W — powyzej = pralka pracuje
$OFF       = 3;     // W — ponizej = pralka zgaszona
$CIEPLA_MIN = 15;   // min cieplej wody od startu
$IDLE_MIN  = 60;    // min bez > WORK = koniec (gdy pralka nie zgasla)

// Swiezosc odczytu mocy
$r = $db->query("SELECT last_state FROM devices WHERE id={$DEV}");
$ls = ($r && $row = $r->fetch_assoc()) ? (json_decode($row['last_state'] ?? '{}', true) ?: []) : [];
if (!isset($ls['power']) || time() - (float)($ls['__ts']['power'] ?? 0) > 120) exit(0);

$start = (int) val("SELECT value FROM helpers WHERE name='pralka_zawor_start' LIMIT 1");
function setStart($v) { global $db; $db->query("INSERT INTO helpers (name, value) VALUES ('pralka_zawor_start', '{$v}') ON DUPLICATE KEY UPDATE value='{$v}'"); }

if ($start === 0) {
    // 3 ostatnie minuty — w kazdej co najmniej jedna probka > WORK
    $min = (int) val("SELECT COUNT(DISTINCT FLOOR(TIMESTAMPDIFF(SECOND, ts, NOW()) / 60)) FROM device{$DEV}
                      WHERE ts > NOW() - INTERVAL 180 SECOND AND power > {$WORK}");
    if ($min >= 3) {
        $start = (int) val("SELECT UNIX_TIMESTAMP(MIN(ts)) FROM device{$DEV} WHERE ts > NOW() - INTERVAL 180 SECOND AND power > {$WORK}");
        setStart($start);
        tymos_log('INFO', 'pralka START — ciepla woda do ' . date('H:i', $start + $CIEPLA_MIN * 60));
    }
} else {
    $maxOff = val("SELECT MAX(power) FROM device{$DEV} WHERE ts > NOW() - INTERVAL 5 MINUTE");
    $cntOff = (int) val("SELECT COUNT(*) FROM device{$DEV} WHERE ts > NOW() - INTERVAL 5 MINUTE");
    $praca  = (int) val("SELECT COUNT(*) FROM device{$DEV} WHERE ts > NOW() - INTERVAL {$IDLE_MIN} MINUTE AND power > {$WORK}");
    $koniec = ($cntOff >= 8 && $maxOff !== null && (float)$maxOff <= $OFF) || $praca === 0;
    if ($koniec && time() - $start > 600) {
        $start = 0;
        setStart(0);
        tymos_log('INFO', 'pralka KONIEC — zawor na ciepla');
    }
}

$pozadany = ($start > 0 && time() - $start >= $CIEPLA_MIN * 60) ? 'CLOSE' : 'OPEN';

$r = $db->query("SELECT device, last_state FROM devices WHERE id={$ZAWOR}");
if (!$r || !($row = $r->fetch_assoc())) exit(1);
$lz = json_decode($row['last_state'] ?? '{}', true) ?: [];
if (($lz['state'] ?? null) === $pozadany) exit(0);

$topic = "zigbee2mqtt/{$row['device']}/set";
exec(sprintf("mosquitto_pub -h localhost -t %s -m %s", escapeshellarg($topic), escapeshellarg(json_encode(['state' => $pozadany]))));
tymos_log('INFO', "pralka zawor -> {$pozadany}");
