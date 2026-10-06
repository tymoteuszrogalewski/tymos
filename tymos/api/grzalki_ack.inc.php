<?php // POST
// "Rozumiem" klikniete na kamerze — chowa ikone do czasu, az watchdog wykryje KOLEJNA probe grzania
// bez pradu (grzalka_wd_miss > ack) albo zacznie sie nowy epizod. Sam alert w Telegramie zostaje.
$id = (int)($_POST['id'] ?? 0);
if (!in_array($id, [1, 3], true)) { echo '{"ok":false}'; exit; }
$now = time();
$db->query("INSERT INTO helpers (name, label, type, value) VALUES ('grzalka_wd_ack_dev{$id}', "
         . "'Grzalka dev{$id} - potwierdzenie awarii (epoch)', 'text', '{$now}') "
         . "ON DUPLICATE KEY UPDATE value='{$now}'");
echo json_encode(['ok' => true, 'id' => $id, 'ack' => $now]);
