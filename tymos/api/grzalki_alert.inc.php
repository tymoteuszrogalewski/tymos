<?php // GET
// Ktore grzalki bufora sa martwe (stan ON, ale zero pradu) i nie zostaly jeszcze potwierdzone
// klikiem na kamerze. Zrodlo: helpery ai_grzalki_watchdog.php (patrz tamten naglowek):
//   grzalka_wd_alert_dev<ID> — 1 przez CALY epizod awarii, gasnie dopiero gdy grzalka realnie
//                              pobierze prad (czyli user wcisnal reset STB na glowicy),
//   grzalka_wd_miss_dev<ID>  — epoch startu biezacej serii "ON bez pradu"; wraca do 0, gdy ai_bufor
//                              wylaczy gniazdko (dlatego NIE wolno na nim opierac widocznosci ikony —
//                              awaria trwa dalej, tylko akurat nie probujemy grzac).
// Potwierdzenie: grzalka_wd_ack_dev<ID> = epoch kliku. Ikona wraca, gdy pojawi sie NOWA proba
// grzania bez pradu (miss > ack) albo gdy zacznie sie nowy epizod.
$GRZALKI = [
    1 => ['label' => 'Grzałka 2 (górna)', 'pos' => 'gora'],
    3 => ['label' => 'Grzałka 1 (dolna)', 'pos' => 'dol'],
];
function h($db, $n) {
    $r = $db->query("SELECT value FROM helpers WHERE name='" . $db->real_escape_string($n) . "' LIMIT 1");
    return ($r && $row = $r->fetch_row()) ? (int)$row[0] : 0;
}
$items = [];
foreach ($GRZALKI as $id => $g) {
    if (h($db, "grzalka_wd_alert_dev{$id}") !== 1) continue;
    $miss = h($db, "grzalka_wd_miss_dev{$id}");
    $ack  = h($db, "grzalka_wd_ack_dev{$id}");
    if ($ack > 0 && $miss <= $ack) continue;     // potwierdzone i nie bylo od tego czasu nowej proby
    $items[] = [
        'id'    => $id,
        'label' => $g['label'],
        'pos'   => $g['pos'],
        'mins'  => $miss > 0 ? (int)round((time() - $miss) / 60) : null,
    ];
}
echo json_encode(['ok' => true, 'items' => $items]);
