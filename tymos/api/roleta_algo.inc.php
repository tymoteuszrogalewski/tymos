<?php // GET — "algorytm rolety" na zywo: wszystkie warianty (rano / dzien / geometria / zachod / blokada / flagi)
// z ocena warunkow TAK/NIE wg AKTUALNYCH odczytow. Pokazywane po dlugim przytrzymaniu okna rolety w karcie
// dzwonka (card_camera_doorbell), zeby nie trzeba bylo pytac "czy roleta powinna juz zjechac".
//
// Warunki akcji 33 i 110 sa czytane Z BAZY (actions.conditions) i oceniane tym samym sposobem, co w demonie
// (tymos.py: eval_single_condition / resolve_value / _is_stale) — po edycji akcji w adminie okienko samo sie
// dostosuje. Akcja 95 jest skryptem (roleta_salon_otworz_po_sloncu.php), wiec jej logika jest odtworzona tutaj
// 1:1 z tymi samymi stalymi; akcja 34 nie ma warunkow. To jest TYLKO PODGLAD — nic nie steruje.
//
// Kazdy wiersz sprawdzenia: ['label', 'wartosc', ok] gdzie ok = true/false/null (null = brak danych / nie dotyczy).

const RA_LAT = HOME_LAT;
const RA_LON = HOME_LON;
const RA_AZ_OPEN  = 246.0;   // jak w roleta_salon_otworz_po_sloncu.php i roleta_sun_detect.php
const RA_ELEV_MIN = 5.0;
const RA_ELEV_SET = 0.0;
const RA_WARM_TOL = 1.0;
const RA_MANUAL_BLOCK = 3600;
const RA_LOCK_SEC = 10800;
const RA_SUN_ON  = 3.0;
const RA_SUN_OFF = 2.0;
// Swiezosc odczytow — 1:1 z demona (STALE_MIN_DEFAULT / STALE_MIN_BY_DEV / STALE_FIELDS)
const RA_STALE_DEFAULT = 120;
const RA_STALE_BY_DEV  = ['10' => 240];
const RA_STALE_FIELDS  = ['temperature', 'humidity', 'co2', 'pressure', 'illuminance', 'soil_moisture',
    'outdoor_air_temperature', 'extract_air_temperature', 'supply_air_temperature',
    'extract_air_humidity', 'running_mean_outdoor_temperature'];

$now = time();

// --- helpery (po id i po nazwie) ---
$H = []; $HN = [];
$r = $db->query("SELECT id, name, label, value FROM helpers");
while ($r && $row = $r->fetch_assoc()) { $H[(string)$row['id']] = $row; $HN[$row['name']] = $row; }
function ra_h($name) { global $HN; return $HN[$name]['value'] ?? null; }

// --- urzadzenia: last_state + nazwa ---
$D = [];
$r = $db->query("SELECT id, device, last_state FROM devices WHERE id IN (10,21,684,1043)");
while ($r && $row = $r->fetch_assoc()) {
    $D[(string)$row['id']] = ['name' => $row['device'], 'ls' => json_decode($row['last_state'] ?? '{}', true) ?: []];
}
function ra_dev_val($id, $field) { global $D; return $D[(string)$id]['ls'][$field] ?? null; }
function ra_dev_name($id) { global $D; return $D[(string)$id]['name'] ?? ('dev' . $id); }
function ra_dev_age_min($id, $field) {
    global $D, $now;
    $ts = $D[(string)$id]['ls']['__ts'][$field] ?? null;
    return $ts ? (int)round(($now - (float)$ts) / 60) : null;
}
function ra_is_stale($id, $field) {
    if (!in_array($field, RA_STALE_FIELDS, true)) return false;
    $age = ra_dev_age_min($id, $field);
    if ($age === null) return false;
    return $age > (RA_STALE_BY_DEV[(string)$id] ?? RA_STALE_DEFAULT);
}
function ra_fmt($v, $dec = 1) { return is_numeric($v) ? number_format((float)$v, $dec, ',', '') : '—'; }
function ra_hm($ts) { return $ts ? date('H:i', (int)$ts) : '—'; }

// --- slonce: wschod/zachod (date_sun_info, ~1 min od Spencera w demonie) + az/elev (NOAA jak w skryptach) ---
$sun = date_sun_info($now, RA_LAT, RA_LON);
$sunrise = (int)$sun['sunrise']; $sunset = (int)$sun['sunset'];
function ra_sun_az_elev(): array {
    $t = new DateTime('now', new DateTimeZone('UTC'));
    $doy = (int)$t->format('z') + 1;
    $uh  = (float)$t->format('H') + (float)$t->format('i') / 60.0 + (float)$t->format('s') / 3600.0;
    $g   = 2 * M_PI / 365.0 * ($doy - 1 + ($uh - 12) / 24.0);
    $decl = 0.006918 - 0.399912 * cos($g) + 0.070257 * sin($g) - 0.006758 * cos(2*$g) + 0.000907 * sin(2*$g)
          - 0.002697 * cos(3*$g) + 0.001480 * sin(3*$g);
    $eot  = 229.18 * (0.000075 + 0.001868 * cos($g) - 0.032077 * sin($g) - 0.014615 * cos(2*$g) - 0.040849 * sin(2*$g));
    $ha   = deg2rad(15.0 * ($uh + RA_LON / 15.0 + $eot / 60.0 - 12.0));
    $lat  = deg2rad(RA_LAT);
    $sinE = sin($lat) * sin($decl) + cos($lat) * cos($decl) * cos($ha);
    $elev = asin($sinE);
    $cosA = max(-1.0, min(1.0, (sin($decl) - $sinE * sin($lat)) / (cos($elev) * cos($lat))));
    $az   = rad2deg(acos($cosA));
    if ($ha > 0) $az = 360.0 - $az;
    return [$az, rad2deg($elev)];
}
[$az, $elev] = ra_sun_az_elev();

// --- stan rolety / blokady / flag ---
$pos   = ra_dev_val(21, 'position');
$pos   = is_numeric($pos) ? (int)$pos : null;
$motor = strtolower((string)ra_dev_val(21, 'motor_run_status'));
$dir   = $motor === 'forward' ? 'w górę' : ($motor === 'reverse' ? 'w dół' : 'stoi');
$lockFlag  = (string)ra_h('roleta_manual_lock');
$lockUntil = (int)ra_h('roleta_manual_until');
$lockActive = ($lockFlag === '1');
$klik = $lockUntil > 0 ? $lockUntil - RA_LOCK_SEC : 0;
$komfort = ra_h('komfort_target');
$tryb    = ra_h('tryb_klimatu');
$flSlonce = (string)ra_h('roleta_slonce');
$flGeo    = (string)ra_h('roleta_po_geometrii');
$salon  = ra_dev_val(684, 'temperature');
$slonce = ra_dev_val(1043, 'temperature');
$cien   = ra_dev_val(10, 'temperature');

// --- akcje z bazy ---
$A = [];
$r = $db->query("SELECT id, name, enabled, trigger_config, conditions, min_interval, last_triggered FROM actions WHERE id IN (33,34,95,110)");
while ($r && $row = $r->fetch_assoc()) $A[(string)$row['id']] = $row;
function ra_last($id) {
    global $A;
    $lt = $A[(string)$id]['last_triggered'] ?? null;
    if (!$lt) return 'nigdy';
    $ts = strtotime($lt);
    return (date('Y-m-d', $ts) === date('Y-m-d')) ? ('dziś ' . date('H:i', $ts)) : date('d.m H:i', $ts);
}

// --- generyczna ocena warunkow (jak demon) ---
function ra_cmp($a, $op, $e) {
    if (is_numeric($a) && is_numeric($e)) {
        $a = (float)$a; $e = (float)$e;
        switch ($op) { case '=': return $a == $e; case '!=': return $a != $e; case '>': return $a > $e;
                       case '<': return $a < $e; case '>=': return $a >= $e; case '<=': return $a <= $e; }
        return false;
    }
    $a = (string)$a; $e = (string)$e;
    return $op === '!=' ? $a !== $e : $a === $e;
}
function ra_eval($cond, &$rows) {
    global $H, $now, $sunrise, $sunset;
    if (isset($cond['items'])) {
        $logic = strtoupper($cond['logic'] ?? 'AND');
        $res = [];
        $sub = [];
        foreach ($cond['items'] as $c) $res[] = ra_eval($c, $sub);
        $ok = $logic === 'OR' ? in_array(true, $res, true) : !in_array(false, $res, true);
        if (isset($cond['logic']) && $logic === 'OR' && count($cond['items']) > 1) {
            // grupa OR: wiersz naglowkowy + podwiersze wciete
            $rows[] = ['JEDNO z poniższych:', '', $ok, 'group'];
            foreach ($sub as $s) { $s[3] = 'sub'; $rows[] = $s; }
        } else {
            foreach ($sub as $s) $rows[] = $s;
        }
        return $ok;
    }
    $type = $cond['type'] ?? '';
    if ($type === 'helper') {
        $hid = (string)($cond['helper_id'] ?? '');
        $h = $H[$hid] ?? null;
        $act = $h['value'] ?? null;
        $ok = $act === null ? false : ra_cmp($act, $cond['op'] ?? '=', $cond['value'] ?? '');
        $hl = $h ? ($h['label'] ?: $h['name']) : ('helper ' . $hid);
        $rows[] = [$hl . ' ' . ($cond['op'] ?? '=') . ' ' . ($cond['value'] ?? ''),
                   $act === null ? '—' : $act, $ok];
        return $ok;
    }
    if ($type === 'value') {
        $dev = (string)($cond['dev_id'] ?? ''); $field = $cond['field'] ?? '';
        $act = ra_dev_val($dev, $field);
        // prog: stala albo helper + offset
        if (($cond['value_src'] ?? '') === 'helper') {
            $h = $H[(string)($cond['helper_id'] ?? '')] ?? null;
            $base = is_numeric($h['value'] ?? null) ? (float)$h['value'] : 0.0;
            $off = (float)($cond['offset'] ?? 0);
            $exp = $base + $off;
            $expLbl = ($h['name'] ?? 'helper') . ($off ? sprintf(' %+g', $off) : '') . ' (' . ra_fmt($exp) . ')';
        } else { $exp = $cond['value'] ?? ''; $expLbl = (string)$exp; }
        $stale = ra_is_stale($dev, $field);
        $ok = ($act === null || $stale) ? false : ra_cmp($act, $cond['op'] ?? '=', $exp);
        $val = $act === null ? '—' : (is_numeric($act) ? ra_fmt($act, $field === 'position' ? 0 : 1) : (string)$act);
        if ($stale) $val .= ' (nieświeży ' . ra_dev_age_min($dev, $field) . ' min)';
        $rows[] = [ra_dev_name($dev) . ' ' . $field . ' ' . ($cond['op'] ?? '=') . ' ' . $expLbl, $val, $ok];
        return $ok;
    }
    if ($type === 'sun') {
        $mode = $cond['sun_mode'] ?? 'after_sunset';
        $off = (int)($cond['sun_offset'] ?? 0) * 60;
        $sr = $sunrise + $off; $ss = $sunset + $off;
        if ($mode === 'after_sunset')       { $ok = $now >= $ss || $now < $sr; $lbl = 'po zachodzie (' . ra_hm($ss) . ')'; }
        elseif ($mode === 'after_sunrise')  { $ok = $now >= $sr && $now < $ss; $lbl = 'po wschodzie (' . ra_hm($sr) . '), przed zachodem (' . ra_hm($ss) . ')'; }
        elseif ($mode === 'before_sunset')  { $ok = $now < $ss; $lbl = 'przed zachodem (' . ra_hm($ss) . ')'; }
        else                                { $ok = $now < $sr; $lbl = 'przed wschodem (' . ra_hm($sr) . ')'; }
        $rows[] = [$lbl, date('H:i', $now), $ok];
        return $ok;
    }
    $rows[] = ['warunek ' . $type . ' (nieznany)', '', null];
    return false;
}
function ra_cron_lbl($tc) {
    $arr = json_decode($tc, true) ?: [];
    $out = [];
    foreach ($arr as $t) {
        if (($t['type'] ?? '') === 'cron') $out[] = $t['cron'];
        elseif (($t['type'] ?? '') === 'sun') $out[] = ($t['sun_type'] ?? 'sunset') . ($t['sun_offset'] ? sprintf(' %+d min', $t['sun_offset']) : '');
    }
    return implode(' | ', $out);
}

$blocks = [];

// ====== 1. RANO — akcja 33 ======
$a = $A['33'] ?? null;
$rows = []; $ok = false;
if ($a) {
    $c = json_decode($a['conditions'] ?? '', true);
    $ok = $c ? ra_eval($c, $rows) : true;
}
$hm = (int)date('G') * 60 + (int)date('i');
// Okno 6:30-8:10 (od 2026-09-09; wczesniej do 9:55 — nachodzilo na 110 i po zamknieciu na slonce
// o 9:52 akcja 33 o 9:55 otwierala rolete z powrotem). Najpozniejszy wschod ~8:05.
$inWin33 = $hm >= 6 * 60 + 30 && $hm <= 8 * 60 + 10;
$blocks[] = [
    'id' => 33, 'title' => 'Rano: otwórz', 'when' => 'cron 6:30–8:10 co 5 min',
    'desc' => 'Pierwsze otwarcie dnia. Nie wcześniej niż 6:30 (latem słońce wschodzi o 4), zimą czeka na wschód. '
            . 'Nie rusza, gdy roleta już otwarta (position > 10) albo trwa blokada ręczna.',
    'rows' => $rows, 'ok' => $ok && $inWin33, 'enabled' => (int)($a['enabled'] ?? 0), 'last' => ra_last(33),
    'verdict' => !$a ? 'brak akcji w bazie' : (!$a['enabled'] ? 'akcja WYŁĄCZONA'
              : (!$inWin33 ? 'poza oknem 6:30–8:10 — nie otwiera' . ($ok ? ' (choć warunki spełnione)' : '')
              : ($ok ? 'warunki spełnione — otworzy przy najbliższym ticku (co 5 min)' : 'nie otworzy (warunek na czerwono)'))),
];

// ====== 2. DZIEN — akcja 110 ======
$a = $A['110'] ?? null;
$rows = []; $ok = false;
if ($a) {
    $c = json_decode($a['conditions'] ?? '', true);
    $ok = $c ? ra_eval($c, $rows) : true;
}
$hour = (int)date('G');
$hm = $hour * 60 + (int)date('i');
$inWin = $hm >= 8 * 60 + 15 && $hour <= 16;      // od 8:15 (2026-09-09), zeby nie bic sie z 33
$blocks[] = [
    'id' => 110, 'title' => 'Dzień: zamknij na słońce', 'when' => 'cron 8:15–16:59 co minutę, min. odstęp ' . (int)($a['min_interval'] ?? 0) . ' s',
    'desc' => 'Zasłania, gdy jest gorąco względem komfortu (helper komfort_target, ustawiany sezonowo przez reku) '
            . 'i słońce realnie grzeje (flaga roleta_slonce) LUB salon jest już 2° ponad komfort. '
            . 'Po zejściu słońca z elewacji (flaga geometrii = 1) już NIE zamyka. Respektuje blokadę ręczną.',
    'rows' => $rows, 'ok' => $ok && $inWin, 'enabled' => (int)($a['enabled'] ?? 0), 'last' => ra_last(110),
    'verdict' => !$a ? 'brak akcji w bazie' : (!$a['enabled'] ? 'akcja WYŁĄCZONA'
              : (!$inWin ? 'poza oknem 8:15–17 — nie zamyka' . ($ok ? ' (choć warunki spełnione)' : '')
              : ($ok ? 'warunki spełnione — zamyka w ciągu minuty' : 'nie zamknie (warunek na czerwono)'))),
];

// ====== 3. POPOLUDNIE — akcja 95 (skrypt geometrii) ======
$a = $A['95'] ?? null;
$rows = [];
$poPoludniu = $az > 180;
$katOk = ($az >= RA_AZ_OPEN) || ($elev <= RA_ELEV_MIN);
$rows[] = ['po południu (azymut > 180°)', ra_fmt($az, 0) . '°', $poPoludniu];
$rows[] = ['słońce za elewacją: azymut ≥ ' . ra_fmt(RA_AZ_OPEN, 0) . '° LUB wysokość ≤ ' . ra_fmt(RA_ELEV_MIN, 0) . '°',
           'az ' . ra_fmt($az, 0) . '°, elev ' . ra_fmt($elev, 1) . '°', $katOk];
$przedZach = $elev > RA_ELEV_SET;
$rows[] = ['przed zachodem (wysokość > 0°)', ra_fmt($elev, 1) . '°', $przedZach];
$odKliku = $klik > 0 ? $now - $klik : null;
$manualOk = !($odKliku !== null && $odKliku >= 0 && $odKliku < RA_MANUAL_BLOCK);
$rows[] = ['min. 60 min od ręcznego ruchu', $odKliku === null ? 'brak' : (int)round($odKliku / 60) . ' min temu', $manualOk];
$cieploOk = true; $cieploVal = '—';
if (is_numeric($salon) && is_numeric($cien)) {
    $chlodno = is_numeric($komfort) && (float)$salon <= (float)$komfort;
    $nieCieplej = (float)$cien <= (float)$salon + RA_WARM_TOL;
    $cieploOk = $chlodno || $nieCieplej;
    $cieploVal = 'salon ' . ra_fmt($salon) . ', cień ' . ra_fmt($cien) . ', komfort ' . ra_fmt($komfort);
}
$rows[] = ['salon ≤ komfort LUB cień ≤ salon + ' . ra_fmt(RA_WARM_TOL, 0) . '° (otwarcie nie dogrzeje)', $cieploVal, $cieploOk];
$otwDzis = (string)ra_h('roleta_otwarta_data') === date('Y-m-d');
$rows[] = ['jeszcze nie otwierała dziś po geometrii', $otwDzis ? 'już otwarta dziś' : 'nie', !$otwDzis];
$rows[] = ['roleta zasłonięta (position ≤ 10)', $pos === null ? '—' : $pos, $pos !== null && $pos <= 10];
$ok95 = $poPoludniu && $katOk && $przedZach && $manualOk && $cieploOk && !$otwDzis && ($pos !== null && $pos <= 10);
$inWin95 = $hour >= 11 && $hour <= 21;
$blocks[] = [
    'id' => 95, 'title' => 'Popołudnie: otwórz po geometrii', 'when' => 'cron 11:00–21:58 co 2 min',
    'desc' => 'Gdy słońce zejdzie z południowej elewacji (azymut ≥ 246° albo nisko ≤ 5°), roleta wraca do góry — '
            . 'max raz dziennie, nigdy po zachodzie, nie w pierwszej godzinie po ręcznym ruchu i nie wtedy, '
            . 'gdy na dworze jest wyraźnie cieplej niż w salonie. Odporna na blokadę ręczną po 1. godzinie.',
    'rows' => $rows, 'ok' => $ok95 && $inWin95, 'enabled' => (int)($a['enabled'] ?? 0), 'last' => ra_last(95),
    'verdict' => !$a ? 'brak akcji w bazie' : (!$a['enabled'] ? 'akcja WYŁĄCZONA'
              : ($otwDzis && $pos !== null && $pos > 10 ? 'już otwarta po geometrii (' . ra_h('roleta_otwarta_data') . ')'
              : (!$inWin95 ? 'poza oknem 11–22 — nie otwiera'
              : ($ok95 ? 'warunki spełnione — otworzy w ciągu 2 min' : 'nie otworzy (warunek na czerwono)')))),
];

// ====== 4. ZACHOD — akcja 34 ======
$a = $A['34'] ?? null;
$tc = json_decode($a['trigger_config'] ?? '[]', true) ?: [];
$off34 = (int)($tc[0]['sun_offset'] ?? 15);
$t34 = $sunset + $off34 * 60;
$rows = [];
$rows[] = ['zachód dziś', ra_hm($sunset), null];
$rows[] = ['zamknięcie o zachód ' . sprintf('%+d', $off34) . ' min', ra_hm($t34), $now >= $t34];
$blocks[] = [
    'id' => 34, 'title' => 'Zachód: zamknij', 'when' => 'sunset ' . sprintf('%+d', $off34) . ' min (jednorazowo)',
    'desc' => 'Zasłania na noc kwadrans po zachodzie. Bez warunków — ODPORNA na blokadę ręczną (od 7.09.2026), '
            . 'zamyka nawet jeśli ktoś klikał roletę po południu.',
    'rows' => $rows, 'ok' => $now >= $t34, 'enabled' => (int)($a['enabled'] ?? 0), 'last' => ra_last(34),
    'verdict' => !$a ? 'brak akcji w bazie' : (!$a['enabled'] ? 'akcja WYŁĄCZONA'
              : ($now >= $t34 ? 'czas minął — powinna być zamknięta (ostatnio: ' . ra_last(34) . ')' : 'zamknie o ' . ra_hm($t34))),
];

// ====== 5. BLOKADA RECZNA ======
$rows = [];
$rows[] = ['blokada aktywna (helper roleta_manual_lock)', $lockActive ? 'TAK do ' . ra_hm($lockUntil) : 'nie', $lockActive ? false : true];
if ($klik > 0) $rows[] = ['ostatni ręczny ruch', date('d.m H:i', $klik), null];
$blocks[] = [
    'id' => 0, 'title' => 'Blokada ręczna 3 h', 'when' => 'klik w widget lub ścienny przycisk',
    'desc' => 'Każdy ręczny ruch (GUI albo przycisk na ścianie) blokuje automat na 3 h: rano (33) i dzień (110) '
            . 'stoją, geometria (95) czeka tylko pierwszą godzinę, zachód (34) ignoruje blokadę. '
            . 'Ruchy systemowe blokady nie zakładają.',
    'rows' => $rows, 'ok' => !$lockActive, 'enabled' => 1, 'last' => '',
    'verdict' => $lockActive ? 'automat 33/110 ZABLOKOWANY do ' . ra_hm($lockUntil) : 'brak blokady — automat działa',
];

// ====== 6. FLAGI: slonce + geometria (roleta_sun_detect.php co minute) ======
$rows = [];
$diff = (is_numeric($slonce) && is_numeric($cien)) ? (float)$slonce - (float)$cien : null;
$dzien = $now >= $sunrise + 1800 && $now <= $sunset - 1800;
$rows[] = ['dzień (wschód+30 min … zachód−30 min)', ra_hm($sunrise + 1800) . '–' . ra_hm($sunset - 1800), $dzien];
$rows[] = ['słońce (dev1043) − cień (dev10)', $diff === null ? '—' : ra_fmt($slonce) . ' − ' . ra_fmt($cien) . ' = ' . ra_fmt($diff),
           $diff === null ? null : $diff >= RA_SUN_ON];
$rows[] = ['flaga roleta_slonce (ON ≥ ' . ra_fmt(RA_SUN_ON) . ', OFF ≤ ' . ra_fmt(RA_SUN_OFF) . ', między = trzymaj)',
           $flSlonce === '1' ? '1 = słońce' : '0 = cień', $flSlonce === '1'];
$rows[] = ['flaga roleta_po_geometrii (az > 180 i (az ≥ 246 lub elev ≤ 5))', $flGeo === '1' ? '1 = słońce za elewacją' : '0 = rano/dzień', null];
$blocks[] = [
    'id' => 104, 'title' => 'Flagi słońca i geometrii', 'when' => 'cron co minutę',
    'desc' => 'Słońce wykrywane z różnicy dwu czujników: goły w słońcu (Taras) minus w rurce na drzewie (Patio). '
            . 'Histereza, żeby chmury nie migały flagą. Poza dniem flaga twardo 0. Odczyty muszą być świeże '
            . '(słońce 120 min, cień 240 min).',
    'rows' => $rows, 'ok' => null, 'enabled' => 1, 'last' => '',
    'verdict' => $diff === null ? 'brak świeżych odczytów' : ($flSlonce === '1' ? 'słońce grzeje' :
                 ($diff >= RA_SUN_OFF ? 'strefa histerezy (' . ra_fmt($diff) . ') — flaga trzyma 0, słońce przy ≥ ' . ra_fmt(RA_SUN_ON) : 'brak słońca')),
];

echo json_encode([
    'ok' => true,
    'now' => date('H:i'),
    'pos' => $pos, 'dir' => $dir,
    'lock' => $lockActive ? ra_hm($lockUntil) : null,
    'sun' => ['sunrise' => ra_hm($sunrise), 'sunset' => ra_hm($sunset), 'az' => round($az), 'elev' => round($elev, 1)],
    'vals' => ['salon' => ra_fmt($salon), 'slonce' => ra_fmt($slonce), 'cien' => ra_fmt($cien),
               'komfort' => ra_fmt($komfort), 'tryb' => $tryb, 'flaga_slonce' => $flSlonce, 'flaga_geo' => $flGeo],
    'blocks' => $blocks,
], JSON_UNESCAPED_UNICODE);
