<?php // GET — czy KTOS WLASNIE DZWONI (pulsujaca sluchawka w card_camera_doorbell).
// Zrodlo: `settings.doorbell_last_ring` — stempel czasu stawiany przez actions/alarm_notify.php
// przy celach 'dzwonek_*'. Okno 60 s gasnie samo, wiec po rozmowie nie ma czego kasowac.
//
// Dwie odrzucone drogi, zeby nikt nie wracal:
//   - log z fraza 'doorbell=True' (pierwotna wersja tego API) — takich wpisow NIGDY nie bylo,
//     wiec zwracalo zawsze 0,
//   - tabela tymos_sounds — cele 'dzwonek_gong'/'dzwonek_osoba' celowo nie maja 'snd' (user ma
//     fizyczne dzwonki w gniazdkach), wiec dzwonek nie zostawia tam ani jednego wiersza.
// Samo devices.last_state dev623 tez nie wystarcza: pole `doorbell` wraca do 0 po chwili, a jego
// `__ts` rusza takze przy zbiorowym republishu stanu z kamery.
$r = $db->query("SELECT v FROM settings WHERE k='doorbell_last_ring' LIMIT 1");
$t = ($r && $row = $r->fetch_row()) ? (int)$row[0] : 0;
echo json_encode(['t' => (time() - $t <= 60) ? $t : 0]);
