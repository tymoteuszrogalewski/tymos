#!/usr/bin/env php
<?php
/**
 * health_check.php — okresowa walidacja spojnosci akcji TymOS w bazie.
 * Loguje WARN/ERROR do tabeli `log` (widoczne w panel_admin -> Logi).
 *
 * Wywolanie: akcja CRON system_health_check, co 1h.
 *
 * Sprawdza dla kazdej enabled akcji:
 *   - trigger_type='cron' wymaga przynajmniej jednego trigger.type='cron' z cron expression
 *   - jakikolwiek trigger.type='cron' ALE trigger_type != 'cron' → akcja wisi (ten bug 2026-04-20)
 *   - skrypty (z action.script) musza istniec i byc w /opt/tymos
 *   - cron expression musi miec 5 polow (basic syntax check)
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) {
    tymos_log('ERROR', 'health_check: DB connect: ' . $db->connect_error);
    exit(1);
}
$db->set_charset('utf8mb4');

$res = $db->query("SELECT id, name, enabled, trigger_type, trigger_config, actions FROM actions WHERE enabled=1");
if (!$res) { exit(1); }

$issues = 0;

while ($a = $res->fetch_assoc()) {
    $id = (int)$a['id'];
    $name = $a['name'];
    $tt = $a['trigger_type'];
    $triggers = json_decode($a['trigger_config'] ?? '[]', true) ?: [];
    $actions  = json_decode($a['actions']        ?? '[]', true) ?: [];

    $tag = "akcja#{$id} '{$name}'";

    // 1. Spojnosc trigger_type vs trigger_config
    $hasCron = false;
    foreach ($triggers as $tr) {
        if (($tr['type'] ?? '') === 'cron') { $hasCron = true; break; }
    }
    if ($tt === 'cron' && !$hasCron) {
        tymos_log('WARN', "{$tag}: trigger_type='cron' ale brak 'cron' typu w trigger_config");
        $issues++;
    }
    if ($tt !== 'cron' && $hasCron) {
        tymos_log('WARN', "{$tag}: zawiera 'cron' trigger ale trigger_type='{$tt}' — akcja nie odpali sie przez scheduler!");
        $issues++;
    }

    // 2. Cron expression sanity (5 pol)
    foreach ($triggers as $i => $tr) {
        if (($tr['type'] ?? '') !== 'cron') continue;
        $expr = $tr['cron'] ?? '';
        $parts = preg_split('/\s+/', trim($expr));
        if (count($parts) !== 5) {
            tymos_log('WARN', "{$tag}: trigger #" . ($i+1) . " cron expression niepoprawne ('{$expr}')");
            $issues++;
        }
    }

    // 3. Skrypty w action steps
    foreach ($actions as $i => $act) {
        if (($act['type'] ?? '') !== 'script') continue;
        $script = trim($act['script'] ?? '');
        if ($script === '') {
            tymos_log('WARN', "{$tag}: action #" . ($i+1) . " typ 'script' bez sciezki");
            $issues++;
            continue;
        }
        // Pierwszy token to plik (reszta to argumenty CLI)
        $file = explode(' ', $script)[0];
        if (strpos($file, '/opt/tymos/') !== 0) {
            tymos_log('WARN', "{$tag}: action #" . ($i+1) . " sciezka poza /opt/tymos: {$file}");
            $issues++;
        }
        if (!file_exists($file)) {
            tymos_log('ERROR', "{$tag}: action #" . ($i+1) . " skrypt nie istnieje: {$file}");
            $issues++;
        }
    }
}

if ($issues === 0) {
    // Cisza w log — health_check ma raportowac tylko gdy cos jest nie tak
    exit(0);
}
exit(0);
