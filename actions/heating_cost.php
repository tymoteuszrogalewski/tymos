#!/usr/bin/env php
<?php
/**
 * heating_cost.php — oblicza godzinowy koszt grzania bufora (prad dynamiczny vs pellet)
 * Dane z TymOS: device3 (grzalka DOLNA), device1 (grzalka GÓRNA), device10 (Patio, temp w cieniu)
 * Cron: co 30 minut
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

$db->query("
CREATE TABLE IF NOT EXISTS energa_vs_pellet (
  ts           DATETIME     NOT NULL PRIMARY KEY,
  kwh          DECIMAL(10,6) NOT NULL DEFAULT 0,
  cost_electric DECIMAL(10,4) NOT NULL DEFAULT 0,
  cost_pellet  DECIMAL(10,4) NOT NULL DEFAULT 0
)");

$existing = (int)$db->query("SELECT COUNT(*) AS c FROM energa_vs_pellet")->fetch_assoc()['c'];
// Incremental run: limit device rows to last 50h (LAG needs a predecessor before the 48h window)
$devFilter   = $existing === 0 ? '' : 'AND ts >= NOW() - INTERVAL 50 HOUR';
$rangeFilter = $existing === 0 ? '' : 'AND hour_ts >= NOW() - INTERVAL 48 HOUR';
$rangeDesc   = $existing === 0 ? 'full' : '48h';

$db->query("
INSERT INTO energa_vs_pellet (ts, kwh, cost_electric, cost_pellet)
SELECT
  g.hour_ts,
  g.kwh,
  ROUND(g.kwh * COALESCE(ep.full_price_pstryk, 0), 4),
  ROUND(g.kwh * CASE
    -- Stawki PLN/kWh ciepla w buforze. Skalibrowane dla:
    -- - pellet Barlinek 1700 zl/t (4.9 kWh/kg netto = 0.347 PLN/kWh czystego paliwa)
    -- - dom pasywny + bufor obsluguje CO+CWU+AGD (zmywarka/pralka)
    -- - mala delta buforu = piec boi sie pelnej mocy → niska sprawnosc na malej mocy
    -- - lato/wiosna: krotkie cykle CWU + niska moc, sprawnosc ~43-53%
    -- - zima: dlugie sesje, sprawnosc ~63-81%
    WHEN avg_day.avg_temp IS NULL THEN 0.55
    WHEN avg_day.avg_temp > 15   THEN 0.80
    WHEN avg_day.avg_temp > 8    THEN 0.65
    WHEN avg_day.avg_temp > 0    THEN 0.55
    WHEN avg_day.avg_temp > -15  THEN 0.48
    ELSE 0.43
  END, 4)
FROM (
  -- kWh per hour = sum of positive increments of the cumulative energy counter.
  -- The plug counter is monotonic, so its rise IS the total consumption. LAG over
  -- non-NULL energy readings per device; drop resets (d<=0) and glitches (d>=5).
  -- No power-based fallback: it double-counted hours where energy was reported flat.
  SELECT hour_ts, ROUND(SUM(d), 6) AS kwh
  FROM (
    SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:00:00') AS hour_ts,
           energy - LAG(energy) OVER (ORDER BY ts) AS d
    FROM device3 WHERE energy IS NOT NULL {$devFilter}
    UNION ALL
    SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:00:00') AS hour_ts,
           energy - LAG(energy) OVER (ORDER BY ts) AS d
    FROM device1 WHERE energy IS NOT NULL {$devFilter}
  ) deltas
  WHERE d > 0 AND d < 5 {$rangeFilter}
  GROUP BY hour_ts
  HAVING kwh > 0
) g
LEFT JOIN energa ep ON ep.ts = g.hour_ts
LEFT JOIN (
  SELECT DATE(ts) AS day, ROUND(AVG(temperature), 1) AS avg_temp
  FROM device10
  WHERE temperature IS NOT NULL
  GROUP BY DATE(ts)
) avg_day ON avg_day.day = DATE(g.hour_ts)
ON DUPLICATE KEY UPDATE
  kwh          = VALUES(kwh),
  cost_electric = VALUES(cost_electric),
  cost_pellet  = VALUES(cost_pellet)
");

$rows = (int)$db->query("SELECT COUNT(*) AS c FROM energa_vs_pellet")->fetch_assoc()['c'];

// Dynamiczny prog grzalki wg najnizszej dobowej temp
$r = $db->query("SELECT ROUND(MIN(temperature),1) AS mt FROM device10 WHERE DATE(ts) = CURDATE() AND temperature IS NOT NULL");
$minTemp = $r ? ($r->fetch_assoc()['mt'] ?? null) : null;

if ($minTemp !== null) {
    // Prog "grzac grzalka czy czekac": ponizej tej ceny pradu/kWh oplaca sie grzalka,
    // powyzej — pellet tanszy. Te same stawki co cost_pellet powyzej (skalibrowane na
    // pellet 1700 zl/t + realne sprawnosci pieca w domu pasywnym).
    $t = (float)$minTemp;
    if ($t > 15)      $prog = '0.80';
    elseif ($t > 8)   $prog = '0.65';
    elseif ($t > 0)   $prog = '0.55';
    elseif ($t > -15) $prog = '0.48';
    else              $prog = '0.43';
    $db->query("INSERT INTO settings (k,v) VALUES ('pellet_prog_grzalki','{$prog}') ON DUPLICATE KEY UPDATE v='{$prog}'");
    echo date('Y-m-d H:i:s') . " | pellet_prog_grzalki={$prog} (min_temp={$minTemp}C)\n";
}

echo date('Y-m-d H:i:s') . " | energa_vs_pellet: {$rows} rows total, range={$rangeDesc}\n";
