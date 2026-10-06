<?php
/**
 * card_pstryk_live — moc chwilowa per faza, okno 2,5 h, JEDNA PROBKA = JEDEN PIKSEL.
 *
 * `?off=N` = ile MINUT wstecz konczy sie okno. off=0 = teraz (jedyne okno, ktore ma sens odswiezac).
 *
 * Dlaczego minuty, a nie numer okna: okno ma 2,5 h, a 24 h sie przez to nie dzieli (9,6 okna),
 * wiec przyciski „−24 h / +24 h" nigdy nie trafialyby w te sama godzine. Offset w minutach rozdziela
 * SZEROKOSC okna (2,5 h, wymuszona przez 900 px = 900 probek) od KROKU przesuwania (2 h w swipe,
 * 24 h w przyciskach). Sasiednie pozycje swipe zachodza wtedy na siebie o 30 min — nic sie nie gubi na szwie.
 */
require_once __DIR__ . '/../../inc/phase_power_chart.inc.php';

const LIVE_MAX_OFF = 10080;   // minut = 7 dni wstecz (tyle, co swipe po dniach nizej)

$off  = max(0, min(LIVE_MAX_OFF, (int)($_GET['off'] ?? 0)));
$win  = PPC_SLOTS * PPC_STEP;              // 9 000 s = 2,5 h
$end  = time() - $off * 60;
$t0   = $end - $win;

$db->select_db('tymos');

$cats = [];
for ($s = 0; $s < PPC_SLOTS; $s++) {
    $tt = $t0 + $s * PPC_STEP;
    // etykieta na kazdej pelnej polgodzinie — jeden slot 10 s trafia w granice dokladnie raz
    $cats[] = ((int)date('i', $tt) % 30 === 0 && (int)date('s', $tt) < PPC_STEP) ? date('G:i', $tt) : '';
}

[$l1, $l2, $l3] = ppc_phase_data($db, $t0, PPC_STEP, PPC_SLOTS);
[$devSeries, $devLegend] = ppc_dev_series($db, $t0, PPC_STEP, PPC_SLOTS);

$range = date('G:i', $t0) . '–' . date('G:i', $end);
if (date('Y-m-d', $t0) !== date('Y-m-d')) $range = date('d.m', $t0) . ' ' . $range;
$when  = $off === 0 ? 'ostatnie 2,5 h' : $range;
?>
<div style="text-align:center; font-size:12px; color:#9ab; padding:2px 0 4px;">
  Moc chwilowa · L1/L2/L3 · <?= $when ?> <span style="opacity:.6">· 1 próbka = 1 px</span>
</div>
<div style="text-align:center; font-size:11px; padding:0 0 4px;">Progi na fazę: <?= pwr_limit_note() ?></div>
<?= ppc_render($cats, $l1, $l2, $l3, $devSeries, array_merge($PPC_PHASE_LEGEND, [['type' => 'newline']], $devLegend)) ?>
