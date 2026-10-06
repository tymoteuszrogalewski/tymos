<?php
/**
 * TymOS — agregaty 5-minutowe
 * Cron: co 5 minut (/opt/tymos/crons/aggregate.php)
 * Czyta z device<ID>, zapisuje do stat<ID>
 * Numeryczne pola: avg, min, max
 * Tekstowe pola (state, switch_*): last value
 *
 * Auto-backfill: wykrywa ostatni agregat per urzadzenie i nadrabia brakujace buckety.
 * Limit: 200 bucketow per urzadzenie per uruchomienie (~16h danych).
 */

$MAX_BUCKETS_PER_RUN = 200;

// Lock — zapobiega nakladaniu sie iteracji
$lockFile = '/tmp/tymos_cron_stats.lock';
$lock = fopen($lockFile, 'w');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // poprzednia iteracja jeszcze trwa
}

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
$db->set_charset('utf8mb4');

if ($db->connect_error) {
    tymos_log('ERROR', 'DB connect: ' . $db->connect_error);
    exit(1);
}

// Biezacy bucket (zaokraglony do 5 min w dol)
$now = new DateTime();
$min = (int)$now->format('i');
$bucket_min = $min - ($min % 5);
$now_bucket = clone $now;
$now_bucket->setTime((int)$now->format('H'), $bucket_min, 0);

$processed = 0;
$backfilled = 0;

// Pobierz wszystkie aktywne urzadzenia ktore maja tabele danych
$devs = $db->query("SELECT id, fields, enabled_fields FROM devices WHERE deleted=0 AND enabled_fields IS NOT NULL AND enabled_fields != '[]'");
if (!$devs) exit(0);

while ($dev = $devs->fetch_assoc()) {
    $id = $dev['id'];
    $fields = json_decode($dev['fields'], true) ?: [];
    $ef = json_decode($dev['enabled_fields'], true) ?: [];
    if (empty($ef)) continue;

    $raw_tbl = "device{$id}";
    $stat_tbl = "stat{$id}";

    // Sprawdz czy tabela RAW istnieje
    $chk = $db->query("SHOW TABLES LIKE '{$raw_tbl}'");
    if (!$chk || $chk->num_rows === 0) continue;

    // Klasyfikuj pola: numeric vs text
    $numeric = [];
    $text = [];
    foreach ($ef as $fname) {
        if (!isset($fields[$fname])) continue;
        $finfo = $fields[$fname];
        $sql_type = is_array($finfo) ? ($finfo['sql'] ?? 'TEXT') : $finfo;
        $upper = strtoupper(explode('(', $sql_type)[0]);
        if (in_array($upper, ['DOUBLE', 'FLOAT', 'BIGINT', 'INT', 'TINYINT'])) {
            $numeric[] = $fname;
        } else {
            $text[] = $fname;
        }
    }

    if (empty($numeric) && empty($text)) continue;

    // Utworz tabele stat jesli nie istnieje
    $cols = ["ts DATETIME NOT NULL"];
    foreach ($numeric as $f) {
        $cols[] = "`{$f}_avg` DOUBLE DEFAULT NULL";
        $cols[] = "`{$f}_min` DOUBLE DEFAULT NULL";
        $cols[] = "`{$f}_max` DOUBLE DEFAULT NULL";
    }
    foreach ($text as $f) {
        $cols[] = "`{$f}_last` VARCHAR(64) DEFAULT NULL";
    }
    $cols[] = "samples INT DEFAULT 0";
    $cols[] = "PRIMARY KEY (ts)";
    $create = "CREATE TABLE IF NOT EXISTS `{$stat_tbl}` (" . implode(', ', $cols) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->query($create);

    // Sync kolumn — dodaj brakujace
    $existing = [];
    $colRows = $db->query("SHOW COLUMNS FROM `{$stat_tbl}`");
    while ($cr = $colRows->fetch_assoc()) $existing[] = $cr['Field'];
    foreach ($numeric as $f) {
        if (!in_array("{$f}_avg", $existing)) {
            $db->query("ALTER TABLE `{$stat_tbl}` ADD COLUMN `{$f}_avg` DOUBLE DEFAULT NULL");
            $db->query("ALTER TABLE `{$stat_tbl}` ADD COLUMN `{$f}_min` DOUBLE DEFAULT NULL");
            $db->query("ALTER TABLE `{$stat_tbl}` ADD COLUMN `{$f}_max` DOUBLE DEFAULT NULL");
        }
    }
    foreach ($text as $f) {
        if (!in_array("{$f}_last", $existing)) {
            $db->query("ALTER TABLE `{$stat_tbl}` ADD COLUMN `{$f}_last` VARCHAR(64) DEFAULT NULL");
        }
    }
    if (!in_array('samples', $existing)) {
        $db->query("ALTER TABLE `{$stat_tbl}` ADD COLUMN `samples` INT DEFAULT 0");
    }

    // Znajdz od kiedy trzeba agregowac — od ostatniego statu lub poczatku RAW
    $last_stat = $db->query("SELECT MAX(ts) AS last_ts FROM `{$stat_tbl}`");
    $last_ts = $last_stat ? $last_stat->fetch_assoc()['last_ts'] : null;

    if ($last_ts) {
        // Kontynuuj od nastepnego bucketa po ostatnim stacie
        $start_bucket = new DateTime($last_ts);
        $start_bucket->modify('+5 minutes');
    } else {
        // Brak statow — zacznij od poczatku RAW
        $first_raw = $db->query("SELECT MIN(ts) AS first_ts FROM `{$raw_tbl}`");
        $first_ts = $first_raw ? $first_raw->fetch_assoc()['first_ts'] : null;
        if (!$first_ts) continue;
        $start_bucket = new DateTime($first_ts);
        $sb_min = (int)$start_bucket->format('i');
        $start_bucket->setTime((int)$start_bucket->format('H'), $sb_min - ($sb_min % 5), 0);
    }

    // Iteruj od start_bucket, pomijaj puste bez liczenia limitu
    $buckets_done = 0;
    $cursor = clone $start_bucket;

    while ($cursor <= $now_bucket && $buckets_done < $MAX_BUCKETS_PER_RUN) {
        $bucket_ts = $cursor->format('Y-m-d H:i:s');
        $bucket_end = (clone $cursor)->modify('+5 minutes')->format('Y-m-d H:i:s');

        // Sprawdz czy sa dane w tym buckecie
        $cnt = $db->query("SELECT COUNT(*) AS c FROM `{$raw_tbl}` WHERE ts >= '{$bucket_ts}' AND ts < '{$bucket_end}'");
        if (!$cnt) { $cursor->modify('+5 minutes'); continue; }
        $row = $cnt->fetch_assoc();
        $samples = (int)$row['c'];
        if ($samples === 0) { $cursor->modify('+5 minutes'); continue; }

        // Agregacja numeryczna
        $selects = [];
        $insert_cols = ['ts'];

        foreach ($numeric as $f) {
            $fe = $db->real_escape_string($f);
            $selects[] = "ROUND(AVG(`{$fe}`), 4) AS `{$f}_avg`";
            $selects[] = "MIN(`{$fe}`) AS `{$f}_min`";
            $selects[] = "MAX(`{$fe}`) AS `{$f}_max`";
            $insert_cols[] = "`{$f}_avg`";
            $insert_cols[] = "`{$f}_min`";
            $insert_cols[] = "`{$f}_max`";
        }

        $agg_data = [];
        if (!empty($selects)) {
            $sql = "SELECT " . implode(', ', $selects) . " FROM `{$raw_tbl}` WHERE ts >= '{$bucket_ts}' AND ts < '{$bucket_end}'";
            $res = $db->query($sql);
            if ($res) $agg_data = $res->fetch_assoc();
        }

        // Tekstowe: ostatnia wartosc
        $text_data = [];
        foreach ($text as $f) {
            $fe = $db->real_escape_string($f);
            $res = $db->query("SELECT `{$fe}` AS v FROM `{$raw_tbl}` WHERE ts >= '{$bucket_ts}' AND ts < '{$bucket_end}' AND `{$fe}` IS NOT NULL ORDER BY ts DESC LIMIT 1");
            if ($res && $r = $res->fetch_assoc()) {
                $text_data["{$f}_last"] = $r['v'];
            }
            $insert_cols[] = "`{$f}_last`";
        }

        // INSERT ... ON DUPLICATE KEY UPDATE
        $vals = ["'{$bucket_ts}'"];
        $updates = [];
        foreach ($numeric as $f) {
            foreach (['avg', 'min', 'max'] as $agg) {
                $key = "{$f}_{$agg}";
                $v = isset($agg_data[$key]) && $agg_data[$key] !== null ? $agg_data[$key] : 'NULL';
                $vals[] = $v;
                $updates[] = "`{$key}`={$v}";
            }
        }
        foreach ($text as $f) {
            $key = "{$f}_last";
            $v = isset($text_data[$key]) ? "'" . $db->real_escape_string($text_data[$key]) . "'" : 'NULL';
            $vals[] = $v;
            $updates[] = "`{$key}`={$v}";
        }
        $insert_cols[] = "samples";
        $vals[] = $samples;
        $updates[] = "samples={$samples}";

        $sql = "INSERT INTO `{$stat_tbl}` (" . implode(', ', $insert_cols) . ") VALUES (" . implode(', ', $vals) . ") ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
        $db->query($sql);

        if ($cursor < $now_bucket) $backfilled++;
        $cursor->modify('+5 minutes');
        $buckets_done++;
    }

    if ($buckets_done > 0) $processed++;
}

$parts = [];
if ($processed > 0) $parts[] = "{$processed} urzadzen";
if ($backfilled > 0) $parts[] = "backfill: {$backfilled} bucketow";
if (!empty($parts)) {
    echo date('Y-m-d H:i:s') . " Agregacja: " . implode(', ', $parts) . "\n";
}
