#!/usr/bin/env php
<?php
/**
 * zmywarka_ai.php — sterowanie elektrozaworem zmywarki na podstawie cen dynamicznych (Pstryk)
 * Zamknij gdy prad tanszy od peletu, otworz gdy drozszy
 * Przestawia zawor TYLKO gdy zmywarka pobiera prad (> 8 W) — w praktyce raz na cykl zmywarki,
 * a nie przy kazdym przejsciu ceny przez prog.
 * Sterowanie: Z2M MQTT (Zigbee MINI-ZBRBS)
 * Harmonogram: akcja GUI 41 „Zmywarka AI Zawór”, cron co 1 minute
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

// Zmywarka pracuje? Gniazdko dev_id=8. Spoczynek 0-4 W, start programu od ~10 W.
// Brak/przestarzaly odczyt mocy (>120 s) = traktuj jak "nie pracuje" = nie ruszaj zaworu
// (zostaje w stanie z poprzedniego cyklu).
$prog_w = 8;
$r = $db->query("SELECT last_state FROM devices WHERE id=8");
$ls8 = ($r && $row = $r->fetch_assoc()) ? (json_decode($row['last_state'] ?? '{}', true) ?: []) : [];
$moc = $ls8['power'] ?? null;
$moc_ts = (float)($ls8['__ts']['power'] ?? 0);
if ($moc === null || time() - $moc_ts > 120 || (float)$moc <= $prog_w) exit(0);

// Prog oplacalnosci — ponizej: zmywarka grzeje sama (prad tani), powyzej: goraca woda z bufora
$prog = 0.40;

// Aktualna cena Pstryk. Brak ceny (pad API Pstryka albo netu) = zachowaj sie jak przy drogim pradzie:
// zawor OPEN, czyli zmywarka bierze goraca wode z bufora zamiast grzac sie sama. Bufor i tak jest
// ciepły, a brak decyzji zostawialby zawor w przypadkowym stanie sprzed awarii.
$r = $db->query("SELECT full_price_pstryk FROM energa WHERE ts = DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') LIMIT 1");
$cena = ($r && ($row = $r->fetch_row()) && $row[0] !== null) ? (float)$row[0] : null;

// Aktualny stan zaworu (dev_id=15) — z devices.last_state (tabela device15 nie trzyma kolumny state)
$dev_id = 15;
$r = $db->query("SELECT last_state FROM devices WHERE id={$dev_id}");
$ls = ($r && $row = $r->fetch_assoc()) ? (json_decode($row['last_state'] ?? '{}', true) ?: []) : [];
$stan = $ls['state'] ?? null;

$pozadany = ($cena !== null && $cena < $prog) ? 'CLOSE' : 'OPEN';

if ($stan === $pozadany) exit(0);

if ($cena === null) tymos_log('WARN', 'ai_zmywarka: brak ceny na biezaca godzine - domyslnie woda z bufora (OPEN)');

// Nazwa urzadzenia z bazy (odporna na rename w Z2M)
$r = $db->query("SELECT device FROM devices WHERE id={$dev_id}");
if (!$r || !($row = $r->fetch_row())) exit(1);
$device_name = $row[0];

// MQTT publish
$topic = "zigbee2mqtt/{$device_name}/set";
$payload = json_encode(['state' => $pozadany]);
$cmd = sprintf("mosquitto_pub -h localhost -t %s -m %s", escapeshellarg($topic), escapeshellarg($payload));
exec($cmd);
