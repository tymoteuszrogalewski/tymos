<?php // POST — czysci cala tabele log (przycisk kosz w /admin/logs)
$db->query("TRUNCATE TABLE log");
echo json_encode(['ok' => true]);
