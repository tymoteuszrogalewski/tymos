#!/usr/bin/env php
<?php
/**
 * multiclick.php — sypialnia: klik / dwuklik z przyciskow ZBMINIR2 (Tymek, Nika) i podwojne
 * pstrykniecie wlacznikiem glownego swiatla.
 * Uzycie: multiclick.php <dev_id>   (akcja GUI, min_interval 0)
 *
 * Kazdy klik = osobny proces. Klik zapisuje swoj czas do pliku stanu (flock), czeka okno.
 * Jesli w tym czasie przyszedl nowszy klik — konczy bez akcji. Jesli nie — to byl ostatni klik
 * sekwencji: wykonuje akcje dla liczby klikow.
 *
 * Tymek / Nika — detach_relay_mode (przycisk NIE steruje przekaznikiem, wysyla action: toggle):
 *   1 klik = toggle tego swiatla
 *   2 kliki = klikniete bylo ON  -> OFF Tymek + Nika + glowne
 *             klikniete bylo OFF -> ON Tymek + Nika (glownego nie ruszac)
 * Glowne — ZOSTAJE w trybie fizycznym (wlacznik przelacza przekaznik sam, dziala bez Pi i sieci).
 *   Klik = zmiana stanu przekaznika (akcja: dwa triggery threshold state = ON / = OFF; trigger
 *   na polu action odpada, bo ZBMINIR2 bez detach dokleja action do kazdego raportu co 5 min).
 *   1 pstrykniecie = nic (przekaznik juz przelaczyl)
 *   2 pstrykniecia (swiatlo mrugnie) = ktorekolwiek z Tymek / Nika ON -> oba OFF, oba OFF -> oba ON
 */

require_once __DIR__ . '/lib/_log.inc.php';

$TYMEK = 1183;
$NIKA  = 1191;
$MAIN  = 1184;

// Okno miedzy klikami. Glowne dluzej: pstrykniecie wlacznikiem tam i z powrotem trwa dluzej niz klik przycisku.
$WINDOWS = [$TYMEK => 0.5, $NIKA => 0.5, $MAIN => 1.0];

$dev_id = (int)($argv[1] ?? 0);
if (!isset($WINDOWS[$dev_id])) { tymos_log('ERROR', "multiclick: nieznany dev_id={$dev_id}"); exit(1); }
$WINDOW = $WINDOWS[$dev_id];

$file = "/tmp/tymos_multiclick_{$dev_id}.json";
$fp = fopen($file, 'c+');
if (!$fp) { tymos_log('ERROR', "multiclick: nie moge otworzyc {$file}"); exit(1); }

// 1. zapisz klik
$now = microtime(true);
flock($fp, LOCK_EX);
$st = json_decode(stream_get_contents($fp), true) ?: ['last' => '0', 'count' => 0];
if ($now - (float)$st['last'] < $WINDOW) {
    $st['count']++;
} else {
    $st['count'] = 1;
}
$my = sprintf('%.6f', $now);
$st['last'] = $my;
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($st));
fflush($fp);
flock($fp, LOCK_UN);

// 2. poczekaj, czy przyjdzie kolejny klik
usleep((int)($WINDOW * 1000000));

flock($fp, LOCK_EX);
rewind($fp);
$st = json_decode(stream_get_contents($fp), true) ?: [];
flock($fp, LOCK_UN);
fclose($fp);

if (($st['last'] ?? '') !== $my) exit(0);  // nowszy klik przejmuje sekwencje

// 3. ostatni klik sekwencji — wykonaj
$count = (int)$st['count'];

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'multiclick: DB connect: ' . $db->connect_error); exit(1); }

// Nazwa Z2M i stan z devices — nazwa nie na sztywno, zeby rename w Z2M nie psul skryptu.
// Stan ON liczy sie tylko, gdy modul odzywal sie w ostatniej godzinie (raportuje co ~5 min) —
// odlaczony modul trzyma w last_state stary stan i falszowalby decyzje "ktorekolwiek wlaczone".
function dev($id) {
    global $db;
    $r = $db->query("SELECT device, last_state FROM devices WHERE id=" . (int)$id);
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row) return null;
    $ls = json_decode($row['last_state'] ?? '{}', true) ?: [];
    $fresh = time() - (float)($ls['__ts']['linkquality'] ?? 0) < 3600;
    return ['name' => $row['device'], 'on' => $fresh && ($ls['state'] ?? '') === 'ON'];
}
function set($id, $state) {
    $d = dev($id);
    if (!$d) { tymos_log('ERROR', "multiclick: brak urzadzenia dev_id={$id}"); return; }
    exec('mosquitto_pub -h localhost -t ' . escapeshellarg("zigbee2mqtt/{$d['name']}/set")
        . ' -m ' . escapeshellarg(json_encode(['state' => $state])) . ' > /dev/null 2>&1');
}

if ($dev_id === $MAIN) {
    if ($count !== 2) exit(0);
    $anyOn = dev($TYMEK)['on'] || dev($NIKA)['on'];
    set($TYMEK, $anyOn ? 'OFF' : 'ON');
    set($NIKA,  $anyOn ? 'OFF' : 'ON');
    exit(0);
}

// Tymek / Nika
if ($count === 1) {
    set($dev_id, 'TOGGLE');
} elseif ($count === 2) {
    // detach_relay_mode: kliki nie ruszaja przekaznika, wiec stan teraz = stan sprzed klikania
    if (dev($dev_id)['on']) {
        set($TYMEK, 'OFF');
        set($NIKA,  'OFF');
        set($MAIN,  'OFF');
    } else {
        set($TYMEK, 'ON');
        set($NIKA,  'ON');
    }
}
