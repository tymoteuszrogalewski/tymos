#!/usr/bin/env php
<?php
/**
 * tv_hifi_watch.php — wlaczenie TV => wlaczenie wiezy Technics na wejsciu OPT (dzwiek TV przez HiFi).
 * Dziala ciagle jako usluga systemd tymos-tv-hifi (cron co minute bylby za wolny).
 *
 * Wykrywanie TV: co POLL_S sekund proba polaczenia TCP z TV na port Cast 8009. Wylaczony TV (po ~1 min
 * czuwania sieciowego) w ogole nie odpowiada, wlaczony odpowiada od razu.
 *   - TV wlaczony = pierwsza udana proba po stanie "wylaczony"
 *   - TV wylaczony = OFF_FAILS nieudanych prob z rzedu (pojedyncze zgubione pakiety nie przelaczaja)
 * Na starcie uslugi przyjmujemy biezacy stan bez akcji (restart przy wlaczonym TV nie przelacza wiezy).
 *
 * Akcja przy wlaczeniu TV: SetPower ON (jesli wieza w czuwaniu), czekanie az wstanie, SetSelector OPT
 * i sprawdzenie GetSelector. Glosnosci nie ruszamy — w czuwaniu ustawia ja hifi_volume_reset.php (16).
 * Wylaczenie TV: jesli wieza jest wlaczona i NADAL na OPT — wylaczamy ja (SetPower OFF). Jesli user
 * przelaczyl na inne zrodlo (np. Spotify, zeby sluchac muzyki), zostawiamy. Wykrycie trwa ~2 min,
 * bo TV po wylaczeniu jeszcze ok. minuty odpowiada w sieci (czuwanie sieciowe).
 *
 * Znane ograniczenie: przez ~1 min po wylaczeniu TV dalej odpowiada na 8009, wiec ponowne wlaczenie TV
 * w tej minucie nie zostanie wykryte.
 */

require_once __DIR__ . '/lib/_log.inc.php';
require_once '/opt/tymos/tymos/inc/hifi.inc.php';

const TV_IP      = '192.168.1.48';   // Sharp Google TV
const TV_PORT    = 8009;              // Cast (TLS) — samo polaczenie TCP wystarczy
const POLL_S     = 2;
const OFF_FAILS  = 30;                // 30 x 2 s = 60 s bez odpowiedzi => TV wylaczony (wlaczony TV po starcie potrafi milczec >10 s)
const WAKE_MAX_S = 20;                // ile czekamy, az wieza wstanie z czuwania

function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }

function tv_alive() {
    $fp = @fsockopen(TV_IP, TV_PORT, $errno, $errstr, 1.0);
    if ($fp === false) return false;
    fclose($fp);
    return true;
}

function hifi_power_on() {
    $p = hifi_alljoyn('GetPower');
    return $p !== null && $p === HIFI_POWER_ON;
}

function tv_turned_on() {
    $t0 = microtime(true);
    if (!hifi_power_on()) {
        if (hifi_alljoyn('SetPower', HIFI_POWER_ON) === null) {
            tymos_log('ERROR', 'tv_hifi_watch: SetPower ON - brak odpowiedzi wiezy');
            return;
        }
        $deadline = time() + WAKE_MAX_S;
        while (!hifi_power_on() && time() < $deadline) usleep(500000);
    }
    for ($i = 1; $i <= 3; $i++) {
        hifi_alljoyn('SetSelector', HIFI_SRC['opt']);
        usleep(700000);
        if (hifi_source() === 'opt') {
            $s = round(microtime(true) - $t0, 1);
            tymos_log('INFO', "tv_hifi_watch: TV wlaczony -> HiFi wlaczona na OPT ({$s} s, proba {$i})");
            logMsg("HiFi na OPT ({$s} s, proba {$i})");
            return;
        }
        sleep(1);
    }
    tymos_log('WARN', 'tv_hifi_watch: TV wlaczony, ale wieza nie przelaczyla sie na OPT');
}

function tv_turned_off() {
    if (!hifi_power_on()) { logMsg('wieza juz wylaczona'); return; }
    $src = hifi_source();
    if ($src !== 'opt') {
        logMsg('wieza na zrodle ' . ($src ?? '?') . ' - zostawiam wlaczona');
        return;
    }
    if (hifi_alljoyn('SetPower', HIFI_POWER_OFF) === null) {
        tymos_log('ERROR', 'tv_hifi_watch: SetPower OFF - brak odpowiedzi wiezy');
        return;
    }
    tymos_log('INFO', 'tv_hifi_watch: TV wylaczony -> HiFi (OPT) wylaczona');
    logMsg('HiFi wylaczona (byla na OPT)');
}

$on    = tv_alive();
$fails = 0;
logMsg('start, TV ' . ($on ? 'wlaczony' : 'wylaczony'));

while (true) {
    sleep(POLL_S);
    if (tv_alive()) {
        $fails = 0;
        if (!$on) {
            $on = true;
            logMsg('TV wlaczony');
            tv_turned_on();
        }
    } elseif ($on && ++$fails >= OFF_FAILS) {
        $on = false;
        logMsg('TV wylaczony');
        tv_turned_off();
    }
}
