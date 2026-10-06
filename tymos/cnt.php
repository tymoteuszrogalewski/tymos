<?php
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

require_once __DIR__ . '/inc/panels.inc.php';
require_once __DIR__ . '/inc/db.inc.php';

function jsonErr($msg, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => $msg]);
    exit;
}

// --- walidacja panel ---
$panels    = scanPanels();
$panel     = $_GET['panel'] ?? '';
if (!isset($panels[$panel])) jsonErr('invalid panel');

$panelCfg  = $panels[$panel];

// --- walidacja tab ---
$tabs      = scanTabs($panel);
$validTabs = array_column($tabs, 'name');
$defaultTab = $panelCfg['default_tab'] ?? ($validTabs[0] ?? '');
$tab       = $_GET['tab'] ?? $defaultTab;
if (!in_array($tab, $validTabs, true)) $tab = $defaultTab;

// --- żądanie karty ---
$card = $_GET['card'] ?? '';
if ($card !== '') {
    // Dynamiczne karty auto_temp_* i auto_battery
    if (strpos($card, 'auto_temp_') === 0 || $card === 'auto_battery') {
        header('Content-Type: text/html; charset=UTF-8');
        include __DIR__ . "/inc/auto_cards.inc.php";
        exit;
    }
    $validCards = scanCards($panel, $tab);
    if (!in_array($card, $validCards, true)) jsonErr('invalid card');

    $cardFile = __DIR__ . "/panel_{$panel}/tab_{$tab}/card_{$card}.inc.php";

    header('Content-Type: text/html; charset=UTF-8');
    include $cardFile;
    exit;
}

// --- żądanie layoutu (brak card=) ---
$layout   = [];
$indexFile = __DIR__ . "/panel_{$panel}/tab_{$tab}/index.inc.php";
if (file_exists($indexFile)) {
    $layout = include $indexFile;
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'panel'  => $panel,
    'tab'    => $tab,
    'tabs'   => $tabs,
    'layout' => $layout,
]);
