#!/usr/bin/env php
<?php
/**
 * multiclick.php — 1/2/3-klik z przycisku ZBMINIR2 w trybie detach_relay_mode.
 * Uzycie: multiclick.php <dev_id>   (akcja GUI: trigger state, pole action = toggle, min_interval 0)
 *
 * Kazdy klik = osobny proces. Klik zapisuje swoj czas do pliku stanu (flock), czeka WINDOW s.
 * Jesli w tym czasie przyszedl nowszy klik — konczy bez akcji. Jesli nie — to byl ostatni klik
 * sekwencji: wykonuje akcje dla liczby klikow.
 * Klik, ktory przyszedl po wiecej niz WINDOW od poprzedniego, zaczyna nowa sekwencje.
 */

require_once __DIR__ . '/lib/_log.inc.php';

$WINDOW = 0.5;

// dev_id przycisku => [liczba klikow => [topic z2m, payload]]
$MAP = [
    1183 => [
        1 => ['SypialniaTymek', '{"state":"TOGGLE"}'],
        2 => ['SypialniaTymek', '{"state":"OFF"}'],
        3 => ['Chłodnica Salon Wentylatory', '{"state":"OFF"}'],
    ],
];

$dev_id = (int)($argv[1] ?? 0);
if (!isset($MAP[$dev_id])) { tymos_log('ERROR', "multiclick: brak mapy dla dev_id={$dev_id}"); exit(1); }

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
if (!isset($MAP[$dev_id][$count])) exit(0);  // np. 4 kliki — brak akcji
[$name, $payload] = $MAP[$dev_id][$count];
exec('mosquitto_pub -h localhost -t ' . escapeshellarg("zigbee2mqtt/{$name}/set") . ' -m ' . escapeshellarg($payload) . ' > /dev/null 2>&1');
