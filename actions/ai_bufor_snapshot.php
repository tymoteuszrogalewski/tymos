#!/usr/bin/env php
<?php
/**
 * ai_bufor_snapshot.php — zapisuje snapshot stanu bufora do tabeli bufor_ai
 * Pobiera temp bufora, moc grzalek, decyzje, stan urzadzen
 * Cron: co 5 minut
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

function val($q) { global $db; $r = $db->query($q); return ($r && $row = $r->fetch_row()) ? $row[0] : null; }
function lastState($id) { global $db; $r = $db->query("SELECT last_state FROM devices WHERE id={$id}"); return ($r && $row = $r->fetch_assoc()) ? (json_decode($row['last_state'] ?? '{}', true) ?: []) : []; }

$temp       = val("SELECT temperature FROM device12 ORDER BY ts DESC LIMIT 1");
$tempDol    = val("SELECT temperature FROM device11 ORDER BY ts DESC LIMIT 1");
// UWAGA NA NAZWY: power1 = device3 = "Bufor Grzałka 1" = grzalka DOLNA (L3, ta z wywalajacym STB),
// power2 = device1 = "Bufor Grzałka 2" = grzalka GÓRNA (L1). Kolumn w bufor_ai nie zmieniamy
// (historia danych), wiec mapowanie zyje w tym komentarzu i w card_bufor.inc.php.
$power1     = val("SELECT power FROM device3 ORDER BY ts DESC LIMIT 1");
$power2     = val("SELECT power FROM device1 ORDER BY ts DESC LIMIT 1");
$decision   = val("SELECT value FROM helpers WHERE id=9");
$target     = val("SELECT value FROM helpers WHERE id=10");
$pralkaW    = val("SELECT COALESCE(power, 0) FROM device19 ORDER BY ts DESC LIMIT 1");
// Nagrzewnica (id=16) — tabela device16 nie ma kolumny power, czytamy z devices.last_state
$nagrzW     = lastState(16)['power'] ?? null;
$podlogW    = val("SELECT power FROM device551 ORDER BY ts DESC LIMIT 1");
$zmyW       = val("SELECT power FROM device8 ORDER BY ts DESC LIMIT 1");

if (!is_numeric($temp)) { tymos_log('ERROR', "Nieprawidlowa temp bufora: '{$temp}'"); exit(1); }

function sqlVal($v) { return ($v !== null && is_numeric($v)) ? $v : 'NULL'; }
function isOn($w, $thresh = 1) { return (is_numeric($w) && (float)$w > $thresh) ? 1 : 0; }

$tempDolSql  = sqlVal($tempDol);
$power1Sql   = sqlVal($power1);
$power2Sql   = sqlVal($power2);
$decisionSql = sqlVal($decision);
$targetSql   = sqlVal($target);

$pralkaOn  = isOn($pralkaW, 10);
$nagrzOn   = isOn($nagrzW);
$podlogOn  = isOn($podlogW);
$zmyOn     = isOn($zmyW, 5);

// Prysznic: wilgotnosc lazienki (device754) — MAX z 7 min >= 70% LUB skok >= 12pp
$humMax  = val("SELECT MAX(humidity) FROM device754 WHERE ts >= NOW() - INTERVAL 7 MINUTE");
$humBase = val("SELECT humidity FROM device754 WHERE ts <= NOW() - INTERVAL 7 MINUTE ORDER BY ts DESC LIMIT 1");
$prysznicOn = 0;
if (is_numeric($humMax)) {
    if ((float)$humMax >= 70) $prysznicOn = 1;
    elseif (is_numeric($humBase) && ((float)$humMax - (float)$humBase >= 12)) $prysznicOn = 1;
}

// Zmywarka zawor (id=15): OPEN=1, CLOSE=0 — tabela device15 nie ma kolumny state, czytamy z devices.last_state
$zmyZaworState = lastState(15)['state'] ?? null;
$zmyZawor = ($zmyZaworState === 'OPEN') ? 1 : 0;

$ts = date('Y-m-d H:i:00');

$db->query("INSERT INTO bufor_ai (ts, temp, temp_dol, power1, power2, decision, target_temp, pralka, zmywarka, zmywarka_zawor, nagrzewnica, podlogowka, prysznic)
VALUES ('{$ts}', {$temp}, {$tempDolSql}, {$power1Sql}, {$power2Sql}, {$decisionSql}, {$targetSql}, {$pralkaOn}, {$zmyOn}, {$zmyZawor}, {$nagrzOn}, {$podlogOn}, {$prysznicOn})
ON DUPLICATE KEY UPDATE temp={$temp}, temp_dol={$tempDolSql}, power1={$power1Sql}, power2={$power2Sql}, decision={$decisionSql}, target_temp={$targetSql}, pralka={$pralkaOn}, zmywarka={$zmyOn}, zmywarka_zawor={$zmyZawor}, nagrzewnica={$nagrzOn}, podlogowka={$podlogOn}, prysznic={$prysznicOn}");

// Backfill: gdy urzadzenie wykryte, cofnij sie max 10 min wstecz
foreach (['pralka' => $pralkaOn, 'zmywarka' => $zmyOn, 'nagrzewnica' => $nagrzOn, 'podlogowka' => $podlogOn, 'prysznic' => $prysznicOn] as $col => $v) {
    if ($v === 1) {
        $db->query("UPDATE bufor_ai SET {$col}=1
            WHERE {$col}=0
            AND ts > GREATEST(COALESCE((SELECT MAX(ts) FROM (SELECT ts FROM bufor_ai WHERE {$col}=1 AND ts < '{$ts}') t), DATE_SUB('{$ts}', INTERVAL 10 MINUTE)), DATE_SUB('{$ts}', INTERVAL 10 MINUTE))
            AND ts < '{$ts}'");
    }
}

echo "{$ts} | bufor={$temp}C bufor_dol={$tempDolSql}C power1={$power1Sql}W power2={$power2Sql}W decision={$decisionSql} target={$targetSql}C pralka={$pralkaOn} zmywarka={$zmyOn}({$zmyW}W) zmyw_zawor={$zmyZawor} nagrzewnica={$nagrzOn} podlogowka={$podlogOn} prysznic={$prysznicOn}\n";
