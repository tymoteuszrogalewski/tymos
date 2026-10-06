<?php // GET — stan wszystkich elementow klimatu/basenu dla widgetu (tryby + realne stany urzadzen).
function h($db, $n) {
    $r = $db->query("SELECT value FROM helpers WHERE name='" . $db->real_escape_string($n) . "'");
    return ($r && $x = $r->fetch_assoc()) ? $x['value'] : null;
}
function dstate($db, $id) {
    $r = $db->query("SELECT last_state FROM devices WHERE id=" . (int)$id);
    $ls = ($r && $x = $r->fetch_assoc()) ? (json_decode($x['last_state'] ?? '{}', true) ?: []) : [];
    return strtoupper($ls['state'] ?? '');
}
// Pozycja rolety (dev21). 100 = calkiem w gorze, 0 = calkiem na dole.
// BRAK ODCZYTU => null, a widget wtedy NIE podswietla ani UP, ani DOWN — lepiej nie pokazac nic,
// niz pokazac "na dole", gdy roleta jest w gorze. Uwaga: notatka o gubieniu stanu przez MINI-ZBRBS
// dotyczy STEROWANIA pulse, nie odczytu — `position` w last_state jest wiarygodne.
function rolpos($db, $id = 21) {
    $r = $db->query("SELECT last_state FROM devices WHERE id=" . (int)$id);
    $ls = ($r && $x = $r->fetch_assoc()) ? (json_decode($x['last_state'] ?? '{}', true) ?: []) : [];
    return isset($ls['position']) && is_numeric($ls['position']) ? (int)$ls['position'] : null;
}
function d527($db, $col) {
    $r = $db->query("SELECT {$col} FROM device527 WHERE {$col} IS NOT NULL ORDER BY ts DESC LIMIT 1");
    return ($r && $x = $r->fetch_row()) ? $x[0] : null;
}
function izones($db) {   // strefy podlewania z realnym stanem zaworu (widget MANUAL)
    $z = [];
    $r = $db->query("SELECT name, dev_channel FROM irrigation_zones WHERE dev_channel IS NOT NULL AND enabled=1 ORDER BY sort_order, id");
    if ($r) while ($x = $r->fetch_assoc()) {
        $on = false;
        $p = explode(':', $x['dev_channel'], 2);
        if (count($p) === 2) {
            $rr = $db->query("SELECT last_state FROM devices WHERE id=" . (int)$p[0]);
            if ($rr && $y = $rr->fetch_assoc()) {
                $ls = json_decode($y['last_state'] ?? '{}', true) ?: [];
                $on = strtoupper($ls[$p[1]] ?? '') === 'ON';
            }
        }
        $z[] = ['name' => $x['name'], 'channel' => $x['dev_channel'], 'on' => $on];
    }
    return $z;
}
$fan = d527($db, 'fan_speed');       // Away/Low/Medium/High
$byp = d527($db, 'bypass_state');    // 0..100

echo json_encode([
    'ok'                  => true,
    'tryb_klimatu'        => h($db, 'tryb_klimatu') ?: 'chlodz',
    'piec'                => h($db, 'piec_wlaczony'),
    'podlogowka'          => h($db, 'podlogowka_wlaczona'),
    'reku_bieg_mode'      => h($db, 'reku_bieg_mode') ?: 'auto',
    'reku_bypass_mode'    => h($db, 'reku_bypass_mode') ?: 'auto',
    'fan_speed'           => $fan,
    'bypass_open'         => (is_numeric($byp) && (float)$byp > 0) ? 1 : 0,
    'tryb_chlodnice'      => h($db, 'tryb_chlodnice') ?: 'auto',
    'chlodnice_on'        => (dstate($db, 1045) === 'ON') ? 1 : 0,
    'tryb_chlodnice_nawiew' => h($db, 'tryb_chlodnice_nawiew') ?: 'auto',
    'nawiew_on'           => (dstate($db, 1052) === 'OPEN') ? 1 : 0,
    'komfort_target'      => h($db, 'komfort_target'),
    'tryb_freecool'       => h($db, 'tryb_freecool') ?: 'auto',
    'freecool_on'         => (dstate($db, 1044) === 'ON') ? 1 : 0,
    'force_basen'         => h($db, 'force_basen') ?: 'auto',
    'basen_on'            => (dstate($db, 1036) === 'ON') ? 1 : 0,
    'force_basen_swiatlo' => h($db, 'force_basen_swiatlo') ?: 'auto',
    'basen_swiatlo_on'    => (dstate($db, 1035) === 'ON') ? 1 : 0,
    'swiatla_ruch_mode'   => h($db, 'swiatla_ruch_mode') ?: 'auto',
    'irrigation_enabled'  => h($db, 'irrigation_enabled'),
    'irrigation_mode'     => h($db, 'irrigation_mode') ?: ((h($db, 'irrigation_enabled') === '1') ? 'auto' : 'off'),
    'irrigation_manual_zone' => h($db, 'irrigation_manual_zone') ?: '',
    'irrigation_zones'    => izones($db),
    'irrigation_running'  => h($db, 'irrigation_running_zone'),
    'roleta_pos'          => rolpos($db),
]);
