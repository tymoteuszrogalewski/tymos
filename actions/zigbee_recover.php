<?php
/**
 * zigbee_recover.php — wspolny recovery dongla Zigbee: cooldown + reboot + Telegram.
 *
 * Wolane przez DWIE sciezki wykrywania:
 *   A) actions/zigbee_watchdog.php  — staleness (brak pushow > prog), cron co 1 min
 *   B) daemons/tymos.py            — fatalny blad Z2M w bridge/logging, natychmiast
 *
 * Wspolny cooldown (settings.dongle_reboot_last_ts) => A i B NIGDY nie zrobia
 * podwojnego rebootu. flock serializuje rownolegle wywolania (B moze wypluc serie
 * bledow w tej samej sekundzie — jeden crash = kilka linii ERROR).
 *
 * ESKALACJA TARGETU (2026-08-27): pierwsza proba to `mg24` (= chip Zigbee; od 2026-09-09 SLZB-06Mg26U, alias
 * zachowany w dongle_reboot.php) — restart samego chipa Zigbee.
 * Jest szybszy niz `all` i NIE rusza ESP32, czyli nie mruga linkiem ethernet. To wazne, bo
 * mrugniecie linku samo w sobie jest u nas zapalnikiem: sesja ASH-over-TCP nie znosi nawet
 * krotkiej przerwy w sieci. Dopiero gdy `mg24` nie pomogl (kolejne wywolanie recovery w oknie
 * ESCALATE_WINDOW), siegamy po `all` = pelny reboot ESP32 + MG24, ~50 s przestoju.
 * Ostatni uzyty target trzymamy w settings.dongle_reboot_last_target.
 *
 * Uzycie: php zigbee_recover.php "<reason>"
 */

require_once __DIR__ . '/lib/_log.inc.php';

const REBOOT_COOLDOWN = 600;   // 10 min — min miedzy auto-rebootami (> pelny czas recovery ~3.4 min)
const ESCALATE_WINDOW = 1800;  // 30 min — w tym oknie po nieskutecznym `mg24` eskalujemy do `all`

$reason = $argv[1] ?? 'unknown';

// flock — tylko jedna proba recovery naraz. Gdy pliku nie da sie otworzyc, dzialaj bez locka
// (cooldown ponizej i tak chroni); gdy lock zajety przez inna probe — wychodzimy cicho.
$lock = @fopen('/tmp/tymos/zigbee_recover.lock', 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);  // inna proba recovery juz trwa
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

// --- cooldown ---
$now = time();
$row = $db->query("SELECT v FROM settings WHERE k='dongle_reboot_last_ts'")->fetch_assoc();
$last_ts = (int)($row['v'] ?? 0);
$since   = $now - $last_ts;
if ($last_ts > 0 && $since < REBOOT_COOLDOWN) {
    tymos_log('WARN', sprintf(
        'Recovery Zigbee (%s), ale cooldown — od ostatniego rebootu %d s, czekam', $reason, $since
    ));
    exit(0);
}

// --- wybor targetu: najpierw lekki mg24, po nieskutecznej probie eskalacja do all ---
$row_t       = $db->query("SELECT v FROM settings WHERE k='dongle_reboot_last_target'")->fetch_assoc();
$last_target = (string)($row_t['v'] ?? '');
$target      = ($last_target === 'mg24' && $since < ESCALATE_WINDOW) ? 'all' : 'mg24';

// 2026-08-29..2026-09-09 koordynator byl na USB (Max, potem awaryjny NOUS E16) — dongle_reboot zahaszowany.
// 2026-09-09 SMLIGHT SLZB-06Mg26U po PoE/TCP: lancuch watchdog -> dongle_reboot.php (HTTP API SMLIGHT) dziala znowu.
tymos_log('WARN', sprintf('Recovery Zigbee (%s) — odpalam dongle_reboot %s', $reason, $target));
$out = trim((string)shell_exec('php /opt/tymos/actions/dongle_reboot.php ' . $target . ' 2>&1'));
$ok  = (strpos($out, 'OK target=' . $target) !== false);

// zapisz timestamp i target proby (udanej i nieudanej — cooldown i eskalacja dzialaja w obu wypadkach)
$db->query("
    INSERT INTO settings (k, v) VALUES ('dongle_reboot_last_ts', '$now')
    ON DUPLICATE KEY UPDATE v='$now'
");
$db->query("
    INSERT INTO settings (k, v) VALUES ('dongle_reboot_last_target', '$target')
    ON DUPLICATE KEY UPDATE v='$target'
");

// zawieszony recovery — zigbee_watchdog.php wysle "WROCILO", gdy zobaczy push NOWSZY od $now.
// Ustawiamy tez po nieudanym rebootie: Zigbee moze wstac sam, a wtedy tez chcemy o tym wiedziec.
$db->query("
    INSERT INTO settings (k, v) VALUES ('zigbee_recover_pending', '$now')
    ON DUPLICATE KEY UPDATE v='$now'
");

// --- Telegram ---
// Uklad wiadomosci: SLOWO KLUCZ na poczatku kazdej linii, szczegoly po myslniku (opcjonalne do czytania).
// Rzutem oka widac stan: ZONK / RESTART / WROCILO (to ostatnie leci z zigbee_watchdog.php).
$cooldown_min = (int)(REBOOT_COOLDOWN / 60);
if ($ok) {
    $co  = ($target === 'all')
         ? 'RESTART calego dongla (ESP32 + chip Zigbee) — eskalacja po nieskutecznym restarcie chipa, ~40 s przestoju'
         : 'RESTART chipa Zigbee (MG24) — bez ruszania sieci, ESP32 nie mruga linkiem';
    $msg = "ZONK ZIGBEE — koordynator nie odpowiada, automatyka stoi\n"
         . "{$co}\n"
         . "BLOKADA {$cooldown_min} min — bez kolejnego auto-rebootu (ochrona przed petla)\n"
         . "POWOD: {$reason}";
} else {
    $msg = "ZONK ZIGBEE — koordynator nie odpowiada, automatyka stoi\n"
         . "RESTART NIEUDANY ({$target}) — dongiel nie przyjal komendy\n"
         . "PONOWNA PROBA za {$cooldown_min} min\n"
         . "POWOD: {$reason}\n"
         . "BLAD: {$out}";
}
// Kanal WAZNE: padniety koordynator = cala automatyka domu stoi, to nie jest wiadomosc do wyciszonego kanalu.
$payload = json_encode(['type' => 'text', 'msg' => $msg, 'chat' => 'alert']);
$proc = popen('php /opt/tymos/actions/telegram_send.php > /dev/null 2>&1', 'w');
if ($proc) { fwrite($proc, $payload); pclose($proc); }

exit($ok ? 0 : 1);
