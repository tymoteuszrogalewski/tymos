#!/usr/bin/env php
<?php
/**
 * ai_podlogowka.php — dociaga stan gniazdka podlogowki lazienki (dev551) do FLAGI helpera
 * `podlogowka_wlaczona` (0/1). Widget panelu = flaga docelowa; ta akcja czyni rzeczywistosc zgodna.
 * Cron co minute. Odporne na power-back (gniazdko ma power_on_behavior=off -> cron je dociagnie).
 * Publikuje tylko przy NIEZGODNOSCI. MQTT anonimowo na localhost:1883 (zigbee2mqtt/<name>/set).
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'ai_podlogowka: DB ' . $db->connect_error); exit(1); }
function val($q) { global $db; $r = $db->query($q); return ($r && $row = $r->fetch_row()) ? $row[0] : null; }

$want = val("SELECT value FROM helpers WHERE name='podlogowka_wlaczona'");
if ($want === null) exit(0);              // brak flagi -> nic nie robimy
$wantOn = ((string)$want === '1');

$row = $db->query("SELECT device, last_state, available FROM devices WHERE id=551")->fetch_assoc();
if (!$row) exit(0);
if ((string)($row['available'] ?? '1') === '0') exit(0);   // offline -> nie spamuj MQTT (Z2M i tak nie dostarczy)
$name  = $row['device'] ?? '';
$ls    = json_decode($row['last_state'] ?? '{}', true) ?: [];
$curOn = (strtoupper((string)($ls['state'] ?? 'OFF')) === 'ON');

if ($wantOn === $curOn) exit(0);          // zgodne -> nic

$target = $wantOn ? 'ON' : 'OFF';
$topic  = "zigbee2mqtt/{$name}/set";
exec('mosquitto_pub -h localhost -t ' . escapeshellarg($topic) . ' -m ' . escapeshellarg('{"state":"' . $target . '"}') . ' > /dev/null 2>&1');
tymos_log('INFO', "ai_podlogowka: dociagam {$target} (flaga={$want}, bylo=" . ($curOn ? 'ON' : 'OFF') . ")");
