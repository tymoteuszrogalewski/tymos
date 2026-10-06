#!/usr/bin/env php
<?php
/**
 * ai_bufor.php — Grzalka buforowa - decyzja na podstawie cen dynamicznych (Pstryk)
 * grzalka 3kW == ~6,5'C/h
 * Cron: co 5 minut
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

function val($r) { global $db; $q = $db->query($r); return ($q && $row = $q->fetch_row()) ? $row[0] : null; }
function setHelper($name, $v) {
    global $db;
    $db->query("UPDATE helpers SET value='{$v}' WHERE name='{$name}'");
    // sygnal do daemona — przeladuj helpers_cache (inaczej widzi stara wartosc do FORCE_RELOAD 30 min)
    $db->query("UPDATE settings SET v=UNIX_TIMESTAMP() WHERE k='tymos_reload'");
}
function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }

$curHour = (int)date('H');

// Prog grzalki z settings DB (fallback: 0.42)
$pelletProg = (float)(val("SELECT v FROM settings WHERE k='pellet_prog_grzalki' LIMIT 1") ?? '0.42');

// Piec wlaczony? (0=off/lato, 1=on/zima)
$piecOn = val("SELECT value FROM helpers WHERE name='piec_wlaczony' LIMIT 1") === '1';

// Ceny: biezaca godzina + nastepne 5h (window do nagrzania bufora moze trwac 2-3h)
$prices = [];
$r = $db->query("SELECT full_price_pstryk FROM energa WHERE ts >= DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') AND ts < DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') + INTERVAL 6 HOUR ORDER BY ts ASC");
while ($r && $row = $r->fetch_row()) $prices[] = (float)$row[0];

$curCost = $prices[0] ?? null;

if ($curCost === null) {
    logMsg("BRAK CENY: H={$curHour} — nie grzej");
    setHelper('grzalka_decision', 0);
    setHelper('grzalka_target_temp', 0);
    exit(0);
}

// Przyszle ceny min (H+1, H+2, H+3)
$cost1h = $prices[1] ?? null;
$cost2h = $cost1h;
$cost3h = $cost1h;
if (isset($prices[2])) $cost2h = min($cost2h, $prices[2]);
$cost3h = $cost2h;
if (isset($prices[3])) $cost3h = min($cost3h, $prices[3]);

// Temp bufora
$buforTemp = (float)val("SELECT temperature FROM device12 ORDER BY ts DESC LIMIT 1");
$buforTempDol = val("SELECT temperature FROM device11 ORDER BY ts DESC LIMIT 1");

if ($buforTemp === 0.0 && val("SELECT COUNT(*) FROM device12") == 0) {
    logMsg("BLAD: brak danych bufor temp");
    exit(1);
}

// temp_dol — jesli dostepna, uzyj MIN(gora,dol)
if ($buforTempDol !== null && is_numeric($buforTempDol)) {
    $buforTempDol = (float)$buforTempDol;
    if ($buforTempDol < $buforTemp) {
        logMsg("buforTemp=MIN(gora={$buforTemp},dol={$buforTempDol}) → {$buforTempDol}C");
        $buforTemp = $buforTempDol;
    } else {
        logMsg("buforTemp=MIN(gora={$buforTemp},dol={$buforTempDol}) → {$buforTemp}C (gora zimniejsza)");
    }
} else {
    logMsg("UWAGA: buforTempDol={$buforTempDol} (nie liczba, uzywam tylko gory)");
}

if ($cost1h === null) {
    logMsg("UWAGA: brak przyszlych cen — decyzja bez lookahead");
}

// === KROK 1: ustal target na podstawie ceny ===
$targetTemp = 0;
$decision = 1;

if ($curCost < 0) {
    $targetTemp = 65;   // zbite z 70: zabezpieczenie grzalki (STB) fabryczne ~70C — trzymamy margines
} elseif ($curCost < 0.25) {
    $targetTemp = 60;   // zbite z 65
} elseif ($curCost < $pelletProg) {
    $targetTemp = $piecOn ? 60 : 55;   // lato (piec off): gorny target 60->55 = mniej strat postojowych; zima: 60 (pojemnosc na CO)
} else {
    $targetTemp = 50;                  // podloga komfortu ZAWSZE 50 — zapas na pralka+kapiel jednoczesnie
}

// === KROK 1b: gdy piec wylaczony, szukaj relatywnego dolka ===
if (!$piecOn && $targetTemp <= 50) {
    $minFuture8h = val("SELECT MIN(full_price_pstryk) FROM energa WHERE ts > DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') AND ts <= DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') + INTERVAL 8 HOUR AND full_price_pstryk IS NOT NULL");
    if ($minFuture8h !== null && is_numeric($minFuture8h)) {
        if ($curCost <= (float)$minFuture8h + 0.05) {
            logMsg("PIEC_OFF DOLEK: curCost={$curCost} ~ min8h={$minFuture8h} — target 50→55");
            $targetTemp = 55;
        }
    } else {
        logMsg("PIEC_OFF: brak przyszlych cen 8h — target 50→55");
        $targetTemp = 55;
    }
}

// === KROK 1.5: lookahead 12h — jesli bedzie bardzo tanio, czekaj z minimum 50'C ===
$bestFuture12h = val("SELECT MIN(full_price_pstryk) FROM energa WHERE ts > DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') AND ts <= DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00') + INTERVAL 12 HOUR AND full_price_pstryk IS NOT NULL");
$lookahead12h = false;
if ($bestFuture12h !== null && is_numeric($bestFuture12h)) {
    $bf = (float)$bestFuture12h;
    if ($bf < -0.10) $lookahead12h = true;
    if (!$piecOn && !$lookahead12h && ($curCost - $bf >= 0.20)) $lookahead12h = true;
}
if ($lookahead12h) {
    if ($curCost < 0) {
        logMsg("LOOKAHEAD 12h: min={$bestFuture12h} ale curCost={$curCost} < 0 — grzeje (cena juz ujemna)");
    } elseif ($curCost < (float)$bestFuture12h) {
        logMsg("LOOKAHEAD 12h: min={$bestFuture12h} ale curCost={$curCost} juz tansze — grzeje normalnie");
    } elseif ($buforTemp >= 50) {
        logMsg("LOOKAHEAD 12h: min={$bestFuture12h} — czekam na dolek (bufor={$buforTemp}C >= 50)");
        $targetTemp = 50;
        $decision = -2;
        setHelper('grzalka_target_temp', $targetTemp);
        setHelper('grzalka_decision', $decision);
        exit(0);
    } else {
        logMsg("LOOKAHEAD 12h: min={$bestFuture12h} — dolek bedzie, ale bufor={$buforTemp}C < 50, grzeje do 50");
        $targetTemp = 50;
    }
}

// === KROK 2: czy czekac? (jesli w najblizszych h bedzie taniej o min 0.05 PLN) ===
$waitThresh = 0.05;
$targetOrig = $targetTemp;

if ($buforTemp < 40) {
    if ($targetTemp < 45) $targetTemp = 45;
} elseif ($buforTemp < 50) {
    // ponizej 50C zawsze grzej (floor komfortu), niezaleznie od cen
} else {
    // SLIDING WINDOW: znajdz N kolejnych godzin o najmniejszej SUMIE cen,
    // gdzie N = czas potrzebny na nagrzanie bufora do targetu (2 grzalki = ~13C/h).
    // Optymalizacja sumy a nie pojedynczej minimum — gdy nagrzanie trwa 2h,
    // "ta + nastepna" moga byc tansze niz "nastepna + jeszcze nastepna".
    $heating_rate = 13.0;  // °C/h dla 2 grzalek 6kW przy buforze 400L
    $hours_needed = max(1, (int)ceil(($targetOrig - $buforTemp) / $heating_rate));
    if ($hours_needed > count($prices)) $hours_needed = count($prices);
    if ($hours_needed >= 1 && count($prices) > $hours_needed) {
        $cur_sum = 0;
        for ($i = 0; $i < $hours_needed; $i++) $cur_sum += $prices[$i];
        $best_sum = $cur_sum;
        $best_start = 0;
        $running = $cur_sum;
        for ($s = 1; $s + $hours_needed <= count($prices); $s++) {
            $running = $running - $prices[$s-1] + $prices[$s+$hours_needed-1];
            if ($running < $best_sum) {
                $best_sum = $running;
                $best_start = $s;
            }
        }
        // Czekaj jesli w innym oknie zaoszczedzimy >= waitThresh per godzine (suma proporcjonalna)
        if ($best_start > 0 && ($cur_sum - $best_sum) >= $waitThresh * $hours_needed) {
            $decision = -1;
            logMsg("WINDOW {$hours_needed}h: best_start=+{$best_start}h sum={$best_sum} vs now={$cur_sum} — czekam");
        } else {
            logMsg("WINDOW {$hours_needed}h: now optimal (best_start=+{$best_start}h sum_now={$cur_sum} sum_best={$best_sum})");
        }
    }
}

// === KROK 3: bufor juz cieplky — nie trzeba grzac ===
if ($buforTemp >= $targetOrig) {
    $decision = ($curCost >= $pelletProg) ? -3 : 0;
    $targetTemp = 0;
}

// === Ustaw helpery TymOS ===
setHelper('grzalka_target_temp', $targetOrig);
setHelper('grzalka_decision', $decision);

logMsg("H={$curHour} cena={$curCost} bufor={$buforTemp}C target={$targetTemp}C decision={$decision}");
