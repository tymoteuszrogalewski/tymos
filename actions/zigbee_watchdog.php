<?php
/**
 * zigbee_watchdog.php — SCIEZKA A: wykrywa CICHY zwis Z2M/dongla (staleness).
 *
 * Logika:
 *   MAX(ts) ze WSZYSTKICH raw tabel device<ID> dla non-battery Zigbee
 *   > STALE_THRESHOLD sek temu = brak push z urzadzen → uznajemy ze wisi.
 *   NIE uzywamy devices.last_seen bo to faszowane (tymos.py updateuje przy
 *   kazdym MQTT message bridge'a, nawet retained po Z2M restart) — last_seen
 *   jest swieze mimo ze raw push do device<ID> stoja.
 *
 * Prog 180s: zmierzony normalny max odstep miedzy pushami w calej sieci to ~59s
 * (24h, poza zwisami), gniazdka raportuja srednio co ~2s. 180s = 3x margines,
 * lapie cichy zwis w ~3-4 min zamiast ~11 min. Ciche zwisy (bez bledu w logu
 * Z2M) lapie TYLKO ta sciezka — sciezka B (tymos.py) reaguje na fatalny blad.
 *
 * Reboot + cooldown + Telegram robi wspolny actions/zigbee_recover.php
 * (ten sam cooldown co sciezka B => zero podwojnych rebootow).
 *
 * Wywolanie: cron co minute z akcji 'system_zigbee_watchdog'.
 */

require_once __DIR__ . '/lib/_log.inc.php';

const STALE_THRESHOLD  = 180;  // 3 min — ponizej tego progu Zigbee uznajemy za zdrowy (norma ~59s)

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

// 1. najswiezszy raw push do device<ID> dla non-battery Zigbee (UNION ALL po wszystkich tabelach)
$ids = [];
$r = $db->query("SELECT id, fields FROM devices WHERE deleted=0 AND ieee LIKE '0x%'");
while ($row = $r->fetch_assoc()) {
    $fields = json_decode($row['fields'] ?? '{}', true) ?: [];
    if (isset($fields['battery'])) continue;  // bateryjne pomijamy (spia, milcza dlugo)
    $ids[] = (int)$row['id'];
}
if (!$ids) exit(0);

// Tabela `device<ID>` powstaje dopiero przy pierwszym pushu z wlaczona statystyka. Urzadzenie,
// ktore NIC nie publikuje (router Zigbee), nie ma jej wcale — bez tego filtra UNION ALL wywalal
// caly watchdog fatalem (2026-08-22, `ti.router` id 1085), czyli auto-recovery koordynatora
// przestawalo dzialac przez jedno nowe urzadzenie.
$exists = [];
$rt = $db->query("SELECT table_name FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name REGEXP '^device[0-9]+$'");
while ($t = $rt->fetch_row()) $exists[(int)substr($t[0], 6)] = true;
$ids = array_values(array_filter($ids, function($id) use ($exists) { return isset($exists[$id]); }));
if (!$ids) exit(0);

$parts = [];
foreach ($ids as $id) {
    $parts[] = "SELECT MAX(ts) AS ts FROM `device{$id}`";
}
$sub = implode(' UNION ALL ', $parts);
$row = $db->query("SELECT UNIX_TIMESTAMP(MAX(ts)) AS max_ts, TIMESTAMPDIFF(SECOND, MAX(ts), NOW()) AS sec_ago
                   FROM ({$sub}) t")->fetch_assoc();
$max_ts  = (int)($row['max_ts'] ?? 0);
$sec_ago = (int)($row['sec_ago'] ?? 0);

// 2. powrot po recovery: zigbee_recover.php zostawia zigbee_recover_pending = ts wykrycia zwisu.
// Dowodem zycia jest push NOWSZY od tego ts (+5 s marginesu) — sam swiezy MAX(ts) nie wystarcza,
// bo sciezka B (fatal w logu Z2M) odpala recovery natychmiast, gdy pushe sa jeszcze sprzed sekund.
// BRAK ODCZYTU (blad SELECT) => pending = 0 => zadnej wiadomosci, staleness ponizej dziala normalnie.
$rp      = $db->query("SELECT v FROM settings WHERE k='zigbee_recover_pending'");
$pending = $rp ? (int)($rp->fetch_assoc()['v'] ?? 0) : 0;
if ($pending > 0 && $max_ts > $pending + 5) {
    $down    = $max_ts - $pending;
    $down_s  = ($down < 120) ? "{$down} s" : round($down / 60) . ' min';
    $rt      = $db->query("SELECT v FROM settings WHERE k='dongle_reboot_last_target'");
    $target  = $rt ? (string)($rt->fetch_assoc()['v'] ?? '?') : '?';
    $db->query("UPDATE settings SET v='0' WHERE k='zigbee_recover_pending'");
    tymos_log('INFO', "Zigbee wrocil po {$down} s (target={$target})");

    // Ten sam uklad co w zigbee_recover.php: slowo klucz na poczatku linii, szczegoly po myslniku.
    $msg = "WROCILO ZIGBEE — urzadzenia znow raportuja, automatyka dziala\n"
         . "PRZESTOJ {$down_s} — od wykrycia zwisu do pierwszego pusha\n"
         . "RESTART: {$target}";
    $payload = json_encode(['type' => 'text', 'msg' => $msg, 'chat' => 'alert']);
    $proc = popen('php /opt/tymos/actions/telegram_send.php > /dev/null 2>&1', 'w');
    if ($proc) { fwrite($proc, $payload); pclose($proc); }
}

if ($sec_ago < STALE_THRESHOLD) exit(0);

// 3. Zwis wykryty — delegacja do wspolnego recovery (cooldown + reboot + Telegram tam).
// Wspolny cooldown ze sciezka B => brak podwojnego rebootu.
shell_exec('php /opt/tymos/actions/zigbee_recover.php ' . escapeshellarg("stale {$sec_ago}s") . ' > /dev/null 2>&1');
exit(0);
