#!/usr/bin/env php
<?php
/**
 * ai_mroz.php — BLOKADA MROZOWA. Jedno miejsce, ktore decyduje „jest mroz / nie ma mrozu",
 * z SZEROKA HISTEREZA, zeby przy oscylacji wokol zera nic nie mrugalo co minute.
 * Cron co minute.
 *
 * Stan trzyma helper `mroz_blokada` (1/0) — korzystaja z niego wszystkie skrypty, ktore nie moga
 * pracowac przy przymrozku (pompa ogrodowa, podlewanie, basen). Dzieki temu prog jest JEDEN,
 * a nie rozsypany po skryptach.
 *
 * Histereza: wlacza sie ponizej FROST_ON, gasnie dopiero powyzej FROST_OFF. Miedzy progami stan
 * sie NIE ZMIENIA. Jesienny poranek potrafi przeskakiwac wokol 1-2 stopni, a pompa nie ma wracac
 * do pracy na kwadrans, zeby zaraz znowu stanac.
 *
 * Zrodlo temperatury: CIEN (device10) — to samo, czym mierzy sie dwor w reszcie systemu.
 * Fallback: outdoor z reku (device527), gdy cien milczy (bateryjny, na slabej galezi mesha).
 *
 * BRAK ODCZYTU = BLOKADA WLACZONA. Swiadoma decyzja: nie wiemy, czy jest mroz, wiec zakladamy
 * najgorsze — kosztuje to nieprzelana grzadke, a alternatywa to rozsadzona pompa.
 *
 * POMPA OGRODOWA jest twardo gaszona (gniazdko dev906 OFF), bo abisynka, hydrofor i weze stoja
 * na dworze. Publikujemy tylko przy zmianie stanu — zero spamu MQTT.
 */

require_once __DIR__ . '/lib/_log.inc.php';

const FROST_ON   = 2.0;    // °C — ponizej tego blokujemy
const FROST_OFF  = 7.0;    // °C — dopiero powyzej tego odblokowujemy
const CIEN_MAX   = 240;    // min — dopuszczalny wiek odczytu z cienia (dev10 bywa rzadki)
const REKU_MAX   = 60;     // min — fallback z reku raportuje czesto
const PUMP_ID    = 906;    // "Ogród Pompa" (S60ZBTPF)

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'ai_mroz: DB ' . $db->connect_error); exit(1); }
function val($q) { global $db; $r = $db->query($q); return ($r && $row = $r->fetch_row()) ? $row[0] : null; }
function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }
function dev_state($id) { return strtoupper((string)val("SELECT JSON_UNQUOTE(JSON_EXTRACT(last_state,'$.state')) FROM devices WHERE id={$id}")); }
function dev_set($id, $payload) {
    $name = val("SELECT device FROM devices WHERE id={$id}");
    if (!$name) return;
    exec('mosquitto_pub -h localhost -t ' . escapeshellarg("zigbee2mqtt/{$name}/set") . ' -m ' . escapeshellarg($payload) . ' > /dev/null 2>&1');
}
function setHelper($name, $v) {
    global $db;
    $e = $db->real_escape_string((string)$v);
    $db->query("INSERT INTO helpers (name, label, type, value) VALUES ('{$name}', 'Blokada mrozowa (1/0)', 'text', '{$e}') "
             . "ON DUPLICATE KEY UPDATE value='{$e}'");
}
function telegram($msg) {
    $p = popen('php /opt/tymos/actions/telegram_send.php > /dev/null 2>&1', 'w');
    if ($p) { fwrite($p, json_encode(['type' => 'text', 'msg' => $msg, 'chat' => 'alert'])); pclose($p); }
}

// --- temperatura dworu: cien, potem reku ---
$T = null; $src = '';
$r = $db->query("SELECT temperature, TIMESTAMPDIFF(MINUTE, ts, NOW()) AS age FROM device10
                 WHERE temperature IS NOT NULL ORDER BY ts DESC LIMIT 1");
if ($r && $row = $r->fetch_assoc()) {
    if ((int)$row['age'] <= CIEN_MAX) { $T = (float)$row['temperature']; $src = "cien ({$row['age']} min)"; }
}
if ($T === null) {
    $r = $db->query("SELECT outdoor_air_temperature AS t, TIMESTAMPDIFF(MINUTE, ts, NOW()) AS age FROM device527
                     WHERE outdoor_air_temperature IS NOT NULL ORDER BY ts DESC LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) {
        if ((int)$row['age'] <= REKU_MAX) { $T = (float)$row['t']; $src = "reku ({$row['age']} min)"; }
    }
}

$prev = (string)val("SELECT value FROM helpers WHERE name='mroz_blokada' LIMIT 1");
$was  = ($prev === '1');

if ($T === null) {
    $now = true;                                  // brak danych = blokada (patrz naglowek)
    $src = 'BRAK ODCZYTU';
} elseif ($T < FROST_ON) {
    $now = true;
} elseif ($T > FROST_OFF) {
    $now = false;
} else {
    $now = $was;                                  // strefa histerezy — trzymaj stan
}

if ($now !== $was) {
    setHelper('mroz_blokada', $now ? '1' : '0');
    $tTxt = $T === null ? '—' : number_format($T, 1, ',', '') . '°C';
    if ($now) {
        tymos_log('WARN', "ai_mroz: BLOKADA ON ({$tTxt}, {$src})");
        telegram("❄️ Blokada mrozowa WŁĄCZONA ({$tTxt})\nPompa ogrodowa wyłączona, podlewanie i basen wstrzymane.\nOdblokowanie dopiero powyżej " . FROST_OFF . "°C.");
    } else {
        tymos_log('WARN', "ai_mroz: BLOKADA OFF ({$tTxt}, {$src})");
        telegram("✅ Blokada mrozowa zdjęta ({$tTxt}). Pompa i podlewanie znów dostępne.");
    }
}

// --- pompa ogrodowa: przy blokadzie gniazdko ma byc OFF, zawsze ---
if ($now) {
    $avail = (int)val("SELECT available FROM devices WHERE id=" . PUMP_ID);
    if ($avail === 1 && dev_state(PUMP_ID) === 'ON') {
        logMsg('MROZ: gaszę pompę ogrodową (dev' . PUMP_ID . ')');
        tymos_log('WARN', 'ai_mroz: pompa ogrodowa wylaczona (mroz)');
        dev_set(PUMP_ID, '{"state":"OFF"}');
    }
}

logMsg(sprintf('T=%s [%s] blokada=%s%s', $T === null ? '—' : $T, $src, $now ? 'ON' : 'OFF', $now !== $was ? ' (ZMIANA)' : ''));
