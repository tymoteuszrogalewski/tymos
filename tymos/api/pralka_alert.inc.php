<?php // GET
// Czy pranie czeka na przelozenie do suszarki — ikona na kamerze glownej. Helper `pralka_do_suszarki`
// ustawia i kasuje actions/ai_pralka_done.php (suszarka ruszyla / nowe pranie), recznie: pralka_ack.
$r = $db->query("SELECT value FROM helpers WHERE name='pralka_do_suszarki' LIMIT 1");
$ts = ($r && $row = $r->fetch_row()) ? (int)$row[0] : 0;
echo json_encode(['ok' => true, 'czeka' => $ts > 0, 'od' => $ts]);
