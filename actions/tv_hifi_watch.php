#!/usr/bin/env php
<?php
/**
 * tv_hifi_watch.php — wlaczenie TV => wlaczenie wiezy Technics na wejsciu OPT (dzwiek TV przez HiFi).
 * Dziala ciagle jako usluga systemd tymos-tv-hifi (cron co minute bylby za wolny).
 * Przy okazji co SPOTIFY_EVERY obiegow zapamietuje biezace odtwarzanie Spotify (hifi_spotify_remember)
 * dla przycisku SPOTIFY/PLAY w TymOS — niezaleznie od tego, czy jakis kiosk jest otwarty.
 *
 * Wykrywanie TV: co POLL_S sekund status Cast (TLS 8009, GET_STATUS -> isStandBy). Sama odpowiedz portu
 * NIE wystarcza: wylaczony TV w czuwaniu co jakis czas "budzi sie" w sieci na kilka sekund (isStandBy=true)
 * — 2026-11-27 wieza dwa razy sama przeskoczyla ze Spotify na OPT.
 *   - TV wlaczony = isStandBy=false
 *   - TV wylaczony = OFF_STANDBY razy isStandBy=true z rzedu albo OFF_FAILS razy brak polaczenia
 * Na starcie uslugi przyjmujemy biezacy stan bez akcji (restart przy wlaczonym TV nie przelacza wiezy).
 *
 * Akcja przy wlaczeniu TV: SetPower ON (jesli wieza w czuwaniu), czekanie az wstanie, SetSelector OPT
 * i sprawdzenie GetSelector, potem glosnosc HIFI_VOL_TV (21 — TV jest ciche).
 * Przejscie wiezy na Spotify (stream) z innego zrodla, np. przelaczenie z telefonu po TV: glosnosc
 * HIFI_VOL_MUSIC (16). PLAY w TymOS ustawia 16 sam (api hifi_set).
 * Wylaczenie TV: jesli wieza jest wlaczona i NADAL na OPT — wylaczamy ja (SetPower OFF). Jesli user
 * przelaczyl na inne zrodlo (np. Spotify, zeby sluchac muzyki), zostawiamy.
 */

require_once __DIR__ . '/lib/_log.inc.php';
require_once '/opt/tymos/tymos/inc/hifi.inc.php';
require_once '/opt/tymos/tymos/inc/spotify.inc.php';

const TV_IP      = '192.168.1.48';   // Sharp Google TV
const TV_PORT    = 8009;              // Cast (TLS) — samo polaczenie TCP wystarczy
const POLL_S     = 2;
const OFF_STANDBY = 3;                // 3 x 2 s isStandBy=true z rzedu => TV wylaczony
const OFF_FAILS  = 30;               // 30 x 2 s = 60 s bez polaczenia => TV wylaczony (wlaczony TV po starcie potrafi milczec >10 s)
const WAKE_MAX_S = 20;                // ile czekamy, az wieza wstanie z czuwania
const SPOTIFY_EVERY = 2;              // co 2 obiegi (4 s) zapis biezacego utworu Spotify (sesja StreamSDK, zapas)
const SPOTIFY_API_EVERY = 5;          // co 5 obiegow (10 s) zapis miejsca przez Spotify Web API (gdy wieza na sieci)

function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }

// Pole protobuf typu string (Cast v2 CastMessage).
function cast_str($field, $s) {
    $v = ''; $n = strlen($s);
    do { $b = $n & 0x7f; $n >>= 7; $v .= chr($n ? $b | 0x80 : $b); } while ($n);
    return chr(($field << 3) | 2) . $v . $s;
}
function cast_msg($ns, $payload) {
    $m = "\x08\x00" . cast_str(2, 'sender-0') . cast_str(3, 'receiver-0') . cast_str(4, $ns) . "\x28\x00" . cast_str(6, $payload);
    return pack('N', strlen($m)) . $m;
}

/**
 * Stan TV: 'off' (brak polaczenia), 'standby' (Cast odpowiada, ale isStandBy=true — wylaczony TV
 * "budzi sie" w sieci co jakis czas na kilka sekund), 'on' (isStandBy=false), 'unknown' (polaczenie
 * jest, statusu brak). Cast v2: TLS na 8009, CONNECT + GET_STATUS w przestrzeni receiver.
 */
function tv_state() {
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $fp = @stream_socket_client('tls://' . TV_IP . ':' . TV_PORT, $errno, $errstr, 1.5, STREAM_CLIENT_CONNECT, $ctx);
    if ($fp === false) return 'off';
    stream_set_timeout($fp, 2);
    @fwrite($fp, cast_msg('urn:x-cast:com.google.cast.tp.connection', '{"type":"CONNECT"}')
               . cast_msg('urn:x-cast:com.google.cast.receiver', '{"type":"GET_STATUS","requestId":1}'));
    $buf = ''; $end = microtime(true) + 2.5;
    while (microtime(true) < $end && !feof($fp)) {
        $d = @fread($fp, 8192);
        if ($d === false || $d === '') { if (stream_get_meta_data($fp)['timed_out']) break; continue; }
        $buf .= $d;
        if (preg_match('/"isStandBy":(true|false)/', $buf, $m)) { fclose($fp); return $m[1] === 'true' ? 'standby' : 'on'; }
    }
    fclose($fp);
    return 'unknown';
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
            $vol = hifi_volume_set(HIFI_VOL_TV);
            logMsg("HiFi na OPT ({$s} s, proba {$i}), glosnosc " . HIFI_VOL_TV . ($vol ? '' : ' BLAD'));
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

$on    = tv_state() === 'on';
$fails = 0;
logMsg('start, TV ' . ($on ? 'wlaczony' : 'wylaczony'));

$loop = 0;
$stby = 0;
$last = '';
$prevSrc = hifi_source();
while (true) {
    sleep(POLL_S);
    if (++$loop % SPOTIFY_EVERY === 0) {
        hifi_spotify_remember();
        // Wieza przeszla na Spotify z innego zrodla (np. z telefonu po ogladaniu TV na 21) => 16
        $src = hifi_source();
        if ($src === 'stream' && $prevSrc !== null && $prevSrc !== 'stream' && hifi_power_on()) {
            hifi_volume_set(HIFI_VOL_MUSIC);
            logMsg("zrodlo {$prevSrc} -> stream, glosnosc " . HIFI_VOL_MUSIC);
        }
        if ($src !== null) $prevSrc = $src;
    }
    if ($loop % SPOTIFY_API_EVERY === 0 && $prevSrc === 'stream') spotify_remember();
    $st = tv_state();
    if ($st !== $last) { logMsg("TV: {$st}"); $last = $st; }
    if ($st === 'on') {
        $fails = 0; $stby = 0;
        if (!$on) {
            $on = true;
            logMsg('TV wlaczony');
            tv_turned_on();
        }
    } elseif (!$on) {
        // nic — 'standby' / 'unknown' przy wylaczonym TV to tylko jego przebudzenia w sieci
    } elseif ($st === 'unknown') {
        // polaczenie jest, ale bez statusu — nie zmieniamy stanu
    } elseif (($st === 'standby' && ++$stby >= OFF_STANDBY) || ($st === 'off' && ++$fails >= OFF_FAILS)) {
        $on = false;
        logMsg('TV wylaczony');
        tv_turned_off();
    }
}
