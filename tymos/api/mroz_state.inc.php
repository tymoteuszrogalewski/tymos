<?php // GET
// Czy trwa blokada mrozowa — sniezynka w pasku ostrzezen na kamerze glownej. Helper `mroz_blokada`
// (1/0) ustawia actions/ai_mroz.php z histereza 2/7 °C. Od 2026-11-07 zamiast powiadomienia Telegram.
$r = $db->query("SELECT value FROM helpers WHERE name='mroz_blokada' LIMIT 1");
$on = ($r && $row = $r->fetch_row()) ? ((string)$row[0] === '1') : false;
echo json_encode(['ok' => true, 'on' => $on]);
