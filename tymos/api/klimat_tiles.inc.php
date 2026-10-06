<?php // GET — wartosci z kafelkow pogodowych, do nakladek na kamerach (proba 2026-08-23)
// slonce=device1043, cien=device10, dom=device684 (temp + wilgotnosc).
// Salon ma druga czujke (dev852) jako ZAPAS — demon przepisuje jej odczyt na dev684, gdy glowna
// milczy, wiec tutaj jest juz jedna wartosc i nie ma czego porownywac (2026-09-19).
// `stale` = odczyt starszy niz 2 h, ten sam prog co w card_temps — nakladka ma prawo
// pokazac, ze wartosc jest nieaktualna, zamiast klamac swiezoscia.
const KT_STALE_SEC = 7200;
require_once __DIR__ . '/../inc/outdoor_temps.inc.php';
$out = ['ok' => true];
foreach ([['slonce', 'device1043'], ['cien', 'device10'], ['dom', 'device684']] as [$key, $tbl]) {
    $hum = ($key === 'dom') ? ', humidity' : '';
    $res = @$db->query("SELECT ts, temperature{$hum} FROM `{$tbl}` ORDER BY ts DESC LIMIT 1");
    $r   = ($res && $row = $res->fetch_assoc()) ? $row : null;
    $age = $r ? (time() - strtotime($r['ts'])) : PHP_INT_MAX;
    $out[$key] = [
        't'     => $r !== null && $r['temperature'] !== null ? round((float)$r['temperature'], 1) : null,
        'stale' => $age > KT_STALE_SEC,
    ];
    if ($key === 'dom') $out[$key]['h'] = ($r && $r['humidity'] !== null) ? (int)round((float)$r['humidity']) : null;
}
// Niezmiennik slonce >= cien (patrz inc/outdoor_temps.inc.php). Tylko gdy OBA odczyty swieze —
// przestarzalym cieniem z popoludnia nie wolno podciagac swiezego slonca z wieczora.
if (!$out['slonce']['stale'] && !$out['cien']['stale']) {
    $out['slonce']['t'] = outdoor_sun_fix($out['slonce']['t'], $out['cien']['t']);
}

// Temperatura komfortowa (helper `komfort_target`) — nakladka na dzwonku koloruje wg niej wartosc
// „dom": to ona mowi, czy w domu jest jak ma byc, a nie nastawa pojedynczego termostatu.
$r = $db->query("SELECT value FROM helpers WHERE name='komfort_target' LIMIT 1");
$out['komfort'] = ($r && $row = $r->fetch_row()) ? (float)$row[0] : null;

// Pozycja rolety (dev21) — sterownik siedzi w pasie dzwonka, wiec jej stan musi przyjsc razem
// z reszta kafelkow. 100 = calkiem w gorze, 0 = na dole. BRAK ODCZYTU => null, wtedy nakladka
// nie podswietla zadnej strzalki, zamiast zgadywac polozenie.
$out['roleta_pos'] = null;
$out['roleta_dir'] = null;      // 'up' | 'down' | null (stoi albo nie wiadomo)
$r = $db->query("SELECT last_state FROM devices WHERE id=21");
if ($r && $row = $r->fetch_assoc()) {
    $ls = json_decode($row['last_state'] ?? '{}', true) ?: [];
    if (isset($ls['position']) && is_numeric($ls['position'])) $out['roleta_pos'] = (int)$ls['position'];
    // motor_run_status nie trafia do tabeli historycznej, jest tylko w last_state.
    // ETYKIETY TEGO STEROWNIKA (odczytane z logu Z2M 2026-08-29, skorelowane ze zmiana position):
    //   Forward -> position ROSNIE (82 -> 97 -> 100) = jedzie W GORE
    //   Reverse -> position MALEJE (100 -> 85 -> 70)  = jedzie W DOL
    //   Stop    -> stoi
    // Nie sa to open/close ani up/down, wiec nazwy trzeba mapowac wprost. Kazda inna wartosc
    // zostawia null i zadna strzalka nie swieci — nie zgadujemy kierunku.
    $m = strtolower((string)($ls['motor_run_status'] ?? ''));
    if     ($m === 'forward') $out['roleta_dir'] = 'up';
    elseif ($m === 'reverse') $out['roleta_dir'] = 'down';
}

echo json_encode($out);
