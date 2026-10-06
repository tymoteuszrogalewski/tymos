#!/usr/bin/env php
<?php
/**
 * batsim.php — symulacja magazynu energii na REALNYCH danych godzinowych Pstryk.
 * Model chronologiczny ze stanem naladowania (SoC), miesiac liczony godzina po godzinie.
 *
 * Zalozenia:
 *   - ladowanie tylko w N najtanszych godzinach KAZDEJ doby (wybor per doba, po cenie)
 *   - sprawnosc ladowania 0.95, rozladowania 0.95 (round-trip ~90%, typowe LFP + falownik hybrydowy)
 *   - w godzinach ladowania dom bierze prad z sieci (i tak jest tanio), bateria sie laduje
 *   - w pozostalych godzinach dom jedzie z baterii; gdy SoC nie wystarczy -> dokup z sieci po cenie tej godziny
 *   - bateria startuje puste (pesymistycznie, pierwsza doba)
 */

require_once __DIR__ . '/lib/_log.inc.php';

$MONTH   = $argv[1] ?? '2026-06';
$ETA_C   = 0.95;
$ETA_D   = 0.95;
$P_MAX   = (float)($argv[2] ?? 10.0);   // kW, limit mocy ladowania

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { fwrite(STDERR, 'DB: ' . $db->connect_error . "\n"); exit(1); }

$res = $db->query("SELECT ts, kwh_pstryk k, full_price_pstryk p FROM energa
                   WHERE DATE_FORMAT(ts,'%Y-%m')='{$MONTH}' AND kwh_pstryk IS NOT NULL
                     AND full_price_pstryk IS NOT NULL ORDER BY ts");
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = ['ts'=>$r['ts'], 'd'=>substr($r['ts'],0,10), 'h'=>(int)substr($r['ts'],11,2), 'k'=>(float)$r['k'], 'p'=>(float)$r['p']];
if (!$rows) { echo "brak danych dla {$MONTH}\n"; exit(1); }

// --- oplata STALA na godzine (cost_full_pstryk - kwh*cena); bateria jej nie dotyka ---
$fx = $db->query("SELECT ROUND(cost_full_pstryk - kwh_pstryk*full_price_pstryk, 4) f FROM energa
                  WHERE DATE_FORMAT(ts,'%Y-%m')='{$MONTH}' AND cost_full_pstryk IS NOT NULL
                  ORDER BY f LIMIT 1 OFFSET 360")->fetch_assoc();
$FIX = (float)($fx['f'] ?? 0);

// --- baseline ---
$base_kwh = 0; $base_cost = 0; $peak = 0; $byDay = [];
foreach ($rows as $r) { $base_kwh += $r['k']; $base_cost += $r['k']*$r['p']; $peak = max($peak,$r['k']); $byDay[$r['d']][] = $r; }
$fix_total = $FIX * count($rows);
printf("MIESIAC %s  |  %d godzin, %d dni, limit mocy ladowania %.1f kW\n", $MONTH, count($rows), count($byDay), $P_MAX);
printf("BEZ BATERII: %.1f kWh | energia %.2f zl (sr %.4f zl/kWh) + oplaty stale %.2f zl (%.4f zl/h) = RACHUNEK %.2f zl\n",
    $base_kwh, $base_cost, $base_cost/$base_kwh, $fix_total, $FIX, $base_cost + $fix_total);
printf("szczyt godzinowy domu %.2f kWh\n\n", $peak);

// --- ile energii trzeba przewiezc przez bateria (potrzeba dobowa poza okienkiem ladowania) ---
function chargeHours($day, $N) {
    $p = [];
    foreach ($day as $i => $r) $p[$i] = $r['p'];
    asort($p);
    return array_slice(array_keys($p), 0, $N, true);
}

echo "POTRZEBA DOBOWA (energia do przewiezienia przez bateria, kWh):\n";
foreach ([2,3,4] as $N) {
    $need = [];
    foreach ($byDay as $d => $day) {
        $ch = array_flip(chargeHours($day, $N));
        $s = 0;
        foreach ($day as $i => $r) if (!isset($ch[$i])) $s += $r['k'];
        $need[] = $s;
    }
    sort($need);
    $n = count($need);
    printf("  N=%d h:  min %.1f | mediana %.1f | p90 %.1f | max %.1f\n",
        $N, $need[0], $need[intdiv($n,2)], $need[(int)floor(0.9*($n-1))], $need[$n-1]);
}

// --- symulacja chronologiczna dla siatki (N, pojemnosc) ---
function simulate($rows, $byDay, $N, $capUsable, $ETA_C, $ETA_D, $P_MAX) {
    $chg = [];   // ts => true
    foreach ($byDay as $d => $day) {
        foreach (chargeHours($day, $N) as $i) $chg[$day[$i]['ts']] = true;
    }
    $soc = 0.0; $cost = 0.0; $grid = 0.0; $deficit = 0.0; $maxP = 0.0;
    foreach ($rows as $r) {
        if (isset($chg[$r['ts']])) {
            // dom z sieci + ladowanie do pelna, limit mocy
            $room   = ($capUsable - $soc) / $ETA_C;          // kWh z sieci potrzebne do dopelnienia
            $charge = min($room, $P_MAX);                    // 1 h, wiec kWh == kW
            $soc   += $charge * $ETA_C;
            $draw   = $r['k'] + $charge;
            $cost  += $draw * $r['p']; $grid += $draw;
            $maxP   = max($maxP, $charge + $r['k']);
        } else {
            $fromBat = min($r['k'], $soc * $ETA_D);
            $soc    -= $fromBat / $ETA_D;
            $rest    = $r['k'] - $fromBat;
            if ($rest > 0.0001) { $cost += $rest * $r['p']; $grid += $rest; $deficit += $rest; }
        }
    }
    return [$cost, $grid, $deficit, $maxP];
}

$bill0 = $base_cost + $fix_total;
echo "RACHUNEK CALY (energia + oplaty stale) wg godzin ladowania x pojemnosc uzyteczna:\n";
printf("%-7s", "kWh");
foreach ([2,3,4] as $N) printf("      N=%d h      ", $N);
echo "\n";
foreach ([5,8,10,12,15,18,20,25] as $cap) {
    printf("%-7s", $cap);
    foreach ([2,3,4] as $N) {
        [$c,$g,$def,$mp] = simulate($rows, $byDay, $N, $cap, $ETA_C, $ETA_D, $P_MAX);
        printf("%7.0f zl (-%2.0f%%)  ", $c + $fix_total, 100*(1-($c+$fix_total)/$bill0));
    }
    echo "\n";
}

echo "\nSZCZEGOLY dla wybranych wariantow:\n";
foreach ([[3,10],[3,15],[3,20],[2,20],[4,20]] as [$N,$cap]) {
    [$c,$g,$def,$mp] = simulate($rows, $byDay, $N, $cap, $ETA_C, $ETA_D, $P_MAX);
    printf("  N=%d h, %2d kWh: energia %6.2f + stale %5.2f = %6.2f zl (-%.0f%%, oszczednosc %.0f zl) | z sieci %.1f kWh (+%.1f%%) | niepokryte %.1f kWh | szczyt poboru %.1f kW\n",
        $N, $cap, $c, $fix_total, $c + $fix_total, 100*(1-($c+$fix_total)/$bill0), $bill0-($c+$fix_total),
        $g, 100*($g/$base_kwh-1), $def, $mp);
}

// --- rozklad godzin wybieranych do ladowania ---
echo "\nKTORE GODZINY WYCHODZA NAJTANSZE (N=3, ile razy w miesiacu):\n";
$cnt = array_fill(0,24,0);
foreach ($byDay as $day) foreach (chargeHours($day,3) as $i) $cnt[$day[$i]['h']]++;
for ($h=0;$h<24;$h++) if ($cnt[$h]) printf("  %02d:00  %s (%d)\n", $h, str_repeat('#',$cnt[$h]), $cnt[$h]);
