#!/usr/bin/env php
<?php
/**
 * ai_gofrownica_watchdog.php — auto-OFF gniazdka "Gofrownica" po LIMIT_MIN minutach od wlaczenia.
 * Cron: co 1 minute (akcja TymOS typu script).
 *
 * Po co: gofrownica jest podlaczona na stale, domownicy wlaczaja i wylaczaja PRZYCISKIEM na gniazdku
 * (wczesniej wtykaly/wyjmowaly wtyczke). Czasem zapomna wylaczyc -> gofrownica grzeje bez nadzoru.
 * Po auto-wylaczeniu leci lektor, zeby ktos kto chce gofrowac dalej wiedzial, ze ma wlaczyc ponownie.
 *
 * Logika (helper `gofrownica_on_ts`: '0' = nie liczymy, epoch = moment wykrycia wlaczenia):
 *   state != ON              -> helper '0' (licznik od zera przy kazdym wylaczeniu)
 *   state == ON, helper '0'  -> helper = teraz (start liczenia)
 *   state == ON, minelo >= LIMIT_MIN -> MQTT OFF + lektor + helper '0'
 *
 * Swiadomie NIE robimy tego akcja natywna z warunkiem `last_activity`: S60 pushuje `state` przy
 * KAZDYM raporcie okresowym (nie tylko przy zmianie), wiec __val_ts[state][ON] odswiezalby sie
 * co ~30 s i prog 600 s nigdy by nie zadzialal. Warunek `last_change` czyta device<ID>, gdzie
 * `state` nie musi byc logowany (enabled_fields = tylko statystyki) — tez zawodny.
 *
 * Rozdzielczosc crona = 1 min, wiec realny czas pracy to LIMIT_MIN..LIMIT_MIN+1 min. Wystarcza.
 * NIE ruszamy power_on_behavior gniazdka (patrz feedback: mechanizm odrzucony przez usera).
 */

require_once __DIR__ . '/lib/_log.inc.php';

$LIMIT_MIN = 10;                  // minut od wlaczenia -> auto-OFF
$DEV_NAME  = 'Gofrownica';        // nazwa w Z2M (id ustalone przy pierwszym uruchomieniu w logu)
$SOUND     = 'gofrownica_off';    // = plik snd/gofrownica_off.mp3 (nazwa dzwieku 1:1 z nazwa pliku)
$HELPER    = 'gofrownica_on_ts';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'ai_gofrownica: DB ' . $db->connect_error); exit(1); }
function val($q) { global $db; $r = $db->query($q); return ($r && $row = $r->fetch_row()) ? $row[0] : null; }

$q = "SELECT id, device, last_state, available FROM devices
      WHERE device='" . $db->real_escape_string($DEV_NAME) . "' AND deleted=0 LIMIT 1";
$r = $db->query($q);
if (!$r || !($dev = $r->fetch_assoc())) {
    tymos_log('ERROR', "ai_gofrownica: brak urzadzenia '{$DEV_NAME}' w devices");
    exit(1);
}

$ls   = json_decode($dev['last_state'] ?? '{}', true) ?: [];
$isOn = (strtoupper((string)($ls['state'] ?? 'OFF')) === 'ON');

$ts = val("SELECT value FROM helpers WHERE name='{$HELPER}' LIMIT 1");
if ($ts === null) { $db->query("INSERT INTO helpers (name, value) VALUES ('{$HELPER}', '0')"); $ts = '0'; }
$ts = (int)$ts;

// --- gniazdko wylaczone: licznik od zera ---
if (!$isOn) {
    if ($ts !== 0) $db->query("UPDATE helpers SET value='0' WHERE name='{$HELPER}'");
    exit(0);
}

// --- wlaczone, pierwsze wykrycie: zapamietaj moment ---
if ($ts === 0) {
    $db->query("UPDATE helpers SET value='" . time() . "' WHERE name='{$HELPER}'");
    exit(0);
}

// --- wlaczone: czy minal limit? ---
$mins = (time() - $ts) / 60;
if ($mins < $LIMIT_MIN) exit(0);

// offline -> nie spamuj MQTT (Z2M i tak nie dostarczy). Licznik zostaje, ponowimy za minute.
if ((string)($dev['available'] ?? '1') === '0') {
    tymos_log('WARN', 'ai_gofrownica: limit minal, ale gniazdko offline — ponowie');
    exit(0);
}

$topic = "zigbee2mqtt/{$dev['device']}/set";
exec('mosquitto_pub -h localhost -t ' . escapeshellarg($topic)
     . ' -m ' . escapeshellarg('{"state":"OFF"}') . ' > /dev/null 2>&1');

$db->query("INSERT INTO tymos_sounds (sound) VALUES ('{$SOUND}')");
$db->query("UPDATE helpers SET value='0' WHERE name='{$HELPER}'");
tymos_log('INFO', "ai_gofrownica: auto-OFF po " . round($mins, 1) . " min (dev id={$dev['id']})");
exit(0);
