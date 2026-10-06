#!/opt/tymos/venv/bin/php
<?php
/**
 * TymOS — czyszczenie starych danych z device<ID>
 * Cron: raz dziennie
 * Usuwa rekordy starsze niz 4 miesiace
 */
require_once __DIR__ . '/lib/_log.inc.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }
$db->set_charset('utf8mb4');

$cutoff = date('Y-m-d H:i:s', strtotime('-4 months'));
$tables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='tymos' AND TABLE_NAME LIKE 'device%' AND TABLE_NAME != 'devices'");
$total = 0;

while ($r = $tables->fetch_assoc()) {
    $t = $r['TABLE_NAME'];
    $db->query("DELETE FROM `{$t}` WHERE ts < '{$cutoff}'");
    $del = $db->affected_rows;
    if ($del > 0) {
        echo date('Y-m-d H:i:s') . " {$t}: usunięto {$del} wierszy\n";
        $total += $del;
    }
}

// klimat_ai — snapshot chlodnic co minute (ai_wymienniki.php), ta sama retencja
$db->query("DELETE FROM klimat_ai WHERE ts < '{$cutoff}'");
$del = $db->affected_rows;
if ($del > 0) { echo date('Y-m-d H:i:s') . " klimat_ai: usunięto {$del} wierszy\n"; $total += $del; }

echo date('Y-m-d H:i:s') . " Gotowe — łącznie usunięto {$total} wierszy (cutoff: {$cutoff})\n";
