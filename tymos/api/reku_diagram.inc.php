<?php // GET — dane do ciemnego diagramu reku (card_reku_flow). Wszystko z device527 + dev851 + coil dev16.
function d527($db, $c) {
    $r = $db->query("SELECT {$c} FROM device527 WHERE {$c} IS NOT NULL ORDER BY ts DESC LIMIT 1");
    return ($r && $x = $r->fetch_row()) ? $x[0] : null;
}
$r = $db->query("SELECT temperature FROM device851 ORDER BY ts DESC LIMIT 1");
$post = ($r && $x = $r->fetch_row()) ? $x[0] : null;
$r = $db->query("SELECT last_state FROM devices WHERE id=16");
$cls = ($r && $x = $r->fetch_assoc()) ? (json_decode($x['last_state'] ?? '{}', true) ?: []) : [];
function devon($db, $id, $want = 'ON') { // urzadzenie w zadanym stanie wg last_state.state (ON / OPEN / ...)
    $r = $db->query("SELECT last_state FROM devices WHERE id=" . (int)$id);
    if ($r && $x = $r->fetch_row()) { $j = json_decode($x[0] ?? '{}', true) ?: []; return strtoupper($j['state'] ?? '') === $want; }
    return false;
}

echo json_encode([
    'ok'         => true,
    // Czerpnia: OBA odczyty z wlasnego czujnika centrali (dev527), zeby temp i wilg pochodzily
    // z jednego miejsca — z wnetrza kanalu czerpnego. Do 2026-08-29 temp szla z dev10 „Patio",
    // czyli z czujnika w cieniu ogrodu, ktory pokazuje pogode na dzialce, a nie to, co reku
    // faktycznie zasysa. Diagram ma mowic, czym oddycha centrala.
    'outdoor_t'  => d527($db, 'outdoor_air_temperature'), 'outdoor_h' => d527($db, 'outdoor_air_humidity'),
    'supply_t'   => d527($db, 'supply_air_temperature'),   'supply_h'  => d527($db, 'supply_air_humidity'),
    'extract_t'  => d527($db, 'extract_air_temperature'),  'extract_h' => d527($db, 'extract_air_humidity'),
    'exhaust_t'  => d527($db, 'exhaust_air_temperature'),  'exhaust_h' => d527($db, 'exhaust_air_humidity'),
    'post_t'     => $post,                                  // temp za coilem post (dev851 -> do domu)
    'bypass'     => d527($db, 'bypass_state'),
    'fan'        => d527($db, 'fan_speed'),
    'mode'       => d527($db, 'operating_mode'),
    'sflow'      => d527($db, 'supply_fan_flow'),
    'eflow'      => d527($db, 'exhaust_fan_flow'),
    'power'      => d527($db, 'power'),
    'filter_days'=> d527($db, 'filter_replacement_remaining_days'),
    'rmot'       => d527($db, 'running_mean_outdoor_temperature'),
    'coil_state' => strtoupper($cls['state'] ?? ''),        // dev16 ON/OFF (grzeje/chlodzi wg delty post-supply)
    'chlodnica_salon' => devon($db, 1045),                  // Chlodnica Salon Wentylatory
    'nawiew_cooling'  => devon($db, 1052, 'OPEN'),          // Chlodnica Nawiew Zawor OPEN = zimna woda (studnia) leci przez coil POST -> chlodzi nawiew (lato)
    'went_kuchnia'    => devon($db, 1044),                  // Kuchnia Przewiew (free-cooling)
    'went_tech'       => null,                              // Wentylator techniczny — jeszcze nie istnieje
]);
