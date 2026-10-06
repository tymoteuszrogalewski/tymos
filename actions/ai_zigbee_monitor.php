#!/usr/bin/env php
<?php
/**
 * ai_zigbee_monitor.php — MONITORING DOSTEPNOSCI URZADZEN: znikniecie, powrot, nowe w sieci.
 * Cron co 5 min (akcja `system_ai_zigbee_monitor`).
 *
 * TELEGRAM TYLKO O NOWYCH URZADZENIACH (decyzja usera 2026-09-13). Znikniecia i powroty ida
 * WYLACZNIE do tabeli `log` (level WARN, source `ai_zigbee_monitor`) — sa materialem do
 * pozniejszej analizy, nie powodem do budzenia telefonu. Powod: SNZB-02D bez heartbeatu
 * (dev684) potrafi milczec 3 h przy zdrowej sieci, wiec alert znaczyl tyle co nic.
 * Analiza: SELECT * FROM log WHERE source='ai_zigbee_monitor' AND message LIKE 'ZNIKLO%'.
 *
 * Po co, skoro sa `available` i `zigbee_watchdog.php`:
 *   - `availability_check.php` daje bateryjnym timeout 48 h — awaria 2026-08-19 (18 czujek lezalo
 *     95 min) nie ruszyla go ani o milimetr.
 *   - `zigbee_watchdog.php` (akcja 93) patrzy na CALA siec i to tylko na urzadzenia sieciowe
 *     (`if (isset($fields['battery'])) continue`) — czesciowa awaria, gdzie routery pchaja dane
 *     a bateryjne leza, jest dla niego niewidzialna. To byla wlasnie martwa strefa z 19.08.
 * Ten skrypt patrzy na KAZDE urzadzenie z osobna, z progiem dobranym do tego, jak czesto ono realnie
 * raportuje.
 *
 * Zrodlo czasu: `last_state.__ts` (znacznik ostatniego pushu KAZDEGO pola, pisany przez tymos.py).
 * Nie `last_seen` — tamto jest faszowane retained-wiadomosciami bridge'a po restarcie Z2M.
 * Nie tabele `device<ID>` — te istnieja tylko dla urzadzen z wlaczonymi statystykami.
 *
 * Progi klas — zmierzone p99/max odstepow raportow (14 dni, bez doby awarii):
 *   net (gniazdka, przekazniki, ESP, BleBox): p99 <= 5 min, max ~50 min  -> prog 60 min
 *   bateryjne czujniki temp/wilg (SNZB-02*): p99 ~60 min, max 113 min    -> prog 180 min
 *   bateryjne zdarzeniowe (kontaktron, ruch, przycisk) i kamery: raportuja TYLKO na zdarzenie,
 *     zmierzony max 36 h                                                 -> prog 48 h
 *
 * Bramki (bez nich alert bylby szumem):
 *   1. uptime Pi < UPTIME_MIN — po reboocie wszystko jest chwilowo offline,
 *   2. cala siec Zigbee milczy (najswiezszy raport urzadzenia sieciowego starszy niz NET_ALIVE_SEC)
 *      = padl koordynator, nie urzadzenia. Tym zajmuje sie zigbee_watchdog + zigbee_recover.
 *
 * Stan miedzy przebiegami: helper `zmon_missing` (JSON {id: epoch ostatniego kontaktu}) oraz
 * `zmon_known` (JSON lista znanych id) — przezywaja restart Pi.
 */

require_once __DIR__ . '/lib/_log.inc.php';

const UPTIME_MIN     = 30;    // min — tyle Pi musi chodzic, zanim zaczniemy alarmowac
const NET_ALIVE_SEC  = 180;   // s  — jak dlugo cala siec moze milczec, zanim uznamy to za awarie koordynatora
const AGE_NET        = 60;    // min
const AGE_BAT_SENSOR = 180;   // min
const AGE_EVENT      = 2880;  // min (48 h)

/**
 * Urzadzenia KRYTYCZNE: id => prog ciszy w minutach. Dla nich znikniecie i powrot ida na Telegram,
 * a nie tylko do tabeli `log` (reszta zostaje cicha — patrz naglowek).
 * dev527 Rekuperator ESP32 — jedyna droga STEROWANIA rekuperatorem (Zehnder po Zigbee daje same
 * odczyty), wiec jego smierc jest niewidoczna w UI: wykresy leca dalej, a `ai_rekuperator` nie moze
 * zmienic biegu. 2026-09-18 lezalo tak 16 h 47 min (min RSSI + lock AP w UniFi wykopywaly ESP).
 */
const ALERT_DEVS = [527 => 60];

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'ai_zigbee_monitor: DB ' . $db->connect_error); exit(1); }
function val($q) { global $db; $r = $db->query($q); return ($r && $row = $r->fetch_row()) ? $row[0] : null; }
function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }
function setJson($name, $label, $v) {
    global $db;
    $e = $db->real_escape_string(json_encode($v));
    $db->query("INSERT INTO helpers (name, label, type, value) VALUES ('{$name}', '{$label}', 'text', '{$e}') "
             . "ON DUPLICATE KEY UPDATE value='{$e}'");
}
function fmtAge($m) {
    if ($m === null) return 'brak danych';
    if ($m >= 1440) return sprintf('%d dni %d h', intdiv($m, 1440), intdiv($m % 1440, 60));
    return ($m >= 60) ? sprintf('%d h %02d min', intdiv($m, 60), $m % 60) : "{$m} min";
}

// --- Bramka 1: swiezy reboot Pi ---
$uptime = (int)(floatval(explode(' ', file_get_contents('/proc/uptime'))[0]) / 60);
if ($uptime < UPTIME_MIN) { logMsg("uptime Pi {$uptime} min < " . UPTIME_MIN . " - jeszcze nie alarmuje"); exit(0); }

$now = time();
$devs = [];
// Bez warunku na last_state — urzadzenia, ktore nic nie publikuja (routery Zigbee), maja go
// pustego, a wlasnie o nich tez chcemy wiedziec, ze doszly do sieci.
$r = $db->query("SELECT id, device, ieee, model, fields, last_state, UNIX_TIMESTAMP(last_seen) AS seen
                 FROM devices WHERE deleted=0");
while ($row = $r->fetch_assoc()) {
    $ls = json_decode($row['last_state'] ?? '{}', true) ?: [];
    $ts = $ls['__ts'] ?? [];
    // Ostatni kontakt = najswiezszy znacznik z dowolnego pola; fallback last_seen (stare wpisy bez __ts).
    $last = is_array($ts) && $ts ? (int)max($ts) : (int)($row['seen'] ?? 0);
    if ($last <= 0) continue;

    $fields  = json_decode($row['fields'] ?? '{}', true) ?: [];
    $battery = isset($fields['battery']);
    $sensor  = isset($ls['temperature']) || isset($ls['humidity']);
    $camera  = (strpos($row['ieee'], 'cam_') === 0);
    // Router Zigbee nie raportuje NICZEGO — zero pol i zero pushow. Cisza jest u niego stanem
    // normalnym, wiec nie dostaje progu (limit null = nigdy nie alarmuje o znikniecu). Liczy sie
    // natomiast do wykrywania NOWYCH urzadzen, bo po to go tu wpuszczamy.
    $silent = (!$fields && !$ts);
    if     ($silent)            { $limit = null;           $klasa = 'cichy (router)'; }
    elseif ($camera)            { $limit = AGE_EVENT;      $klasa = 'kamera'; }
    elseif ($battery && $sensor){ $limit = AGE_BAT_SENSOR; $klasa = 'czujnik bat.'; }
    elseif ($battery)           { $limit = AGE_EVENT;      $klasa = 'bat. zdarzeniowe'; }
    else                        { $limit = AGE_NET;        $klasa = 'sieciowe'; }
    if (array_key_exists((int)$row['id'], ALERT_DEVS)) { $limit = ALERT_DEVS[(int)$row['id']]; $klasa = 'krytyczne'; }

    $devs[(int)$row['id']] = [
        'name'  => $row['device'],
        'ieee'  => $row['ieee'],
        'model' => (string)($row['model'] ?? ''),
        'zig'   => (strpos($row['ieee'], '0x') === 0),
        'net'   => !$battery && !$camera && !$silent,
        'klasa' => $klasa,
        'last'  => $last,
        'age'   => intdiv($now - $last, 60),
        'limit' => $limit,
    ];
}
if (!$devs) exit(0);

// --- Bramka 2: czy siec w ogole zyje (najswiezszy raport urzadzenia sieciowego Zigbee) ---
$netFresh = null;
foreach ($devs as $d) {
    if ($d['zig'] && $d['net'] && ($netFresh === null || $d['last'] > $netFresh)) $netFresh = $d['last'];
}
if ($netFresh !== null && ($now - $netFresh) > NET_ALIVE_SEC) {
    logMsg("cala siec Zigbee milczy od " . ($now - $netFresh) . "s - to awaria koordynatora, nie urzadzen");
    exit(0);
}

$missing = json_decode((string)val("SELECT value FROM helpers WHERE name='zmon_missing' LIMIT 1"), true) ?: [];
$known   = json_decode((string)val("SELECT value FROM helpers WHERE name='zmon_known'   LIMIT 1"), true) ?: [];

/**
 * Producent, model i typ urzadzenia z retained `zigbee2mqtt/bridge/devices`. Samo IEEE nic nie mowi,
 * a nowe urzadzenie zwykle nie ma jeszcze nazwy w Z2M. Model jest tez w `devices.model`, ale vendor
 * i opis sa tylko u Z2M — czytamy je dopiero, gdy faktycznie cos nowego doszlo (rzadka sciezka).
 */
function z2mMeta() {
    $json = shell_exec("timeout 5 mosquitto_sub -h localhost -t zigbee2mqtt/bridge/devices -C 1 2>/dev/null");
    $list = json_decode((string)$json, true);
    if (!is_array($list)) return [];
    $out = [];
    foreach ($list as $d) {
        $ieee = $d['ieee_address'] ?? '';
        if ($ieee === '') continue;
        $def   = is_array($d['definition'] ?? null) ? $d['definition'] : [];
        $vend  = $def['vendor'] ?? $d['manufacturer'] ?? '';
        $model = $def['model']  ?? $d['model_id']     ?? '';
        $desc  = $def['description'] ?? '';
        $out[$ieee] = ['opis' => trim("{$vend} {$model}"), 'typ' => $desc];
    }
    return $out;
}

$gone = $back = $fresh = [];
$goneAlert = $backAlert = [];   // tylko urzadzenia z ALERT_DEVS — te ida na Telegram
// Pierwszy przebieg zaklada baze znanych urzadzen po cichu — inaczej przyszedlby alert o 58 sztukach.
$firstRun = empty($known);

foreach ($devs as $id => $d) {
    $isMissing = ($d['limit'] !== null && $d['age'] > $d['limit']);
    $wasMissing = isset($missing[$id]);

    if ($isMissing && !$wasMissing) {
        $missing[$id] = $d['last'];
        $gone[] = "{$d['name']} — cisza od " . fmtAge($d['age']) . " ({$d['klasa']}, próg " . fmtAge($d['limit']) . ")";
        if (array_key_exists($id, ALERT_DEVS)) $goneAlert[] = "{$d['name']} — cisza od " . fmtAge($d['age']) . " (próg " . fmtAge($d['limit']) . ")";
        tymos_log('WARN', "ZNIKLO dev{$id} {$d['name']} | cisza {$d['age']} min | {$d['klasa']} | prog {$d['limit']} min");
    } elseif (!$isMissing && $wasMissing) {
        $przerwa = intdiv($now - (int)$missing[$id], 60);
        unset($missing[$id]);
        $back[] = "{$d['name']} — wróciło po " . fmtAge($przerwa);
        if (array_key_exists($id, ALERT_DEVS)) $backAlert[] = "{$d['name']} — wróciło po " . fmtAge($przerwa);
        tymos_log('WARN', "POWROT dev{$id} {$d['name']} | przerwa {$przerwa} min | {$d['klasa']}");
    }

    if (!in_array($id, $known, true)) {
        if (!$firstRun) $fresh[] = $id;   // opisy skladamy nizej, dopiero gdy cos nowego jest
        $known[] = $id;
    }
}

// Urzadzenie usuniete z bazy — posprzataj, zeby helper nie puchl.
foreach (array_keys($missing) as $id) { if (!isset($devs[$id])) unset($missing[$id]); }

setJson('zmon_missing', 'Monitor Zigbee - urzadzenia nieobecne (JSON)', $missing);
setJson('zmon_known',   'Monitor Zigbee - znane urzadzenia (JSON)', array_values($known));

if (!$gone && !$back && !$fresh) exit(0);
logMsg("znikly=" . count($gone) . " wrocily=" . count($back) . " nowe=" . count($fresh));

// Znikniecia i powroty zostaja w tabeli `log` (wpisy WARN wyzej) — bez Telegrama.
// WYJATEK: urzadzenia z ALERT_DEVS (patrz gora) — ich cisza jest niewidoczna w UI, wiec musi zapiszczec.
if (!$fresh && !$goneAlert && !$backAlert) exit(0);

// Nowe urzadzenia: producent + model + typ, bo samo IEEE nic nie mowi.
$freshTxt = [];
if ($fresh) {
    $meta = z2mMeta();
    foreach ($fresh as $id) {
        $d    = $devs[$id];
        $m    = $meta[$d['ieee']] ?? [];
        $opis = $m['opis'] ?? trim($d['model']);
        $typ  = $m['typ']  ?? '';
        $nazwa = ($d['name'] === $d['ieee']) ? 'BEZ NAZWY w Z2M' : $d['name'];
        $freshTxt[] = trim($nazwa . ' | ' . ($opis ?: 'model nieznany') . ($typ ? " — {$typ}" : '') . ' | ' . $d['ieee']);
    }
}

$msg = '';
if ($goneAlert) $msg .= "⚠️ Brak łączności:\n· " . implode("\n· ", $goneAlert);
if ($backAlert) $msg .= ($msg ? "\n\n" : '') . "✅ Łączność wróciła:\n· " . implode("\n· ", $backAlert);
if ($freshTxt)  $msg .= ($msg ? "\n\n" : '') . "🆕 Nowe urządzenia w sieci:\n· " . implode("\n· ", $freshTxt);
$proc = popen('php /opt/tymos/actions/telegram_send.php > /dev/null 2>&1', 'w');
if ($proc) { fwrite($proc, json_encode(['type' => 'text', 'msg' => $msg, 'chat' => 'alert'])); pclose($proc); }
