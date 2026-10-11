<?php // POST
// Klik ikony pralki na kamerze = „tego prania nie suszymy”: chowa ikone i kasuje przypomnienie lektora.
$db->query("UPDATE helpers SET value='0' WHERE name IN ('pralka_do_suszarki', 'pralka_przypomnij')");
echo json_encode(['ok' => true]);
