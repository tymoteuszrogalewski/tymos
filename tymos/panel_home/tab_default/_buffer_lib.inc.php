<?php
/**
 * _buffer_lib.inc.php — shared helpers for buffer-split cards (monthly card_buffer_split + daily card_buffer_cwu_daily).
 * Cycle detection from stat<ID> (gap > 45 min = new cycle), global P90 baseline, Pstryk run/min24 pricing.
 * Guarded so both cards can require_once it within the same request without redeclare fatals.
 */

if (!function_exists('_bs_cycles')) {

// Sessionize cycles; flat list [['m'=>'YYYY-MM','d'=>'YYYY-MM-DD','kwh'=>float,'start'=>'YYYY-MM-DD HH:MM:SS'], ...].
function _bs_cycles($db, int $devId): array {
    $out = [];
    $res = $db->query("
        SELECT DATE_FORMAT(MIN(ts), '%Y-%m') AS m,
               DATE_FORMAT(MIN(ts), '%Y-%m-%d') AS d,
               MIN(ts) AS start,
               ROUND(SUM(power_avg)/12000, 4) AS kwh,
               TIMESTAMPDIFF(MINUTE, MIN(ts), MAX(ts)) AS mins
        FROM (
            SELECT ts, power_avg, SUM(ns) OVER (ORDER BY ts) AS cyc FROM (
                SELECT ts, power_avg,
                    CASE WHEN LAG(ts) OVER (ORDER BY ts) IS NULL
                          OR TIMESTAMPDIFF(MINUTE, LAG(ts) OVER (ORDER BY ts), ts) > 45
                         THEN 1 ELSE 0 END AS ns
                FROM stat{$devId}
                WHERE power_avg > 10
                  AND ts >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01 00:00:00')
            ) a
        ) b
        GROUP BY cyc HAVING kwh > 0.05 AND mins >= 20
    ");
    if ($res) while ($r = $res->fetch_assoc()) $out[] = ['m' => $r['m'], 'd' => $r['d'], 'kwh' => (float)$r['kwh'], 'start' => $r['start']];
    return $out;
}

function _bs_p90(array $vals): float {
    if (!$vals) return 0.0;
    sort($vals);
    return $vals[max(0, (int)ceil(0.9 * count($vals)) - 1)];
}

// run price (godzina cyklu) + min cena z 24h przed cyklem
function _bs_price(array $priceByHour, array $monthAvg, string $startTs): array {
    $base = strtotime($startTs);
    $h = date('Y-m-d H:00:00', $base);
    $m = substr($startTs, 0, 7);
    $run = $priceByHour[$h] ?? ($monthAvg[$m] ?? 0.0);
    $min24 = null;
    for ($i = 0; $i < 24; $i++) {
        $k = date('Y-m-d H:00:00', $base - $i * 3600);
        if (isset($priceByHour[$k])) $min24 = ($min24 === null) ? $priceByHour[$k] : min($min24, $priceByHour[$k]);
    }
    if ($min24 === null) $min24 = $run;
    return [$run, $min24];
}

} // function_exists guard
