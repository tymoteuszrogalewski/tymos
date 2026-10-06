<?php // POST
// Reczne otwarcie/zamkniecie sieci Zigbee z ikony na kamerze.
//   v=1 — otworz BEZ LIMITU (helper zrejoin_force=1; podtrzymuje ai_zigbee_rejoin.php co minute,
//         bo pojedyncze zadanie moze miec max 254 s),
//   v=0 — zamknij od razu i skasuj tez okno automatyczne po starcie Z2M (zrejoin_until).
$v = (string)($_POST['v'] ?? '');
if (!in_array($v, ['0', '1'], true)) { echo '{"ok":false}'; exit; }

if ($v === '1') {
    $db->query("INSERT INTO helpers (name, label, type, value) VALUES ('zrejoin_force', "
             . "'Rejoin - reczne otwarcie sieci na stale', 'text', '1') ON DUPLICATE KEY UPDATE value='1'");
    $time = 240;
} else {
    $db->query("DELETE FROM helpers WHERE name IN ('zrejoin_force', 'zrejoin_until')");
    $time = 0;
}
exec('mosquitto_pub -h localhost -t ' . escapeshellarg('zigbee2mqtt/bridge/request/permit_join')
   . ' -m ' . escapeshellarg('{"time":' . $time . '}') . ' > /dev/null 2>&1');

// Telegram takze przy RECZNYM sterowaniu — inaczej w kanale widac tylko otwarcia z automatu
// i po fakcie nie wiadomo, czy siec zamknal watchdog, czy ktos w domu kliknal ikone na kamerze.
$msg = ($v === '1')
    ? "🔓 Zigbee: sieć otwarta BEZ LIMITU czasu.\nOtworzył: ręcznie, ikoną na kamerze. "
      . "Zostanie otwarta do ponownego kliknięcia."
    : "🔒 Zigbee: sieć zamknięta.\nZamknął: ręcznie, ikoną na kamerze.";
$proc = popen('php /opt/tymos/actions/telegram_send.php > /dev/null 2>&1', 'w');
if ($proc) { fwrite($proc, json_encode(['type' => 'text', 'msg' => $msg, 'chat' => 'alert'])); pclose($proc); }

echo json_encode(['ok' => true, 'on' => ($v === '1')]);
