#!/usr/bin/env php
<?php
/**
 * ai_zigbee_rejoin.php — OKNO REJOINU po restarcie Z2M / reboocie Pi. Cron co minute.
 *
 * Po co: awaria 2026-08-19 pokazala, ze po dluzszej niedostepnosci koordynatora czesc urzadzen
 * bateryjnych gubi rodzica i probuje wrocic przez UNSECURED rejoin — a ten wymaga otwartej sieci.
 * Przy zamknietej trzeba bylo rozkrecac czujki i wciskac w nich reset (najgorsze: RekuPre/RekuPost
 * schowane w rurach rekuperatora). Dlatego po kazdym starcie Z2M otwieramy siec na WINDOW_MIN minut,
 * zeby zgubki wrocily same.
 *
 * Wykrycie startu: `systemctl show zigbee2mqtt -p ExecMainStartTimestamp`. Znacznik zapamietany
 * w helperze `zrejoin_last_start` — jeden start = jedno okno. Obejmuje tez reboot Pi, bo wtedy
 * Z2M startuje na nowo.
 *
 * Odnawianie: `permit_join` idzie co przebieg z czasem 240 s zamiast raz z 7200 s. Pole
 * PermitDuration w Zigbee ma JEDEN bajt i Z2M pilnuje tego twardo („Cannot permit join for more
 * than 254 seconds."), wiec dlugie okno trzeba podtrzymywac — a przy okazji przezywa to wewnetrzny
 * restart Z2M z jego watchdoga.
 *
 * Recznie: ikona anteny na kamerze (panel home, prawa strona drugiej linii) — szara gdy zamkniete,
 * pulsujaca zielona gdy otwarte (obojetnie czy przez ten skrypt, czy recznie). Klik przy zielonej
 * zamyka siec od razu, klik przy szarej otwiera BEZ LIMITU CZASU (helper `zrejoin_force`).
 *
 * Bezpieczenstwo: otwarta siec = mozliwosc dolaczenia obcego urzadzenia. Dlatego okno jest krotkie,
 * kazde otwarcie i zamkniecie leci na Telegram WAZNE, a `ai_zigbee_monitor.php` osobno powiadamia
 * o KAZDYM nowym urzadzeniu w bazie.
 */

require_once __DIR__ . '/lib/_log.inc.php';

const WINDOW_MIN  = 120;   // min — jak dlugo trzymamy siec otwarta po starcie Z2M
const RENEW_SEC   = 240;   // s   — czas podawany w kazdym permit_join (odnawiany co przebieg crona).
                           // Twardy limit protokolu to 254 s — Z2M odrzuca wiecej wprost:
                           // „Cannot permit join for more than 254 seconds." (sprawdzone 2026-08-21).

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'ai_zigbee_rejoin: DB ' . $db->connect_error); exit(1); }
function val($q) { global $db; $r = $db->query($q); return ($r && $row = $r->fetch_row()) ? $row[0] : null; }
function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }
function hSet($name, $label, $v) {
    global $db;
    $e = $GLOBALS['db']->real_escape_string((string)$v);
    $db->query("INSERT INTO helpers (name, label, type, value) VALUES ('{$name}', '{$label}', 'text', '{$e}') "
             . "ON DUPLICATE KEY UPDATE value='{$e}'");
}
function permitJoin($sec) {
    exec('mosquitto_pub -h localhost -t ' . escapeshellarg('zigbee2mqtt/bridge/request/permit_join')
       . ' -m ' . escapeshellarg('{"time":' . (int)$sec . '}') . ' > /dev/null 2>&1');
}
function telegram($msg) {
    $proc = popen('php /opt/tymos/actions/telegram_send.php > /dev/null 2>&1', 'w');
    if ($proc) { fwrite($proc, json_encode(['type' => 'text', 'msg' => $msg, 'chat' => 'alert'])); pclose($proc); }
}

$raw   = shell_exec('timeout 3 mosquitto_sub -h localhost -t zigbee2mqtt/bridge/state -C 1 2>/dev/null');
$state = json_decode((string)$raw, true)['state'] ?? '';
if ($state === '') { logMsg('brak stanu bridge/state — pomijam przebieg'); exit(0); }

$lastState = (string)val("SELECT value FROM helpers WHERE name='zrejoin_last_state' LIMIT 1");
$until     = (int)(val("SELECT value FROM helpers WHERE name='zrejoin_until' LIMIT 1") ?? 0);
$now       = time();
hSet('zrejoin_last_state', 'Rejoin - ostatni stan bridge Z2M', $state);

// Pierwsze uruchomienie po wdrozeniu: tylko zapamietaj stan, bez otwierania sieci.
if ($lastState === '') {
    logMsg("pierwszy przebieg — zapamietuje stan bridge ({$state}), okna nie otwieram");
    exit(0);
}

if ($state === 'online' && $lastState !== 'online') {
    $until = $now + WINDOW_MIN * 60;
    hSet('zrejoin_until', 'Rejoin - okno otwarte do (epoch)', $until);
    permitJoin(RENEW_SEC);
    tymos_log('WARN', 'ai_zigbee_rejoin: koordynator wrocil - siec OTWARTA na ' . WINDOW_MIN . ' min');
    logMsg('bridge online (bylo: ' . $lastState . ') — okno rejoinu otwarte do ' . date('H:i', $until));
    // Krotko (user 2026-09-14): jedna linia. Kontekst (kto otworzyl, po co) jest w `log`.
    telegram("🔓 Zigbee rejoin otwarty do " . date('H:i', $until) . ".");
    exit(0);
}

// Z2M nie zyje — nie ma komu wyslac zadania, czekamy na powrot.
if ($state !== 'online') { logMsg("bridge {$state} — nic nie robie"); exit(0); }

// RECZNE „na stale" z ikony na kamerze (helper zrejoin_force=1, api/zigbee_join_set) — trzymamy
// otwarte bez konca, az user kliknie ponownie. Tez przez odnawianie, bo limit 254 s obowiazuje zawsze.
if ((int)(val("SELECT value FROM helpers WHERE name='zrejoin_force' LIMIT 1") ?? 0) === 1) {
    permitJoin(RENEW_SEC);
    logMsg('rejoin WYMUSZONY recznie — odnawiam permit_join');
    exit(0);
}

if ($until > $now) {
    permitJoin(RENEW_SEC);   // podtrzymanie — jeden bajt PermitDuration nie uniesie calego okna
    logMsg('okno rejoinu trwa do ' . date('H:i', $until) . ' — odnawiam permit_join');
    exit(0);
}

// Okno wlasnie minelo — zamknij siec raz i wyczysc znacznik.
if ($until > 0) {
    permitJoin(0);
    $db->query("DELETE FROM helpers WHERE name='zrejoin_until'");
    tymos_log('INFO', 'ai_zigbee_rejoin: okno rejoinu zamkniete');
    logMsg('okno rejoinu zamkniete');
    telegram("🔒 Zigbee rejoin zamknięte.");
}
