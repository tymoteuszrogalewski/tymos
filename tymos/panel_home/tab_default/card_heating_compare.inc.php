<?php
require_once __DIR__ . '/../../config.inc.php';
require_once __DIR__ . '/../../inc/chart.inc.php';

$db->select_db('tymos');

$view = $_GET['hc_view'] ?? 'monthly';

// SZACUNEK PELLETU — zawsze wg biezacej ceny SKLEPOWEJ, nie ceny posiadanego zapasu.
// cost_pellet w energa_vs_pellet jest policzony dla PELLET_CENA_BAZOWA (1700 zl/t).
// Sprawnosc pieca nie zalezy od ceny paliwa, wiec skalowanie jest liniowe.
$rRy = $db->query("SELECT v FROM settings WHERE k='pellet_cena_rynek' LIMIT 1");
$pellet_rynek = $rRy && ($row = $rRy->fetch_assoc()) ? (float)$row['v'] : 2450;
$pellet_mult  = $pellet_rynek / PELLET_CENA_BAZOWA;

if ($view === 'yearly') {
    // --- Ostatnie 12 miesiecy ---
    // Premium G11f ryczalt vs G11 ryczalt = (55.51 - 11.70) * 1.23 brutto/mc.
    // To koszt dynamicznej taryfy Pstryk; rozdzielany na kWh proporcjonalnie do udzialu
    // grzalek w calkowitym zuzyciu domu (dom_kwh z energa.kwh_pstryk).
    $abo_g11f_premium = (55.51 - 11.70) * 1.23;
    $months_pl = ['01'=>'sty','02'=>'lut','03'=>'mar','04'=>'kwi','05'=>'maj','06'=>'cze',
                  '07'=>'lip','08'=>'sie','09'=>'wrz','10'=>'paź','11'=>'lis','12'=>'gru'];
    $res = $db->query("
        SELECT DATE_FORMAT(t.ts, '%Y-%m') AS m,
               SUM(t.kwh) AS kwh,
               SUM(t.cost_electric) AS ce,
               SUM(t.cost_pellet) AS cp,
               (SELECT SUM(kwh_pstryk) FROM energa
                WHERE DATE_FORMAT(ts, '%Y-%m') = DATE_FORMAT(t.ts, '%Y-%m')) AS dom_kwh
        FROM energa_vs_pellet t
        WHERE t.ts >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01')
        GROUP BY DATE_FORMAT(t.ts, '%Y-%m')
        ORDER BY m ASC
    ");
    $cats = []; $ce_d = []; $cp_d = []; $labels_e = []; $labels_p = [];
    while ($r = $res->fetch_assoc()) {
        $mo = substr($r['m'], 5, 2);
        $cats[]  = $months_pl[$mo] ?? $mo;
        $ce = (float)$r['ce']; $cp = (float)$r['cp'] * $pellet_mult; $kwh = (float)$r['kwh'];
        $dom_kwh = (float)($r['dom_kwh'] ?? 0);
        // Biezacy miesiac: ekstrapolacja dom_kwh na pelny miesiac (inaczej abo_share zawyzony)
        if ($r['m'] === date('Y-m')) {
            $dp = (int)date('j'); $dim = (int)date('t');
            if ($dp > 0) $dom_kwh = $dom_kwh * $dim / $dp;
        }
        $abo_share = $dom_kwh > 0 ? $abo_g11f_premium * ($kwh / $dom_kwh) : 0;
        $ce_eff = $ce + $abo_share;
        $ce_d[]  = $ce_eff;
        $cp_d[]  = $cp;
        $avg_per_kwh = $kwh > 0 ? $ce_eff / $kwh : 0;
        $labels_e[] = number_format($ce_eff, 0) . "\npln\n" . number_format($avg_per_kwh, 2) . "\n/kWh";
        $labels_p[] = number_format($cp, 0) . ' zł';
    }
    $title = 'Koszt grzania bufora · miesiące <span style="opacity:.6;font-size:.75em">(zawiera aboG11f)</span>';
    $x_label = '';
} else {
    // --- Bieżący miesiąc ---
    // Premium G11f rozkladane na dni proporcjonalnie do udzialu kwh dnia w calym
    // miesiecznym zuzyciu domu — suma dni daje to samo co yearly view dla tego miesiaca.
    $abo_g11f_premium = (55.51 - 11.70) * 1.23;
    $r0 = $db->query("SELECT SUM(kwh_pstryk) AS dom_kwh FROM energa
                      WHERE DATE_FORMAT(ts, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')")->fetch_assoc();
    $dom_kwh_month = (float)($r0['dom_kwh'] ?? 0);

    $daysInMonth = (int)date('t');
    // Ekstrapolacja mianownika na pelny miesiac — inaczej w trakcie miesiaca
    // abo_share dnia jest zawyzony (bo dom_kwh_month obejmuje tylko dni do teraz)
    $daysPassed = (int)date('j');
    if ($daysPassed > 0) {
        $dom_kwh_month = $dom_kwh_month * $daysInMonth / $daysPassed;
    }
    $cats    = array_map('strval', range(1, $daysInMonth));
    $ce_d    = array_fill(0, $daysInMonth, null);
    $cp_d    = array_fill(0, $daysInMonth, null);
    $labels_e = array_fill(0, $daysInMonth, '');
    $labels_p = array_fill(0, $daysInMonth, '');
    $res = $db->query("
        SELECT DAY(ts) AS d,
               SUM(kwh) AS kwh,
               SUM(cost_electric) AS ce,
               SUM(cost_pellet) AS cp
        FROM energa_vs_pellet
        WHERE DATE_FORMAT(ts, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
        GROUP BY DATE(ts)
        ORDER BY DATE(ts) ASC
    ");
    while ($r = $res->fetch_assoc()) {
        $d = (int)$r['d'] - 1;
        $ce = (float)$r['ce']; $cp = (float)$r['cp'] * $pellet_mult; $kwh = (float)$r['kwh'];
        $abo_share = $dom_kwh_month > 0 ? $abo_g11f_premium * ($kwh / $dom_kwh_month) : 0;
        $ce_d[$d]  = $ce + $abo_share;
        $cp_d[$d]  = $cp;
    }
    $title = 'Koszt grzania bufora · dni <span style="opacity:.6;font-size:.75em">(zawiera aboG11f)</span>';
}

// Temperatura zewnetrzna z tym.device_temp_humid.taras_temp
$temp_d = ($view === 'yearly') ? [] : array_fill(0, $daysInMonth, null);

if ($view === 'yearly') {
    $res = $db->query("
        SELECT DATE_FORMAT(ts, '%Y-%m') AS m,
               ROUND(AVG(temperature), 1) AS t
        FROM device10
        WHERE ts >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01')
          AND temperature IS NOT NULL
        GROUP BY DATE_FORMAT(ts, '%Y-%m')
        ORDER BY m ASC
    ");
    $temp_map = [];
    while ($r = $res->fetch_assoc()) $temp_map[$r['m']] = (float)$r['t'];
    $res2 = $db->query("
        SELECT DATE_FORMAT(ts, '%Y-%m') AS m FROM energa_vs_pellet
        WHERE ts >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01')
        GROUP BY DATE_FORMAT(ts, '%Y-%m') ORDER BY m ASC
    ");
    while ($r2 = $res2->fetch_assoc()) {
        $temp_d[] = $temp_map[$r2['m']] ?? null;
    }
} else {
    $res = $db->query("
        SELECT DAY(ts) AS d,
               ROUND(AVG(temperature), 1) AS t
        FROM device10
        WHERE DATE_FORMAT(ts, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
          AND temperature IS NOT NULL
        GROUP BY DATE(ts)
        ORDER BY d ASC
    ");
    while ($r = $res->fetch_assoc()) {
        $d = (int)$r['d'] - 1;
        if ($d < $daysInMonth) $temp_d[$d] = (float)$r['t'];
    }
}

$db->select_db('tymos');
?>
<div class="card-title"><?= $title ?></div>
<?= chart_render([
    'width'      => 800,
    'categories' => $cats,
    'yaxis'      => [
        ['color' => '#cccccc', 'oy_label_below' => ['text' => 'PLN', 'color' => '#cccccc']],
        ['color' => '#f44336', 'side' => 'right', 'oy_label_below' => ['text' => '°C', 'color' => '#f44336']],
    ],
    'legend' => [
        ['color' => '#1565c0', 'label' => 'Grzałki (Pstryk)', 'type' => 'bar'],
        ['color' => '#ff6600', 'label' => 'Pellet · szacunek wg ' . number_format($pellet_rynek, 0, ',', ' ') . ' zł/t', 'type' => 'line', 'line_width' => 2.2, 'dash' => 3],
        ['color' => '#f44336', 'label' => 'Temperatura zewnętrzna', 'type' => 'line', 'line_width' => 1],
    ],
    'series' => [
        ['type' => 'bar', 'data' => $ce_d, 'color' => '#1565c0', 'labels' => $labels_e, 'label_color' => '#42a5f5'],
        ['type' => 'line', 'data' => $cp_d, 'color' => '#ff6600', 'line_width' => 4, 'dash' => 8],
        ['type' => 'line', 'data' => $temp_d, 'color' => '#f44336', 'yaxis' => 1, 'null_gap' => true, 'line_width' => 1],
    ],
]) ?>
