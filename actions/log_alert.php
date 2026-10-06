#!/usr/bin/env php
<?php
/**
 * log_alert.php — wypycha NOWE bledy z tabeli `log` na kanal alertow Telegram (TG_CHAT_ALERT).
 * Akcja cron `system_log_alert`.
 *
 * Znacznik: settings.log_alert_last_id = najwyzsze id `log` przejrzane w poprzednim biegu.
 * Pierwsze uruchomienie ustawia go na biezace MAX(id) i NIC nie wysyla (zeby nie wysypac historii).
 *
 * WYKLUCZENIA (swiadome, zmierzone 2026-07-29 na 7 dniach logu):
 *   z2m*      — 2665 ERROR/7dni (~380/dobe), glownie per-device timeouty. Realna smierc
 *               koordynatora ma wlasny alert w zigbee_recover.php, wiec tutaj byl by tylko szum.
 *   telegram* — ochrona przed PETLA: blad wysylki loguje ERROR -> nastepny bieg wysyla go ->
 *               znowu blad -> ... Bez tego jeden padniety token zapetla kanal.
 *
 * Limit MAX_SEND na bieg — przy burzy bledow wysyla probke + liczbe pozostalych, nie 300 wiadomosci.
 *
 * COOLDOWN per zrodlo (2026-08-04): bledy sa grupowane po `source` i jedno zrodlo moze odezwac sie
 * najwyzej raz na COOLDOWN sekund. Powtorki w tym oknie sa liczone, ale NIE wysylane — znacznik
 * i tak idzie do przodu, wiec nie wroca pozniej. Bez tego kazdy timeout BleBoxa = osobna wiadomosc.
 * Stan: settings.log_alert_cd_<source> = epoch ostatniej wyslanej wiadomosci z tego zrodla.
 *
 * PROG W OKNIE (2026-08-09): pojedynczy blad NIE alarmuje. Zrodlo odzywa sie dopiero, gdy ma
 * >= THRESHOLD bledow w ostatnich WINDOW sekundach (zliczane z calej tabeli `log`, nie tylko
 * z nowej paczki — wolno kapiace bledy sie kumuluja w oknie). Ponizej progu: cisza, blad
 * zostaje w admin -> Logi. Zrodla z CRITICAL_SOURCES alarmuja od pierwszego wystapienia.
 */

require_once __DIR__ . '/lib/_log.inc.php';

const MAX_SEND  = 8;
const COOLDOWN  = 1800;  // sekundy — min. odstep miedzy wiadomosciami z tego samego `source`
const THRESHOLD = 3;     // min. liczba bledow zrodla w oknie WINDOW, zeby wyslac alert
const WINDOW    = 1800;  // sekundy — okno zliczania wstecz

// Zrodla krytyczne — alert od pierwszego bledu, bez progu.
const CRITICAL_SOURCES = [];

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'log_alert: DB ' . $db->connect_error); exit(1); }

function val($q) { global $db; $r = $db->query($q); return ($r && $row = $r->fetch_row()) ? $row[0] : null; }

$maxId = (int)val("SELECT COALESCE(MAX(id),0) FROM log");
$last  = val("SELECT v FROM settings WHERE k='log_alert_last_id'");

// Pierwszy bieg: tylko ustaw znacznik, nic nie wysylaj.
if ($last === null) {
    $db->query("INSERT INTO settings (k,v) VALUES ('log_alert_last_id','{$maxId}')");
    exit(0);
}
$last = (int)$last;
if ($maxId <= $last) exit(0);

$where = "id > {$last} AND id <= {$maxId} AND level='ERROR'"
       . " AND source NOT LIKE 'z2m%' AND source NOT LIKE 'telegram%'";

$total = (int)val("SELECT COUNT(*) FROM log WHERE {$where}");
if ($total === 0) {
    $db->query("UPDATE settings SET v='{$maxId}' WHERE k='log_alert_last_id'");
    exit(0);
}

// Grupowanie po zrodle: z kazdego bierzemy NAJNOWSZY wpis + liczbe wystapien.
$res = $db->query("SELECT DATE_FORMAT(ts,'%H:%i') t, source, message FROM log WHERE {$where} ORDER BY id");
$grp = [];
while ($row = $res->fetch_assoc()) {
    $s = $row['source'];
    $grp[$s] = ['n' => ($grp[$s]['n'] ?? 0) + 1, 'row' => $row];
}

// Cooldown per zrodlo — kto sie odezwal mniej niz COOLDOWN temu, ten teraz milczy.
// Prog w oknie — zrodlo niekrytyczne musi miec >= THRESHOLD bledow w ostatnich WINDOW sek.
$now   = time();
$lines = [];
$keys  = [];
$muted = 0;
$below = 0;
foreach ($grp as $s => $g) {
    $key  = 'log_alert_cd_' . substr(preg_replace('/[^a-z0-9_:]/i', '_', $s), 0, 50);
    $prev = (int)val("SELECT v FROM settings WHERE k='" . $db->real_escape_string($key) . "'");
    if ($now - $prev < COOLDOWN) { $muted += $g['n']; continue; }

    $inWindow = $g['n'];
    if (!in_array($s, CRITICAL_SOURCES, true)) {
        $inWindow = (int)val("SELECT COUNT(*) FROM log WHERE level='ERROR'"
            . " AND source='" . $db->real_escape_string($s) . "'"
            . " AND ts > NOW() - INTERVAL " . WINDOW . " SECOND");
        if ($inWindow < THRESHOLD) { $below += $g['n']; continue; }
    }
    if (count($lines) >= MAX_SEND) { $muted += $g['n']; continue; }

    $txt = $g['row']['t'] . ' ' . $s . ': ' . mb_substr((string)$g['row']['message'], 0, 200);
    if ($inWindow > 1) $txt .= ' (' . $inWindow . '× w ' . (WINDOW / 60) . ' min)';
    $lines[] = htmlspecialchars($txt, ENT_QUOTES, 'UTF-8');
    $keys[]  = $key;
}

// Wszystko w cooldownie — przesun znacznik i cisza.
if (empty($lines)) {
    $db->query("UPDATE settings SET v='{$maxId}' WHERE k='log_alert_last_id'");
    exit(0);
}

$head = '⚠️ <b>TymOS — bledy (' . $total . ')</b>';
if ($muted > 0) $lines[] = '... i ' . $muted . ' wyciszonych (cooldown)';

$payload = ['type' => 'text', 'msg' => $head . "\n" . implode("\n", $lines), 'chat' => 'alert'];

$h = popen('php ' . __DIR__ . '/telegram_send.php > /dev/null 2>&1', 'w');
if (!$h) { tymos_log('ERROR', 'log_alert: popen telegram_send nie powiodl sie'); exit(1); }
fwrite($h, json_encode($payload, JSON_UNESCAPED_UNICODE));
pclose($h);

// Cooldown i znacznik przesuwamy DOPIERO po wyslaniu — jesli skrypt padnie wczesniej,
// nastepny bieg powtorzy (zamiast wyciszyc zrodlo, ktore nigdy nie doszlo).
foreach ($keys as $k) {
    $db->query("REPLACE INTO settings (k,v) VALUES ('" . $db->real_escape_string($k) . "','{$now}')");
}
$db->query("UPDATE settings SET v='{$maxId}' WHERE k='log_alert_last_id'");
echo date('Y-m-d H:i:s') . " | log_alert: wyslano " . count($lines) . " linii z {$total} bledow, {$muted} wyciszonych (id {$last}->{$maxId})\n";
