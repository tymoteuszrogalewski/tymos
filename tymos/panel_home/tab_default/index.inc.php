<?php
// 2026-08-17: karta `temps` NIE ma juz swipe. Kafelki pokazuja zawsze stan biezacy,
// a swipe po dniach obsluguje sam wykres w rozwinietym divie — kontener .sw-zone
// z karta `temps_chart` (patrz card_temps.inc.php + index/swipe.inc.js).
$swipePstryk = [];
// idx 0..7 = dni wstecz (7 dni temu .. dziś)
for ($i = 7; $i >= 0; $i--) {
    $swipePstryk[] = [
        'panel'  => 'home',
        'tab'    => 'default',
        'card'   => 'pstryk',
        'params' => ['date' => date('Y-m-d', strtotime("-{$i} days"))],
    ];
}
// idx 8..14 = miesiące (bieżący, -1, -2, ... -6)
for ($m = 0; $m <= 6; $m++) {
    $swipePstryk[] = [
        'panel'  => 'home',
        'tab'    => 'default',
        'card'   => 'pstryk_month',
        'params' => ['months_back' => $m],
    ];
}
return [
    'sections' => [
        ['minColWidth' => '340px', 'cards' => [
            ['name' => 'camera_parking', 'refresh' => 0, 'cols' => 2],
        ]],
        ['colWidths' => '1fr 1fr', 'columns' => [
            [
                ['name' => 'camera_doorbell', 'refresh' => 0],
                ['name' => 'klimat', 'refresh' => 0],
            ],
            [
                // Pogoda NAD cenami: prognoza na dzis i jutro (weather_hourly, import co 30 min).
                // refresh 1800 = kiosk sam podciaga swiezsza prognoze, bo ta zmienia sie w ciagu dnia.
                ['name' => 'pogoda', 'refresh' => 1800],
                ['name' => 'pstryk', 'swipe' => $swipePstryk, 'swipeIdx' => 7],
                // Rozwiniecie karty `pstryk` (klik w wykres cen) — OSOBNA karta, nie div w srodku.
                // Gdyby siedziala w `pstryk`, swipe po dniach przesuwalby i chowal ja razem z wykresem
                // cen, bo swipe wymienia CALA zawartosc karty. Domyslnie ukryta (patrz card_pstryk_load).
                ['name' => 'pstryk_load', 'refresh' => 0],
                ['name' => 'smieci', 'refresh' => 0],
                // Podlewanie i basen — prawa kolumna pod kalendarzem (2026-08-23), zeby lewa
                // z kamerami i klimatem nie rosla w nieskonczonosc.
                ['name' => 'woda', 'refresh' => 0],
            ],
        ]],
    ],
];
