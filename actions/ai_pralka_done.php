#!/usr/bin/env php
<?php
/**
 * ai_pralka_done.php — komunikat glosowy na tablet gdy pralka (dev19) skonczy pranie.
 * Cron: co 1 minute (akcja TymOS typu script).
 *
 * Detekcja (analiza cyklu: w trakcie prania moc ma podloge ~5W = elektronika ON,
 * nawet w pauzach; 0W pojawia sie DOPIERO po zakonczeniu):
 *   - stan 'running'  gdy biezaca moc > START (realna faza prania),
 *   - stan 'finished' gdy moc <= OFF nieprzerwanie przez >= FIN_MIN minut -> dzwiek + 'idle'.
 * Podloga 5W w trakcie (>OFF) chroni przed falszywym alarmem w pauzach.
 * Dzwiek: INSERT do tymos_sounds (tablet pollinguje tymos_sound_check co 3s).
 *
 * DWA WARIANTY KOMUNIKATU (2026-08-28):
 *   - suszarka wolna  -> 'pralka'        („przeloz je do suszarki")
 *   - suszarka pracuje -> 'pralka_czeka' („suszarka jeszcze pracuje, dam znac kiedy skonczy")
 *     + helper `pralka_czeka=1`, ktory ai_suszarka_done.php zamienia potem na komunikat laczony.
 * 'pralka' = lektor snd/pralka.mp3 („Uwaga. Pranie zakonczone. Prosze przelozyc je do suszarki."),
 * zastapil syntetyczne PIK x5 2026-07-31 (zostaje w doorbell.inc.js jako 'pralka_pik').
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { exit(1); }

function val($q) { global $db; $r = $db->query($q); return ($r && $x = $r->fetch_row()) ? $x[0] : null; }

$DEV     = 19;    // pralka
$START   = 30;    // W — powyzej = pralka pracuje (start cyklu)
$OFF     = 3;     // W — ponizej = brak poboru (pralka zgaszona)
$FIN_MIN = 5;     // min ciaglego <=OFF = koniec prania (5 min: po cyklu bywa jeszcze odryglowanie drzwi itp.)
$MIN_CNT = 8;     // min. probek w oknie (co ~30s => ~10/5min), zeby luka danych nie wyzwolila

$cur = val("SELECT power FROM device{$DEV} ORDER BY ts DESC LIMIT 1");
if ($cur === null) exit(0);
$cur = (float)$cur;

$stan = val("SELECT value FROM helpers WHERE name='pralka_stan' LIMIT 1");
if ($stan === null) { $db->query("INSERT INTO helpers (name, value) VALUES ('pralka_stan', 'idle')"); $stan = 'idle'; }
function setStan($v) { global $db; $db->query("UPDATE helpers SET value='{$v}' WHERE name='pralka_stan'"); }

if ($stan === 'idle') {
    if ($cur > $START) { setStan('running'); echo date('c') . " pralka START (moc={$cur}W)\n"; }
    exit(0);
}

// stan == running: sprawdz czy przez ostatnie FIN_MIN minut moc <= OFF (koniec)
$maxRecent = val("SELECT MAX(power) FROM device{$DEV} WHERE ts > NOW() - INTERVAL {$FIN_MIN} MINUTE");
$cnt       = (int) val("SELECT COUNT(*) FROM device{$DEV} WHERE ts > NOW() - INTERVAL {$FIN_MIN} MINUTE");

if ($cnt >= $MIN_CNT && $maxRecent !== null && (float)$maxRecent <= $OFF) {
    // SUSZARKA ZAJETA? Komunikat „przeloz do suszarki" jest wtedy bez sensu — suszarka pracuje.
    // Mowimy wiec co innego i zostawiamy flage `pralka_czeka`, ktora ai_suszarka_done.php
    // domyka po zakonczeniu suszenia („dam znac, kiedy skonczy").
    // Suszarka = dev17; SUSZ_ON dobrane tak samo jak START w ai_suszarka_done.php.
    // 150 W = ten sam prog co WORK w ai_suszarka_done.php. Nizej byloby zle: faza przeciw
    // zagnieceniom strzela do ~105 W juz PO zakonczeniu suszenia, a wtedy obietnica
    // „dam znac, kiedy skonczy" nigdy by sie nie domknela (suszarka juz zglosila koniec).
    $SUSZ_DEV = 17;
    $SUSZ_ON  = 150;
    $suszMax  = val("SELECT MAX(power) FROM device{$SUSZ_DEV} WHERE ts > NOW() - INTERVAL 5 MINUTE");
    $suszy    = ($suszMax !== null && (float)$suszMax > $SUSZ_ON);

    // CISZA NOCNA 23-06 (2026-09-07): pranie w nocy = zero lektora. Stan i flaga `pralka_czeka`
    // ida normalnie, zeby lancuch pralka->suszarka sie nie rozjechal; gubimy tylko dzwiek.
    $cicho = tymos_cisza_nocna();
    if ($suszy) {
        if (!$cicho) $db->query("INSERT INTO tymos_sounds (sound) VALUES ('pralka_czeka')");
        $db->query("INSERT INTO helpers (name, value) VALUES ('pralka_czeka', '1')
                    ON DUPLICATE KEY UPDATE value='1'");
        echo date('c') . " pralka KONIEC, suszarka pracuje ({$suszMax}W) -> lektor 'czeka'" . ($cicho ? " POMINIETY (cisza nocna)" : "") . "\n";
    } else {
        if (!$cicho) $db->query("INSERT INTO tymos_sounds (sound) VALUES ('pralka')");
        echo date('c') . " pralka KONIEC -> lektor (max{$FIN_MIN}min={$maxRecent}W, n={$cnt})" . ($cicho ? " POMINIETY (cisza nocna)" : "") . "\n";
    }
    if ($cicho) tymos_log('INFO', 'pralka KONIEC w ciszy nocnej — lektor pominiety');
    setStan('idle');
}
exit(0);
