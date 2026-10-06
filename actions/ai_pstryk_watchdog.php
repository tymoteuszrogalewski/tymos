#!/usr/bin/env php
<?php
/**
 * ai_pstryk_watchdog.php — alert, gdy zabraknie ceny pradu na biezaca godzine. Cron co 15 min.
 *
 * Po co: ceny z Pstryka sa wejsciem do decyzji o grzaniu. Gdy ich nie ma, automatyka radzi sobie
 * sama, ale w zubozonym trybie: `ai_bufor.php` grzeje wylacznie do podlogi komfortu (T_FLOOR),
 * a `ai_zmywarka.php` otwiera zawor i bierze goraca wode z bufora. Dom dziala, tylko drozej —
 * i wlasnie dlatego warto o tym wiedziec, zamiast odkryc po tygodniu na rachunku.
 *
 * Prog: import (`import_pstryk.php`, akcja 37) chodzi o :10 i :40, czyli co 30 min. MISS_MIN = 90
 * to trzy nieudane proby z rzedu — pojedyncza czkawka API nie budzi nikogo.
 *
 * Jeden alert na epizod (helper `pstryk_alert`), plus wiadomosc o powrocie cen — ta sama para
 * wejscie/powrot co w `ai_failsafe.php`, zeby z telefonu bylo widac domkniecie sprawy.
 */

require_once __DIR__ . '/lib/_log.inc.php';

const MISS_MIN = 90;   // min — tyle moze brakowac ceny, zanim uznamy to za awarie importu

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'ai_pstryk_watchdog: DB ' . $db->connect_error); exit(1); }
function val($q) { global $db; $r = $db->query($q); return ($r && $row = $r->fetch_row()) ? $row[0] : null; }
function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }
function hSet($name, $label, $v) {
    global $db;
    $db->query("INSERT INTO helpers (name, label, type, value) VALUES ('{$name}', '{$label}', 'text', '{$v}') "
             . "ON DUPLICATE KEY UPDATE value='{$v}'");
}
function telegram($msg) {
    $proc = popen('php /opt/tymos/actions/telegram_send.php > /dev/null 2>&1', 'w');
    if ($proc) { fwrite($proc, json_encode(['type' => 'text', 'msg' => $msg, 'chat' => 'alert'])); pclose($proc); }
}

$cena = val("SELECT full_price_pstryk FROM energa WHERE ts = DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') "
          . "AND full_price_pstryk IS NOT NULL LIMIT 1");
$miss    = (int)(val("SELECT value FROM helpers WHERE name='pstryk_miss_since' LIMIT 1") ?? 0);
$alerted = (int)(val("SELECT value FROM helpers WHERE name='pstryk_alert' LIMIT 1") ?? 0);
$now     = time();

if ($cena !== null) {
    if ($alerted === 1) {
        // Ostatnia godzina, dla ktorej mamy cene — mowi, czy import nadrobil tez zaleglosci.
        $horyzont = val("SELECT MAX(ts) FROM energa WHERE full_price_pstryk IS NOT NULL");
        telegram("✅ Ceny Pstryka wróciły (bieżąca godzina: " . number_format((float)$cena, 2, ',', ' ') . " zł/kWh).\n"
               . "Automatyka grzania znów decyduje normalnie. Dane do: {$horyzont}.");
        tymos_log('WARN', 'ai_pstryk_watchdog: ceny wrocily');
        logMsg('ceny wrocily');
    }
    if ($miss !== 0 || $alerted !== 0) {
        $db->query("DELETE FROM helpers WHERE name IN ('pstryk_miss_since', 'pstryk_alert')");
    }
    exit(0);
}

if ($miss === 0) {
    hSet('pstryk_miss_since', 'Pstryk - brak ceny od (epoch)', $now);
    logMsg('brak ceny na biezaca godzine - zaczynam liczyc');
    exit(0);
}

$minut = intdiv($now - $miss, 60);
if ($minut < MISS_MIN || $alerted === 1) {
    logMsg("brak ceny od {$minut} min" . ($alerted ? ' (alert juz wyslany)' : ''));
    exit(0);
}

hSet('pstryk_alert', 'Pstryk - alert o braku cen wyslany', 1);
$ostatnia = val("SELECT MAX(ts) FROM energa WHERE full_price_pstryk IS NOT NULL");
telegram("⚠️ Brak ceny prądu na bieżącą godzinę od {$minut} min (import Pstryka chodzi co 30 min).\n"
       . "Ostatnia znana cena: " . ($ostatnia ?: 'brak danych') . ".\n"
       . "Skutki: bufor grzeje tylko do podłogi komfortu (45°C) — ciepła woda będzie, ale bez łapania "
       . "tanich godzin. Zmywarka bierze wodę z bufora.");
tymos_log('WARN', "ai_pstryk_watchdog: brak ceny od {$minut} min - alert wyslany");
logMsg("ALERT: brak ceny od {$minut} min");
