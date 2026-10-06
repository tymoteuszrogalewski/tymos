#!/usr/bin/env php
<?php
/**
 * ai_rekuperator.php — decyzja o bypassie + biegu wentylatora rekuperatora (lato).
 *
 * KOTWICA = KOMFORT (helper `komfort_target`, C) ustawiany sezonowo ze sredniej biegnacej
 * temp zewn. reku (running_mean_outdoor_temperature): <=14 -> 21.5 (zima), >=16 -> 23.0 (lato),
 * pomiedzy -> trzymaj (histereza). Zapisywany do helpera = JEDNO ZRODLO PRAWDY (czyta go tez
 * np. widget/inne akcje). Wartosci 21.5/23 = pokretla.
 *
 * Tryby (priorytet: CHLODZENIE > ODWILZANIE > AWAY > AUTO):
 *   CHLODZENIE: T_out < salon-1 AND salon > floor AND nagrz OFF -> bypass OTWARTY + wentylator.
 *     Chlodzi DO floor i stop. floor = C (normalnie) lub C-1 (nocny PRE-COOL). Bieg wg (salon-T_out);
 *     pre-cool -> bieg 2 (Medium) na sile; bardzo wilgotno -> bieg 3.
 *   ODWILZANIE: bypass auto. Mokro punktowo (lazienka>=75 lub wywiew reku>=80) -> bieg 3, ale
 *     w CISZY NOCNEJ 23-05 max bieg 2. Sam wilgotny dom (salon>=65 gdy T_out<salon) -> bieg 2.
 *   AWAY: na dworze cieplej niz komfort (T_out > C, hist 0.5) -> bypass ZAMKNIETY (odzysk chroni
 *     dom) + Fan Speed=Away. Nie wciagamy cieplego powietrza w upal.
 *   AUTO: reszta -> bypass auto + Auto Ventilation (swieze powietrze; jednostka sama decyduje).
 *
 * COIL NAWIEWU (podloga biegu): gdy zawor chlodnicy nawiewu dev1052 jest OPEN (woda ze studni plynie
 *   przez coil postreku), chlod przychodzi Z COILA, nie z podworka -> podnosimy bieg w KAZDYM trybie,
 *   tez w AWAY przy upale. Sens: bez przeplywu chlod nie dociera do pokoi (poddasze).
 *   Podloga zalezy od godziny: DZIEN 05-23 -> bieg 2 (Medium), NOC 23-05 -> bieg 1 (Low, ciszej).
 *   Bypass BEZ ZMIAN — w AWAY zostaje zamkniety, wiec coil chlodzi odzyskane powietrze zamiast
 *   goraca z dworu. Bieg 3 dalej tylko z wilgoci. Recznie chlodnica nawiew OFF -> zawor CLOSE ->
 *   podloga znika i wracaja poprzednie tryby (w tym AWAY).
 *
 * PRE-COOL (nocny bufor masy przed upalnym dniem): w nocy (0-6) gdy running_mean >= 20 (trwajacy
 *   upal = jutro tez goraco) chlodzimy do C-1 zamiast C -> banujemy ~1°C chlodu w masie pasywniaka.
 *   Bez prognozy/modulu: srednia biegnaca reku to wystarczajacy lokalny sygnal fali upalow.
 *
 * T_out = MIN ze swiezych czujnikow zewn: dev527 outdoor (wlasny czujnik reku = czerpnia wschod)
 *   + Patio dev10 (cien).
 *
 * ESP cofa bieg/away po kilku min -> wentylator ponawiamy co uruchomienie. Cron: co 3 min.
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

function val($q) { global $db; $r = $db->query($q); return ($r && $row = $r->fetch_row()) ? $row[0] : null; }
function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }

// Wilgotnosc BEZWZGLEDNA w g/m3 (wzor Magnusa). Wilgotnosc WZGLEDNA nie mowi, ile wody jest
// w powietrzu — zalezy od temperatury. Bez tego przeliczenia reku „osuszalo" w deszcz, wymieniajac
// powietrze domowe na rownie mokre (pomiar usera 2026-08-02 02:05: dwor 15,7C/96% = 12,9 g/m3,
// dom 22,4C/67% = 13,3 g/m3 — roznica sladowa, a szedl bieg 2-3).
const AH_MARGIN_ON  = 1.0;   // g/m3 — o tyle suchszy musi byc dwor, zeby ZACZAC odwilzanie
const AH_MARGIN_OFF = 0.5;   // g/m3 — ponizej tej przewagi przestajemy (histereza)

function absHum($t, $rh) {
    if (!is_numeric($t) || !is_numeric($rh)) return null;
    $t = (float)$t; $rh = (float)$rh;
    $e = 6.112 * exp(17.67 * $t / ($t + 243.5)) * $rh / 100.0;   // cisnienie pary [hPa]
    return 216.7 * $e / (273.15 + $t);   // g/m3 (216.7 dla cisnienia w hPa; 2.1674 byloby dla Pa)
}
function setHelper($name, $v) {
    global $db;
    $db->query("INSERT INTO helpers (name, label, type, value) VALUES ('{$name}', 'Reku - odwilzanie aktywne', 'text', '{$v}') "
             . "ON DUPLICATE KEY UPDATE value='{$v}'");
}

// Sterowanie wentylatorem reku przez ESPHome REST API. Ponawiane co uruchomienie (ESP cofa).
//   low/medium/high = Manual Permanent <bieg>   away = switch Away Mode   auto = Auto Ventilation ON
//
// AWAY przez SWITCH, nie przez select „Fan Speed" (zmiana 2026-08-01): select wola w komponencie
// set_ventilation_level(), a to leci z domyslnym duration_secs=1, czyli wariantem
// „Manual (limited)" — wbudowany timer jednostki zdejmuje go po kilkunastu minutach.
// `switch/Away Mode` wola set_away() z 0xffffffff, czyli bezterminowo, tak jak nasze przyciski
// Manual Permanent. Ten sam blad co w [[yaml fan on_turn_on]] — mieszanie komend limited/permanent.
const ESP_IP = REKU_IP;

// Jedna ponowna proba przed zgloszeniem bledu: ESP siedzi na zatloczonym 2.4 GHz i pojedyncze
// zapytanie potrafi przepasc, a nastepny tik crona jest dopiero za 3 min. Retry po 2 s zalatwia
// wiekszosc takich wpadek i nie smieci wtedy w logu.
function esp_post($path, $what) {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'content' => '', 'timeout' => 5, 'header' => "Content-Length: 0\r\n"]]);
    $url = 'http://' . ESP_IP . $path;
    if (@file_get_contents($url, false, $ctx) !== false) return;
    sleep(2);
    if (@file_get_contents($url, false, $ctx) !== false) return;
    tymos_log('WARN', "ai_rekuperator: ESP " . ESP_IP . " {$what} nie odpowiada (2 proby)");
}

// $curFan = ostatni znany Fan Speed z bazy. Wychodzac z AWAY trzeba najpierw zdjac Away Mode,
// inaczej komenda biegu trafia w jednostke, ktora dalej siedzi w away.
function reku_fan($mode, $curFan = null) {
    if ($mode === 'away') {
        esp_post('/switch/' . rawurlencode('Away Mode') . '/turn_on', 'away on');
        return;
    }
    if ($curFan === 'Away') {
        esp_post('/switch/' . rawurlencode('Away Mode') . '/turn_off', 'away off');
        sleep(1);
    }
    if     ($mode === 'low')    esp_post('/button/' . rawurlencode('Manual Permanent Low')    . '/press', 'fan low');
    elseif ($mode === 'medium') esp_post('/button/' . rawurlencode('Manual Permanent Medium') . '/press', 'fan medium');
    elseif ($mode === 'high')   esp_post('/button/' . rawurlencode('Manual Permanent High')   . '/press', 'fan high');
    else                        esp_post('/switch/' . rawurlencode('Auto Ventilation')        . '/turn_on', 'auto vent');
}

// --- czujniki zewnetrzne: MIN ze swiezych (dev527 outdoor + Patio dev10). ---
$row_reku  = $db->query("SELECT outdoor_air_temperature AS t, UNIX_TIMESTAMP(ts) AS ts FROM device527 WHERE outdoor_air_temperature IS NOT NULL ORDER BY ts DESC LIMIT 1")->fetch_assoc();
$row_taras = $db->query("SELECT temperature AS t, UNIX_TIMESTAMP(ts) AS ts FROM device10 WHERE temperature IS NOT NULL ORDER BY ts DESC LIMIT 1")->fetch_assoc();

$now = time();
$cands = [];
if ($row_reku  && ($now - (int)$row_reku['ts'])  < 3600) $cands['reku']  = (float)$row_reku['t'];
// taras: bateryjny na slabej galezi mesha, raportuje rzadziej -> dluzszy prog 2h.
if ($row_taras && ($now - (int)$row_taras['ts']) < 7200) $cands['taras'] = (float)$row_taras['t'];

if (!$cands) { tymos_log('WARN', 'ai_rekuperator: brak swiezego czujnika zewn — pomijam'); exit(0); }
asort($cands);
$src       = array_key_first($cands);
$T_outside = reset($cands);

// --- pozostale odczyty (kazdy filtruje IS NOT NULL — ESP pcha rozne kolumny w roznych pushach) ---
$T_extract = val("SELECT extract_air_temperature FROM device527 WHERE extract_air_temperature IS NOT NULL ORDER BY ts DESC LIMIT 1");
// dev851 = temp ZA coil postreku (realny nawiew do domu; uwzglednia chlodnice/nagrzewnice postreku).
// Uzywany do doboru biegu w chlodzeniu: im chlodniejszy nawiew od wywiewu, tym wyzszy bieg.
$T_post = val("SELECT temperature FROM device851 ORDER BY ts DESC LIMIT 1");
$rmOut     = val("SELECT running_mean_outdoor_temperature FROM device527 WHERE running_mean_outdoor_temperature IS NOT NULL ORDER BY ts DESC LIMIT 1");
$salon_t   = val("SELECT JSON_UNQUOTE(JSON_EXTRACT(last_state, '\$.temperature')) FROM devices WHERE id=684 LIMIT 1");
$salon_h   = val("SELECT JSON_UNQUOTE(JSON_EXTRACT(last_state, '\$.humidity')) FROM devices WHERE id=684 LIMIT 1");
$bath_h    = val("SELECT humidity FROM device754 WHERE humidity IS NOT NULL ORDER BY ts DESC LIMIT 1");
// wilgotnosc wywiewu z reku = srednia z calego domu (to, co jednostka realnie wyciaga)
$extr_h    = val("SELECT extract_air_humidity FROM device527 WHERE extract_air_humidity IS NOT NULL ORDER BY ts DESC LIMIT 1");
// wilgotnosc dworu — do bramki na wilgotnosc BEZWZGLEDNA (patrz nizej). Bierzemy pare
// temperatura+wilgotnosc z TEGO SAMEGO czujnika (dev527 outdoor), zeby liczyc spojnie;
// $T_outside jest MINIMUM z dwoch zrodel, wiec nie pasuje do tej wilgotnosci.
$out_h     = val("SELECT outdoor_air_humidity FROM device527 WHERE outdoor_air_humidity IS NOT NULL ORDER BY ts DESC LIMIT 1");
$out_t     = ($row_reku && is_numeric($row_reku['t'] ?? null)) ? (float)$row_reku['t'] : null;
$bypass    = val("SELECT bypass_state FROM device527 WHERE bypass_state IS NOT NULL ORDER BY ts DESC LIMIT 1");
$av        = val("SELECT auto_ventilation FROM device527 WHERE auto_ventilation IS NOT NULL ORDER BY ts DESC LIMIT 1");
$fanSpeed  = val("SELECT fan_speed FROM device527 WHERE fan_speed IS NOT NULL ORDER BY ts DESC LIMIT 1");
$nagrz     = val("SELECT JSON_UNQUOTE(JSON_EXTRACT(last_state, '\$.state')) FROM devices WHERE id=16 LIMIT 1");
// dev1052 = zawor chlodnicy NAWIEWU (postreku), sterowany przez ai_wymienniki.php co minute.
// OPEN = woda ze studni plynie przez coil -> chlod jest w nawiewie, oplaca sie dmuchac (min bieg 2).
$nvValve   = val("SELECT JSON_UNQUOTE(JSON_EXTRACT(last_state, '\$.state')) FROM devices WHERE id=1052 LIMIT 1");

if (!is_numeric($bypass)) { tymos_log('WARN', 'ai_rekuperator: brak bypass_state — pomijam'); exit(0); }
$bypass  = (float)$bypass;
$nagrzON = ($nagrz === 'ON');
$isAway  = ($fanSpeed === 'Away');
// referencja wnetrza: salon (realny komfort); fallback wyciag reku gdy salon niedostepny
$T_in = is_numeric($salon_t) ? (float)$salon_t : (is_numeric($T_extract) ? (float)$T_extract : null);
if ($T_in === null) { tymos_log('WARN', 'ai_rekuperator: brak temp wnetrza (salon/extract) — pomijam'); exit(0); }

// --- KOMFORT (C): sezonowo ze sredniej biegnacej reku; zapis do helpera = jedno zrodlo prawdy ---
$C_cur = val("SELECT value FROM helpers WHERE name='komfort_target' LIMIT 1");
$C_cur = is_numeric($C_cur) ? (float)$C_cur : null;
if (is_numeric($rmOut)) {
    $rm = (float)$rmOut;
    if     ($rm <= 14.0) $C = 21.5;              // zima
    elseif ($rm >= 16.0) $C = 23.0;              // lato
    else                 $C = $C_cur ?? 23.0;    // przejsciowka -> trzymaj (histereza)
} else {
    $C = $C_cur ?? 23.0;
}
if ($C_cur === null || abs($C - $C_cur) > 0.01) {
    $db->query("UPDATE helpers SET value='" . sprintf('%.1f', $C) . "' WHERE name='komfort_target'");
}

// --- PRE-COOL nocny: w upalny okres (running_mean>=20) w nocy (0-6) chlodzimy do C-1 (~1°C bufor) ---
$hour    = (int)date('G');
// CISZA NOCNA 23:00-05:00. Uzywane tu w dwoch miejscach:
// podloga biegu przy coilu (medium->low) ORAZ czapka na odwilzanie (bieg 3 -> 2), bo bieg III bywa w nocy slyszalny,
// a w nocy tez ktos moze sie kapac i wzrosnie wilgotnosc.
$night   = ($hour >= 23 || $hour < 5);
$precool = (!$nagrzON) && is_numeric($rmOut) && ((float)$rmOut >= 20.0) && ($hour >= 0 && $hour < 6);
$floor   = $precool ? ($C - 1.0) : $C;

// === Decyzja: tryb (priorytet CHLODZENIE > ODWILZANIE > AWAY > AUTO) ===
// MASTER tryb klimatu (helper tryb_klimatu): reku chlodzi/wietrzy/away TYLKO w 'chlodz'.
// 'grzej'/'off' -> reku na AUTO (nagrzewnica salonu osobno, bramkowana helperem w akcjach 26/27).
$tryb     = val("SELECT value FROM helpers WHERE name='tryb_klimatu' LIMIT 1");
$coolMode = ($tryb === 'chlodz');
// Chlodnica nawiewu pracuje = podloga biegu 2 (patrz naglowek). Zawor zamkniety (tez przez reczny
// override 'Chlodnica nawiew' = OFF w widgecie) -> podloga znika, wracaja normalne tryby.
$coilCool = $coolMode && ($nvValve === 'OPEN');
// HISTEREZA na granicy komfortu (anti-migotanie CHLODZENIE<->AUTO): gdy juz chlodzimy
// (bypass otwarty) trzymamy chlodzenie do floor; gdy NIE chlodzimy, startujemy dopiero przy
// floor+0.5. Bez tego salon siedzacy na C przeskakiwal tryby przy mikro-wahaniach (fan Auto<->Medium).
$coolThresh = ($bypass > 0) ? $floor : ($floor + 0.5);   // salon: start C+0.5, stop C
// dwor: ruch powietrza oplaca sie nawet gdy dwor CIUT cieplejszy niz salon (+0.5); histereza do +0.8
$outThresh  = ($bypass > 0) ? ($T_in + 0.8) : ($T_in + 0.5);
$cooling = $coolMode && ($T_outside <= $outThresh) && ($T_in > $coolThresh) && !$nagrzON;

// ODWILZANIE — dwa poziomy pilnosci (rozdzielone 2026-08-02):
//   MOKRO PUNKTOWO (lazienka >=75% albo wywiew reku >=80%) -> bieg 3, bo to realny wyrzut pary
//   po prysznicu / gotowaniu i oplaca sie wywiac szybko.
//   WILGOTNY DOM (salon >=65%) -> bieg 2. Bieg 3 na cale mieszkanie to halas bez zysku:
//   w deszcz dwor ma 90%+, wiec wymiana prawie nic nie osusza (user 2026-08-02).
$bath_humid  = is_numeric($bath_h)  && (float)$bath_h  >= 75.0;
$extr_humid  = is_numeric($extr_h)  && (float)$extr_h  >= 80.0;
$wet_spot    = $bath_humid || $extr_humid;
$salon_humid = is_numeric($salon_h) && (float)$salon_h >= 65.0;

// BRAMKA NA WILGOTNOSC BEZWZGLEDNA — wymiana osusza dom tylko wtedy, gdy powietrze z dworu niesie
// MNIEJ wody niz domowe. Liczymy g/m3 po obu stronach i zadamy przewagi AH_MARGIN_ON, zeby ZACZAC;
// zeby przerwac, wystarczy spadek ponizej AH_MARGIN_OFF (histereza, inaczej migotaloby przy progu).
// Stan pamieta helper `reku_dehumid`.
// CELOWO dotyczy tylko galezi „wilgotny dom" (salon >= 65%). MOKRY PUNKT (lazienka po prysznicu,
// wywiew >= 80%) idzie DALEJ bez bramki — tam chodzi o miejscowy wyrzut pary i ochrone przed plesnia,
// a nie o bilans wilgoci calego domu.
$ah_out = absHum($out_t, $out_h);
$ah_in  = absHum($T_extract, $extr_h);
$prevDeh = ((string)val("SELECT value FROM helpers WHERE name='reku_dehumid' LIMIT 1") === '1');
$ahOk = true;
if ($ah_out !== null && $ah_in !== null) {
    $need = $prevDeh ? AH_MARGIN_OFF : AH_MARGIN_ON;
    $ahOk = ($ah_out <= $ah_in - $need);
}

$dehumid     = $coolMode && !$cooling && !$nagrzON && ( $wet_spot || ($salon_humid && $T_outside < $T_in && $ahOk) );
setHelper('reku_dehumid', $dehumid ? '1' : '0');
if ($ah_out !== null && $ah_in !== null) {
    logMsg(sprintf("AH: dwor %.1f g/m3 (%.1fC/%s%%) vs dom %.1f g/m3 (%sC/%s%%) -> %s",
        $ah_out, $out_t, $out_h, $ah_in, $T_extract, $extr_h,
        $ahOk ? 'wymiana osusza' : 'wymiana NIC nie da - odwilzanie zablokowane'));
}

// AWAY: na dworze cieplej niz komfort -> minimalizuj (odzysk chroni, nie wciagamy goraca). Hist 0.5.
$away = $coolMode && !$cooling && !$dehumid && !$nagrzON
        && ( $isAway ? ($T_outside > $C - 0.5) : ($T_outside > $C + 0.5) );

$mode = $cooling ? ($precool ? 'PRE-COOL' : 'CHLODZENIE') : ($dehumid ? 'ODWILZANIE' : ($away ? 'AWAY' : 'AUTO'));
logMsg(sprintf("T_out=%.1f(min:%s) salon=%s/%s%% lazienka=%s%% wywiew=%s%% extract=%s rm=%s C=%.1f floor=%.1f bypass=%d%% fan=%s nagrz=%s tryb=%s coil=%s | %s",
    $T_outside, $src, is_numeric($salon_t) ? $salon_t : '?', is_numeric($salon_h) ? $salon_h : '?',
    is_numeric($bath_h) ? $bath_h : '?', is_numeric($extr_h) ? $extr_h : '?', is_numeric($T_extract) ? $T_extract : '?',
    is_numeric($rmOut) ? sprintf('%.1f', $rmOut) : '?', $C, $floor,
    $bypass, $fanSpeed, $nagrzON ? 'ON' : 'OFF', $tryb ?? '?', $coilCool ? 'OPEN' : 'close', $mode));

// === Decyzja AI: wylicz docelowy bieg ($aiFan) i bypass ($aiBypass) — BEZ wykonania ===
if ($cooling) {
    $aiBypass = 'open';
    // Bardzo wilgotno podczas chlodzenia -> bieg 3 (bypass zostaje otwarty: chlodzi + mocno wywiewa).
    // Bardzo wilgotno -> bieg 3 (anti-plesn). Poza tym DOMYSLNIE bieg 1 (Low, cichy).
    // Bieg 2 (Medium) TYLKO WYJATKOWO: salon wyraznie goracy (>=C+2) I duzy potencjal chlodzenia (diff>=4).
    // Powod: II zbyt slyszalny. PRE-COOL wplywa juz TYLKO na cel (floor), NIE wymusza Medium (nocny halas).
    // Bieg 3 tylko przy mokrym punkcie (lazienka/wywiew) — sam wilgotny salon idzie biegiem 2.
    if ($wet_spot) { $aiFan = 'high'; $aiWhy = "CHLODZENIE + mokro punktowo -> bieg 3"; }
    elseif (is_numeric($salon_h) && (float)$salon_h >= 70.0) { $aiFan = 'medium'; $aiWhy = "CHLODZENIE + wilgotny salon -> bieg 2"; }
    else {
        // diff = potencjal chlodzenia: wywiew - nawiew_po_coil (dev851); fallback salon-T_out.
        if (is_numeric($T_post) && is_numeric($T_extract)) { $diff = (float)$T_extract - (float)$T_post; $src2 = "wywiew-nawiew851"; }
        else { $diff = $T_in - $T_outside; $src2 = "salon-Tout"; }
        // Histereza: gdy juz Medium trzymaj do salon>=C+1.5 & diff>=3; wejscie w Medium dopiero salon>=C+2 & diff>=4.
        $useMedium = ($fanSpeed === 'Medium') ? ($T_in >= $C + 1.5 && $diff >= 3.0)
                                              : ($T_in >= $C + 2.0 && $diff >= 4.0);
        $aiFan = $useMedium ? 'medium' : 'low';
        $aiWhy = sprintf("CHLODZENIE bieg %d (%s=%.1f salon=%.1f cel %.1f)", $useMedium ? 2 : 1, $src2, $diff, $T_in, $floor);
    }
} elseif ($dehumid) {
    $aiBypass = 'closed';
    // W nocy bieg 3 NIE wchodzi wcale — odwilzanie idzie max biegiem 2, choc dluzej.
    $aiFan    = ($wet_spot && !$night) ? 'high' : 'medium';
    if ($wet_spot) {
        $aiWhy = sprintf("ODWILZANIE mokro punktowo (lazienka=%s%% wywiew=%s%%) -> bieg %d%s",
                         is_numeric($bath_h) ? $bath_h : '?', is_numeric($extr_h) ? $extr_h : '?',
                         $night ? 2 : 3, $night ? ' (CISZA NOCNA 23-05: czapka na biegu 2)' : '');
    } else {
        $aiWhy = sprintf("ODWILZANIE domu (salon=%s%%) -> bieg 2 (max, bieg 3 tylko na mokry punkt w dzien)",
                         is_numeric($salon_h) ? $salon_h : '?');
    }
}
elseif ($away)      { $aiBypass = 'closed'; $aiFan = 'away'; $aiWhy = "AWAY (dwor cieplejszy niz komfort) -> Fan Speed=Away"; }
else                { $aiBypass = 'closed'; $aiFan = 'low';  $aiWhy = "AUTO -> bieg 1 (Low) + bypass zamkniety (manual baseline)"; }

// PODLOGA biegu gdy chlodnica nawiewu pracuje. DZIEN (05-23) -> bieg 2 (Medium), NOC (23-05) ->
// bieg 1 (Low) dla ciszy pod sypialniami. Podnosimy tylko w gore (bieg 3 z wilgoci zostaje).
// Bypass NIE ruszany — w AWAY zamkniety, wiec coil chlodzi odzysk, nie goraco z dworu.
if ($coilCool) {
    $coilFloor = $night ? 'low' : 'medium';
    $rank      = ['away' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];
    if ($rank[$aiFan] < $rank[$coilFloor]) {
        $aiWhy .= sprintf(" | COIL nawiew OPEN (%s): %s -> %s", ($coilFloor === 'low') ? 'noc 23-05' : 'dzien 05-23', $aiFan, $coilFloor);
        $aiFan  = $coilFloor;
    }
}

// === RECZNE OVERRIDE'y z widgetu panel_heat (helpery; 'auto'/brak = zostaw decyzje AI) ===
$biegMode = val("SELECT value FROM helpers WHERE name='reku_bieg_mode' LIMIT 1");    // auto/off/1/2/3
$bypMode  = val("SELECT value FROM helpers WHERE name='reku_bypass_mode' LIMIT 1");  // auto/off/on
$fanMap   = ['off' => 'away', '1' => 'low', '2' => 'medium', '3' => 'high'];
$fanManual = ($biegMode !== null && $biegMode !== 'auto' && isset($fanMap[$biegMode]));
$bypManual = ($bypMode === 'on' || $bypMode === 'off');
$finalFan  = $fanManual ? $fanMap[$biegMode] : $aiFan;
$finalByp  = ($bypMode === 'on') ? 'open' : (($bypMode === 'off') ? 'closed' : $aiBypass);

logMsg(sprintf("DECYZJA: bieg=%s%s bypass=%s%s | AI: %s", $finalFan, $fanManual ? '(RECZNY)' : '', $finalByp, $bypManual ? '(RECZNY)' : '', $aiWhy));

// === Wykonanie (bypass: on12h=otwarty, auto=~zamkniety; ESP cofa -> ponawiamy co uruchomienie) ===
if     ($finalByp === 'open'  && $bypass < 100) { logMsg("OTWIERAM bypass (on12h)"); exec("php /opt/tymos/actions/set_reku_bypass.php on12h > /dev/null 2>&1 &"); }
elseif ($finalByp === 'closed' && $bypass > 0)  { logMsg("ZAMYKAM bypass (auto)");   exec("php /opt/tymos/actions/set_reku_bypass.php auto  > /dev/null 2>&1 &"); }
reku_fan($finalFan, $fanSpeed);
