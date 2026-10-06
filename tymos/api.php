<?php
require_once __DIR__ . '/config.inc.php';
require_once __DIR__ . '/inc/db.inc.php';

header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Content-Type: application/json');
$db->select_db('tymos');

// go2rtc — wspolne dla endpointow camera_*
$go2rtc_valid = ['parking_hd', 'parking_sd', 'basen_hd', 'basen_sd', 'ogrod_hd', 'ogrod_sd', 'doorbell_hd', 'doorbell_sd', 'doorbell_talk'];
$go2rtc_base  = 'http://localhost:1984';

// Dozwolone endpointy — budowane z listy plikow w api/
$allowed = [];
foreach (glob(__DIR__ . '/api/*.inc.php') as $f) {
    $allowed[] = basename($f, '.inc.php');
}

$action = $_REQUEST['action'] ?? '';

if (in_array($action, $allowed, true)) {
    $file = __DIR__ . '/api/' . $action . '.inc.php';
    // POST-only endpointy: pierwszy komentarz w pliku zawiera "// POST"
    $firstLine = fgets(fopen($file, 'r'));
    if (strpos($firstLine, '// POST') !== false && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo '{"ok":false,"error":"POST required"}';
        exit;
    }
    include $file;
    exit;
}

echo '{"ok":false}';
