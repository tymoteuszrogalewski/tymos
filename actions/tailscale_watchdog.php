#!/usr/bin/env php
<?php
/**
 * tailscale_watchdog.php — pilnuje VPN Tailscale na Pi. Cron co 15 min (akcja system_tailscale_watchdog).
 *
 * Po co: kiosk i dostep z zewnatrz ida po nazwie z Tailscale. 2026-10-08 klucz wezla wygasl po 180 dniach
 * (domyslna waznosc), Pi sie wylogowal i kiosk przestal sie ladowac — bez zadnego ostrzezenia.
 *
 * Co robi (Telegram, kanal alertow):
 *   - klucz wygasa za <= 7 dni, potem za <= 1 dzien: przypomnienie z linkiem do panelu Tailscale
 *     (tam „Disable key expiry" albo odnowienie). Gdy waznosc klucza jest wylaczona, KeyExpiry nie ma — cisza.
 *   - Pi wylogowany (NeedsLogin): od razu link do zalogowania (AuthURL z `tailscale status`).
 *     Nowy link = nowa wiadomosc; ten sam link nie jest powtarzany.
 *   - polaczenie wrocilo: potwierdzenie.
 * Telegram idzie przez zwykly internet, wiec dziala tez wtedy, gdy VPN lezy.
 *
 * Brak odczytu (tailscale nie odpowiada / zly JSON): tylko log, bez alertu i bez zmiany stanu.
 */

require_once __DIR__ . '/lib/_log.inc.php';

const WARN_DAYS = [7, 1];   // dni przed wygasnieciem, w ktorych przychodzi przypomnienie
const ADMIN_URL = 'https://login.tailscale.com/admin/machines';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'tailscale_watchdog: DB ' . $db->connect_error); exit(1); }
function val($q) { global $db; $r = $db->query($q); return ($r && $row = $r->fetch_row()) ? $row[0] : null; }
function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }
function hGet($name) { global $db; return val("SELECT value FROM helpers WHERE name='" . $db->real_escape_string($name) . "' LIMIT 1"); }
function hSet($name, $label, $v) {
    global $db;
    $v = $db->real_escape_string($v);
    $db->query("INSERT INTO helpers (name, label, type, value) VALUES ('{$name}', '{$label}', 'text', '{$v}') "
             . "ON DUPLICATE KEY UPDATE value='{$v}'");
}
function hDel($name) { global $db; $db->query("DELETE FROM helpers WHERE name='{$name}'"); }
function telegram($msg) {
    $proc = popen('php /opt/tymos/actions/telegram_send.php > /dev/null 2>&1', 'w');
    if ($proc) { fwrite($proc, json_encode(['type' => 'text', 'msg' => $msg, 'chat' => 'alert'])); pclose($proc); }
}

$st = json_decode((string)shell_exec('tailscale status --json 2>/dev/null'), true);
if (!is_array($st) || empty($st['BackendState'])) {
    tymos_log('WARN', 'tailscale_watchdog: brak odczytu z tailscale status');
    logMsg('brak odczytu');
    exit(0);
}

$state = $st['BackendState'];
$login = (string)(hGet('ts_login_alert') ?? '');

// --- Wylogowany / zatrzymany ---
if ($state !== 'Running') {
    $url = (string)($st['AuthURL'] ?? '');
    $key = $state . '|' . $url;
    if ($login === $key) { logMsg("{$state} (alert juz wyslany)"); exit(0); }
    hSet('ts_login_alert', 'Tailscale - alert o wylogowaniu wyslany', $key);
    $msg = "⚠️ Tailscale na Pi nie działa (stan: {$state}). Kiosk i dostęp z zewnątrz nie działają.\n";
    $msg .= $url
        ? "Zaloguj Pi jednym kliknięciem:\n{$url}"
        : "Brak gotowego linku — zaloguj ręcznie na Pi: tailscale up";
    $msg .= "\n\nPo zalogowaniu w panelu Tailscale przy „tymos” wybierz „Disable key expiry”, żeby klucz nie wygasał.";
    telegram($msg);
    tymos_log('WARN', "tailscale_watchdog: {$state} - alert wyslany");
    logMsg("ALERT: {$state}");
    exit(0);
}

// --- Polaczony ---
if ($login !== '') {
    hDel('ts_login_alert');
    telegram("✅ Tailscale na Pi znów połączony. Kiosk i dostęp z zewnątrz działają.");
    tymos_log('WARN', 'tailscale_watchdog: polaczenie wrocilo');
    logMsg('polaczenie wrocilo');
}

$exp = $st['Self']['KeyExpiry'] ?? null;
if (!$exp) {   // waznosc klucza wylaczona
    if (hGet('ts_expiry_stage') !== null) hDel('ts_expiry_stage');
    logMsg('ok, klucz bez daty waznosci');
    exit(0);
}

$left  = (strtotime($exp) - time()) / 86400;
$stage = hGet('ts_expiry_stage');
if ($left > WARN_DAYS[0]) {
    if ($stage !== null) hDel('ts_expiry_stage');
    logMsg(sprintf('ok, klucz wazny jeszcze %.1f dni', $left));
    exit(0);
}

// Najmniejszy prog, ktory juz przekroczylismy (7, potem 1)
$hit = null;
foreach (WARN_DAYS as $d) if ($left <= $d) $hit = $d;
if ($stage !== null && (int)$stage <= $hit) { logMsg(sprintf('klucz za %.1f dni (przypomnienie juz wyslane)', $left)); exit(0); }

hSet('ts_expiry_stage', 'Tailscale - wyslane przypomnienie o kluczu (dni)', $hit);
$kiedy = date('d.m.Y H:i', strtotime($exp));
telegram("⏳ Klucz Tailscale na Pi wygasa " . ($left < 1 ? 'dziś' : 'za ' . ceil($left) . ' dni') . " ({$kiedy}).\n"
       . "Potem Pi się wyloguje i kiosk przestanie działać.\n"
       . "W panelu Tailscale przy „tymos” wybierz „Disable key expiry”:\n" . ADMIN_URL);
tymos_log('WARN', sprintf('tailscale_watchdog: klucz wygasa za %.1f dni - przypomnienie', $left));
logMsg(sprintf('PRZYPOMNIENIE: klucz za %.1f dni', $left));
