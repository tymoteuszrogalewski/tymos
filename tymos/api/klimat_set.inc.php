<?php // POST — ustaw tryb elementu klimatu/basenu (widget panel_heat). Od razu odpala odpowiedni skrypt (instant).
$key = $_POST['key'] ?? '';
$val = (string)($_POST['value'] ?? '');

// whitelist: helper => dozwolone wartosci
$allowed = [
    'piec_wlaczony'       => ['0', '1'],
    'podlogowka_wlaczona' => ['0', '1'],
    'reku_bieg_mode'      => ['auto', 'off', '1', '2', '3'],
    'reku_bypass_mode'    => ['auto', 'off', 'on'],
    'tryb_chlodnice'        => ['auto', 'off', 'on'],
    'tryb_chlodnice_nawiew' => ['auto', 'off', 'on'],
    'tryb_freecool'         => ['auto', 'off', 'on'],
    'force_basen'         => ['auto', 'on', 'off'],
    'force_basen_swiatlo' => ['auto', 'on', 'off'],
    'irrigation_enabled'  => ['0', '1'],
    'irrigation_mode'     => ['auto', 'manual', 'off'],
    'tryb_klimatu'        => ['grzej', 'chlodz', 'off'],
    'swiatla_ruch_mode'   => ['auto', 'on', 'off'],   // swiatla na czujki ruchu w domu (card_woda)
];
if (!isset($allowed[$key]) || !in_array($val, $allowed[$key], true)) { echo '{"ok":false}'; exit; }

$ok = $db->query("UPDATE helpers SET value='" . $db->real_escape_string($val) . "' WHERE name='" . $db->real_escape_string($key) . "'");

// Natychmiastowe zastosowanie: odpal odpowiedni skrypt raz (fire-and-forget). Skrypty czytaja helpery na swiezo.
$scripts = [
    'reku_bieg_mode'      => 'ai_rekuperator.php',
    'reku_bypass_mode'    => 'ai_rekuperator.php',
    'tryb_chlodnice'        => 'ai_wymienniki.php',
    'tryb_chlodnice_nawiew' => 'ai_wymienniki.php',
    'tryb_freecool'         => 'ai_wymienniki.php',
    'force_basen'         => 'ai_basen.php',
    'force_basen_swiatlo' => 'ai_basen.php',
    'podlogowka_wlaczona' => 'ai_podlogowka.php',
    'irrigation_enabled'  => 'irrigation.php',
    'irrigation_mode'     => 'irrigation.php',
];
if (isset($scripts[$key])) exec('php /opt/tymos/actions/' . $scripts[$key] . ' > /dev/null 2>&1 &');

// Podlewanie: irrigation_mode = zrodlo prawdy; lustro irrigation_enabled dla panel_podlewanie (Auto/OFF).
if ($key === 'irrigation_mode') {
    $db->query("UPDATE helpers SET value='" . (($val === 'auto') ? '1' : '0') . "' WHERE name='irrigation_enabled'");
}

// tryb_klimatu = master sezonu (grzej/chlodz/off): czytany przez ai_rekuperator + ai_wymienniki (i akcje cron 26/27).
// Reload demona (bramkuje cronowe uruchomienia) + natychmiast odpal oba skrypty.
if ($key === 'tryb_klimatu') {
    require_once __DIR__ . '/../inc/helpers.inc.php';
    tymos_notify_reload($db);
    exec('php /opt/tymos/actions/ai_rekuperator.php > /dev/null 2>&1 &');
    exec('php /opt/tymos/actions/ai_wymienniki.php > /dev/null 2>&1 &');
}

// swiatla_ruch_mode: warunki 'helper' w akcjach 4/11-20 czyta demon z cache helperow, ktory odswieza
// sie TYLKO przy reloadzie — bez flagi zmiana zadzialalaby dopiero po 30 min. Bez automatycznego
// powrotu do AUTO (user 2026-09-07: „bezpiecznik zbedny") — tryb trzyma, az sie go recznie zmieni.
if ($key === 'swiatla_ruch_mode') {
    require_once __DIR__ . '/../inc/helpers.inc.php';
    tymos_notify_reload($db);
}

echo json_encode(['ok' => (bool)$ok, 'key' => $key, 'value' => $val]);
