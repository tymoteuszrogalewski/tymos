#!/usr/bin/env php
<?php
/**
 * ai_failsafe.php — STAN BEZPIECZNY URZADZEN, GDY ZNIKNIE ODCZYT WARUNKU. Cron co minute.
 *
 * Po co: czujka Zigbee moze wypasc z sieci, a jej ostatnia wartosc zostaje w bazie i wyglada jak
 * zywa. Automatyka steruje wtedy na oslep — awaria 2026-08-19: grzalki bufora grzaly 95 min na
 * zamrozonym odczycie temperatury. `ai_bufor.php` dostal wlasny bezpiecznik; ten skrypt robi to
 * samo dla POZOSTALYCH urzadzen sterowanych warunkami srodowiskowymi.
 *
 * Podzial pracy (dwie warstwy, kazda robi co innego):
 *   1. daemon `tymos.py` (STALE_FIELDS) — warunek akcji na przestarzalym odczycie = FALSE,
 *      czyli akcja z bazy NIE WLACZY urzadzenia. To blokada "nie zapalaj".
 *   2. ten skrypt — aktywnie DOCIAGA urzadzenie do stanu bezpiecznego i powiadamia. To "zgas".
 * Bez warstwy 1 akcja zapalalaby co minute, a ten skrypt gasil — przekaznik by cyklowal.
 *
 * Powrot: dopiero gdy odczyt jest MLODSZY NIZ RECOVER_MIN (nie na granicy progu — inaczej urzadzenie
 * odbijaloby od progu w kolko). Skrypt tylko przestaje wymuszac; wlaczeniem zajmuje sie normalna
 * automatyka w swoim cyklu.
 *
 * Telegram (kanal WAZNE): jedna zbiorcza wiadomosc na przebieg — przy padzie calego mesha wypada
 * kilka czujek naraz i nie chcemy siedmiu osobnych powiadomien. Para wejscie + powrot z czasem
 * trwania przerwy, zeby z telefonu widziec caly epizod i nie szukac przyczyny po powrocie do domu.
 *
 * Stan trzymany w helperach `failsafe_<devid>` (epoch wejscia) — przezywa restart Pi i pozwala
 * policzyc dlugosc przerwy.
 *
 * Grzalki bufora (dev1/dev3) CELOWO nie sa tutaj — ma je `ai_bufor.php` (wlasna eskalacja blokady).
 */

require_once __DIR__ . '/lib/_log.inc.php';

const RECOVER_MIN = 15;   // min — odczyt musi byc SWIEZSZY niz tyle, zeby zdjac stan bezpieczny

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'ai_failsafe: DB ' . $db->connect_error); exit(1); }
function val($q) { global $db; $r = $db->query($q); return ($r && $row = $r->fetch_row()) ? $row[0] : null; }
function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }

/**
 * Wykaz urzadzen. `safe` = stan bezpieczny (payload MQTT), `sensors` = odczyty, ktore musza byc swieze.
 * `max` = prog wieku w minutach; zmierzone na 60 dniach maksymalne NORMALNE odstepy raportow:
 *   dev849 75 min, dev527 reku 46 min -> prog 120 min z zapasem.
 *   dev684 salon (59 min, temperatura i wilgotnosc) -> prog 240 min: przy 120 min bylo za duzo powiadomien (2026-09-11).
 *   dev10 "Patio" jest bateryjny na slabej galezi mesha (rekord 208 min) -> prog 240 min.
 */
$TARGETS = [
    850 => ['label' => 'Liwia Grzejnik', 'safe' => 'OFF', 'sensors' => [
        ['tab' => 'device849', 'col' => 'temperature', 'label' => 'Liwia Temperatura', 'max' => 120],
    ]],
    16 => ['label' => 'Nagrzewnica', 'safe' => 'OFF', 'sensors' => [
        ['tab' => 'device684', 'col' => 'temperature', 'label' => 'Salon Temperatura', 'max' => 240],
    ]],
    876 => ['label' => 'Nawilżacz', 'safe' => 'OFF', 'sensors' => [
        ['tab' => 'device684', 'col' => 'humidity', 'label' => 'Salon Wilgotność', 'max' => 240],
    ]],
    1042 => ['label' => 'Chłodnica salon (zawór)', 'safe' => 'CLOSE', 'sensors' => [
        ['tab' => 'device684', 'col' => 'temperature', 'label' => 'Salon Temperatura', 'max' => 240],
    ]],
    1045 => ['label' => 'Chłodnica salon (wentylatory)', 'safe' => 'OFF', 'sensors' => [
        ['tab' => 'device684', 'col' => 'temperature', 'label' => 'Salon Temperatura', 'max' => 240],
    ]],
    1052 => ['label' => 'Chłodnica nawiew (zawór)', 'safe' => 'CLOSE', 'sensors' => [
        ['tab' => 'device684', 'col' => 'temperature', 'label' => 'Salon Temperatura', 'max' => 240],
        ['tab' => 'device527', 'col' => 'extract_air_temperature', 'label' => 'Reku Wywiew', 'max' => 120],
    ]],
    1044 => ['label' => 'Kuchnia przewiew', 'safe' => 'OFF', 'sensors' => [
        ['tab' => 'device684', 'col' => 'temperature', 'label' => 'Salon Temperatura', 'max' => 240],
        ['tab' => 'device10',  'col' => 'temperature', 'label' => 'Patio Temperatura', 'max' => 240],
    ]],
];

// Wiek odczytu w minutach; null = tabela pusta / brak takiej kolumny = traktujemy jak brak kontaktu.
function sensorAge($s) {
    $a = val("SELECT TIMESTAMPDIFF(MINUTE, MAX(ts), NOW()) FROM {$s['tab']} WHERE {$s['col']} IS NOT NULL");
    return ($a === null) ? null : (int)$a;
}
function fmtAge($m) {
    if ($m === null) return 'brak danych';
    return ($m >= 60) ? sprintf('%d h %02d min', intdiv($m, 60), $m % 60) : "{$m} min";
}

// Dlugosc przerwy, ktora WLASNIE sie skonczyla — odstep miedzy dwoma najnowszymi odczytami.
// Powrot lapiemy w ciagu minuty od pierwszego swiezego odczytu, wiec to jest ta wlasciwa dziura.
function lastGap($s) {
    $m = val("SELECT TIMESTAMPDIFF(MINUTE, MIN(ts), MAX(ts)) FROM (SELECT ts FROM {$s['tab']} "
           . "WHERE {$s['col']} IS NOT NULL ORDER BY ts DESC LIMIT 2) x");
    return ($m === null) ? null : (int)$m;
}

$entered = [];   // urzadzenia, ktore WLASNIE weszly w stan bezpieczny
$returned = [];  // urzadzenia, ktore WLASNIE wrocily

foreach ($TARGETS as $devId => $t) {
    $dev = $db->query("SELECT device, last_state, available FROM devices WHERE id={$devId}")->fetch_assoc();
    if (!$dev) { tymos_log('WARN', "ai_failsafe: brak urzadzenia id={$devId} w bazie"); continue; }

    $stale  = [];  // czujniki przeterminowane (opis do powiadomienia)
    $causes = [];  // same nazwy winnych czujnikow (zapamietywane w helperze na powrot)
    $fresh = true; // wszystkie mlodsze niz RECOVER_MIN?
    foreach ($t['sensors'] as $s) {
        $age = sensorAge($s);
        if ($age === null || $age > $s['max']) { $stale[] = "{$s['label']} off " . fmtAge($age); $causes[] = $s['label']; }
        if ($age === null || $age > RECOVER_MIN) $fresh = false;
    }

    $h      = $db->query("SELECT label, value FROM helpers WHERE name='failsafe_{$devId}' LIMIT 1")->fetch_assoc();
    $since  = $h['value'] ?? null;
    $active = ($since !== null && $since !== '' && (int)$since > 0);

    if (!$active && !$stale) continue;              // normalna praca — nic nie robimy

    if ($active && $fresh) {                        // === POWROT ===
        $mins = max(0, intdiv(time() - (int)$since, 60));
        // Winny czujnik zapisany przy wejsciu w `helpers.label` po znaku '<-' (przy powrocie jest
        // juz swiezy, wiec inaczej nie dalo by sie go wskazac). Do tego dlugosc jego ciszy.
        $who  = trim(explode('<-', (string)($h['label'] ?? ''))[1] ?? '');
        $why  = $who ?: 'czujnik';
        foreach ($t['sensors'] as $s) {
            if ($s['label'] !== $who) continue;
            $g = lastGap($s);
            if ($g !== null) $why = "{$who} off " . fmtAge($g);
            break;
        }
        $db->query("DELETE FROM helpers WHERE name='failsafe_{$devId}'");
        $db->query("UPDATE settings SET v=UNIX_TIMESTAMP() WHERE k='tymos_reload'");
        $returned[$why][] = "{$t['label']} — blokada " . fmtAge($mins);
        tymos_log('WARN', "ai_failsafe: {$t['label']} POWROT po " . fmtAge($mins) . " (powod: {$why})");
        logMsg("POWROT: {$t['label']} po " . fmtAge($mins) . " (powod: {$why})");
        continue;
    }

    if (!$active) {                                 // === WEJSCIE ===
        $now = time();
        $lab = "Fail-safe: {$t['label']} (epoch) <- " . implode(', ', $causes);
        $db->query("INSERT INTO helpers (name, label, type, value) VALUES ('failsafe_{$devId}', "
                 . "'{$lab}', 'text', '{$now}') ON DUPLICATE KEY UPDATE label='{$lab}', value='{$now}'");
        $db->query("UPDATE settings SET v=UNIX_TIMESTAMP() WHERE k='tymos_reload'");
        $entered[] = ['co' => "{$t['label']} → {$t['safe']}", 'why' => implode(', ', $stale)];
        tymos_log('WARN', "ai_failsafe: {$t['label']} -> {$t['safe']} (" . implode('; ', $stale) . ")");
        logMsg("SAFE: {$t['label']} -> {$t['safe']} (" . implode('; ', $stale) . ")");
    }

    // Dociagniecie do stanu bezpiecznego — przy kazdym przebiegu, dopoki blokada aktywna.
    // Publikujemy tylko przy niezgodnosci i tylko gdy urzadzenie online (inaczej Z2M i tak nie dostarczy).
    if ((string)($dev['available'] ?? '1') === '0') continue;
    $ls  = json_decode($dev['last_state'] ?? '{}', true) ?: [];
    $cur = strtoupper((string)($ls['state'] ?? ''));
    if ($cur === $t['safe']) continue;
    $topic = 'zigbee2mqtt/' . $dev['device'] . '/set';
    exec('mosquitto_pub -h localhost -t ' . escapeshellarg($topic)
       . ' -m ' . escapeshellarg('{"state":"' . $t['safe'] . '"}') . ' > /dev/null 2>&1');
    logMsg("dociagam {$t['label']} {$cur} -> {$t['safe']}");
}

// Jedna zbiorcza wiadomosc na przebieg (pad mesha = kilka urzadzen naraz).
if ($entered || $returned) {
    // Format (user 2026-09-14): najpierw PRZYCZYNA (ktory czujnik i od kiedy), pod nia lista
    // urzadzen, ktore przez to zostaly wymuszone. Grupujemy po przyczynie, bo zwykle jeden martwy
    // czujnik kladzie kilka urzadzen naraz i powtarzanie go przy kazdym byloby szumem.
    $msg = '';
    if ($entered) {
        $wg = [];
        foreach ($entered as $e) $wg[$e['why']][] = $e['co'];
        foreach ($wg as $why => $lista) {
            $msg .= ($msg ? "\n\n" : '') . "⚠️ Blokada akcji (powód: {$why})\n· " . implode("\n· ", $lista);
        }
    }
    foreach ($returned as $why => $lista) {
        $msg .= ($msg ? "\n\n" : '') . "✅ Odblokowane akcje (powód: {$why})\n· " . implode("\n· ", $lista);
    }
    $proc = popen('php /opt/tymos/actions/telegram_send.php > /dev/null 2>&1', 'w');
    if ($proc) { fwrite($proc, json_encode(['type' => 'text', 'msg' => rtrim($msg), 'chat' => 'alert'])); pclose($proc); }
}
