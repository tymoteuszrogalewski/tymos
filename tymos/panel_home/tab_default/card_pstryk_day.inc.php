<?php
/**
 * card_pstryk_day — moc per faza dla calej doby, 288 slotow po 5 min, MAX (bez sredniej).
 * Swipe po dniach (?date=YYYY-MM-DD), niezaleznie od okna 2,5 h w card_pstryk_live.
 */
require_once __DIR__ . '/../../inc/phase_power_chart.inc.php';

const DAY_SLOTS = 288;
const DAY_STEP  = 300;

$today     = date('Y-m-d');
$dateParam = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']))
             ? $_GET['date'] : $today;
if ($dateParam > $today) $dateParam = $today;

$db->select_db('tymos');

$t0   = strtotime($dateParam . ' 00:00:00');
$cats = [];
for ($s = 0; $s < DAY_SLOTS; $s++) $cats[] = ($s % 12 === 0) ? (string)intdiv($s, 12) : '';

[$l1, $l2, $l3] = ppc_phase_data($db, $t0, DAY_STEP, DAY_SLOTS);
[$devSeries, ]  = ppc_dev_series($db, $t0, DAY_STEP, DAY_SLOTS);

$when = 'dziś';
if ($dateParam !== $today) {
    $diff = (int)round((strtotime($today) - strtotime($dateParam)) / 86400);
    if ($diff === 1)      $when = 'wczoraj';
    elseif ($diff === 2)  $when = 'przedwczoraj';
    else                  $when = $diff . ' dni temu';
    $when .= ' · ' . date('d.m', strtotime($dateParam));
}
?>
<div style="text-align:center; font-size:12px; color:#9ab; padding:10px 0 4px;">
  Moc <?= $when ?> · L1/L2/L3 <span style="opacity:.6">· max per 5 min</span>
</div>
<?= ppc_render($cats, $l1, $l2, $l3, $devSeries, $PPC_PHASE_LEGEND) ?>
