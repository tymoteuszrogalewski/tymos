#!/usr/bin/env php
<?php
/**
 * ai_suszarka_done.php — komunikat glosowy na tablet gdy suszarka (dev17) skonczy suszenie.
 * Cron: co 1 minute (akcja TymOS typu script). Blizniak ai_pralka_done.php.
 *
 * PROFIL SUSZARKI — ZMIERZONY MINUTA PO MINUCIE na cyklu 2026-08-28:
 *   11:18   266-272 W   praca wlasciwa (cala godzina rowno 266-281 W)
 *   11:19   spadek
 *   11:20+  podloga 6,2-6,8 W + IMPULSY 70-105 W co 4-5 min (faza przeciw zagnieceniom)
 *
 * DWIE RZECZY, KTORE TU DECYDUJA I W PRALCE NIE WYSTEPUJA:
 * 1. **Elektronika suszarki NIGDY sie nie wylacza** — podloga 6,5 W trwa bez konca.
 *    Dlatego warunek „ponizej 3 W" (jak w pralce) NIE SPELNILBY SIE NIGDY. Pierwsza wersja
 *    tego skryptu miala ten blad; zlapane 2026-08-28 przy pierwszym cyklu.
 * 2. Faza przeciw zagnieceniom potrafi ciagnac sie dlugo po zakonczeniu suszenia i strzela
 *    do ~105 W. Czekanie na jej koniec oznaczaloby powiadomienie godzine po fakcie.
 *
 * STAD DEFINICJA: „koniec suszenia" = ustanie GRZANIA, nie ustanie poboru.
 *   WORK  = 150 W  — powyzej tego suszarka realnie suszy (praca 266-281 W, impulsy max 105 W)
 *   FIN   = 5 min  — tyle bez przekroczenia WORK i uznajemy cykl za zakonczony
 * Impulsy przeciw zagnieceniom (<=105 W) ani nie podtrzymuja stanu 'running', ani nie blokuja
 * wykrycia konca — leza w martwej strefie miedzy podloga a WORK. To jest sedno tego doboru.
 *
 * DWA KOMUNIKATY:
 *   - normalnie              -> 'suszarka'
 *   - gdy pralka skonczyla w trakcie suszenia (helper `pralka_czeka` = 1) -> 'suszarka_pralka'
 *     i kasujemy flage. To jest domkniecie obietnicy z ai_pralka_done.php („dam znac, kiedy skonczy").
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { exit(1); }

function val($q) { global $db; $r = $db->query($q); return ($r && $x = $r->fetch_row()) ? $x[0] : null; }

$DEV     = 17;    // suszarka
$WORK    = 150;   // W — powyzej = realne suszenie (grzanie). Patrz naglowek: dobrane miedzy
                  //     impulsami przeciw zagnieceniom (max ~105 W) a praca (266-281 W).
$FIN_MIN = 5;     // min bez przekroczenia WORK = koniec suszenia
$MIN_CNT = 15;    // min. probek w oknie (~6-12/min => ~40 na 5 min), zeby luka danych nie wyzwolila

$cur = val("SELECT power FROM device{$DEV} ORDER BY ts DESC LIMIT 1");
if ($cur === null) exit(0);
$cur = (float)$cur;

$stan = val("SELECT value FROM helpers WHERE name='suszarka_stan' LIMIT 1");
if ($stan === null) { $db->query("INSERT INTO helpers (name, value) VALUES ('suszarka_stan', 'idle')"); $stan = 'idle'; }
function setStan($v) { global $db; $db->query("UPDATE helpers SET value='{$v}' WHERE name='suszarka_stan'"); }

if ($stan === 'idle') {
    if ($cur > $WORK) { setStan('running'); echo date('c') . " suszarka START (moc={$cur}W)\n"; }
    exit(0);
}

// stan == running: czy przez ostatnie FIN_MIN minut nic nie przekroczylo WORK (koniec grzania)
$maxRecent = val("SELECT MAX(power) FROM device{$DEV} WHERE ts > NOW() - INTERVAL {$FIN_MIN} MINUTE");
$cnt       = (int) val("SELECT COUNT(*) FROM device{$DEV} WHERE ts > NOW() - INTERVAL {$FIN_MIN} MINUTE");

if ($cnt >= $MIN_CNT && $maxRecent !== null && (float)$maxRecent <= $WORK) {
    // Czy w trakcie suszenia skonczyla sie pralka i czeka na przelozenie?
    $czeka = val("SELECT value FROM helpers WHERE name='pralka_czeka' LIMIT 1");
    // CISZA NOCNA 23-06 (2026-09-07): jak w ai_pralka_done.php — flaga kasowana normalnie, tylko bez dzwieku.
    $cicho = tymos_cisza_nocna();
    if ((string)$czeka === '1') {
        if (!$cicho) $db->query("INSERT INTO tymos_sounds (sound) VALUES ('suszarka_pralka')");
        $db->query("UPDATE helpers SET value='0' WHERE name='pralka_czeka'");
        echo date('c') . " suszarka KONIEC + pralka czekala -> lektor laczony" . ($cicho ? " POMINIETY (cisza nocna)" : "") . "\n";
    } else {
        if (!$cicho) $db->query("INSERT INTO tymos_sounds (sound) VALUES ('suszarka')");
        echo date('c') . " suszarka KONIEC -> lektor (max{$FIN_MIN}min={$maxRecent}W, n={$cnt})" . ($cicho ? " POMINIETY (cisza nocna)" : "") . "\n";
    }
    if ($cicho) tymos_log('INFO', 'suszarka KONIEC w ciszy nocnej — lektor pominiety');
    setStan('idle');
}
exit(0);
