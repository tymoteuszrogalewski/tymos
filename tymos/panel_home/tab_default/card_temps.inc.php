<?php
require_once __DIR__ . '/../../inc/chart.inc.php';
require_once __DIR__ . '/../../inc/outdoor_temps.inc.php';

// 2026-08-17: swipe po dniach ZDJETY z tej karty. Kafelki pokazuja ZAWSZE stan biezacy
// i stoja nieruchomo; po dniach swipuje sie sam wykres w rozwinietym divie —
// osobna karta card_temps_chart w kontenerze .sw-zone (patrz index/swipe.inc.js).
$today     = date('Y-m-d');
$dateParam = $today;
$isToday   = true;

$tempCien   = '--';  // device10   — temperatura w cieniu
$tempSlonce = '--';  // device1043 — temperatura w sloncu
$tempSal    = '--';
$humSal     = '--';
$tsCien = $tsSlonce = $tsSal = null;
$globalAgeSec = PHP_INT_MAX;

if ($db) {
    $db->select_db('tymos');

    if ($isToday) {
        // dzis: kafelki = ostatnie odczyty na zywo
        $res = $db->query("SELECT ts, temperature FROM device10 ORDER BY ts DESC LIMIT 1");
        if ($res && $r = $res->fetch_assoc()) { $tempCien = round((float)$r['temperature'], 1); $tsCien = $r['ts']; }
        $res = $db->query("SELECT ts, temperature FROM device1043 ORDER BY ts DESC LIMIT 1");
        if ($res && $r = $res->fetch_assoc()) { $tempSlonce = round((float)$r['temperature'], 1); $tsSlonce = $r['ts']; }
        $res = $db->query("SELECT ts, temperature, humidity FROM device684 ORDER BY ts DESC LIMIT 1");
        if ($res && $r = $res->fetch_assoc()) { $tempSal = round((float)$r['temperature'], 1); $humSal = (int)round((float)$r['humidity']); $tsSal = $r['ts']; }
        // global Zigbee freshness — czy cala siec cos pushowala ostatnio
        $res = $db->query("SELECT TIMESTAMPDIFF(SECOND, MAX(last_seen), NOW()) AS s FROM devices WHERE deleted=0 AND ip IS NULL AND last_seen IS NOT NULL");
        if ($res && $r = $res->fetch_assoc()) $globalAgeSec = (int)($r['s'] ?? PHP_INT_MAX);
    } else {
        // dzien historyczny: kafelki = srednie dobowe
        $res = $db->query("SELECT ROUND(AVG(temperature),1) AS t FROM device10 WHERE DATE(ts)='$dateParam'");
        if ($res && $r = $res->fetch_assoc()) $tempCien = $r['t'] !== null ? (float)$r['t'] : '--';
        $res = $db->query("SELECT ROUND(AVG(temperature),1) AS t FROM device1043 WHERE DATE(ts)='$dateParam'");
        if ($res && $r = $res->fetch_assoc()) $tempSlonce = $r['t'] !== null ? (float)$r['t'] : '--';
        // niezmiennik slonce >= cien (inc/outdoor_temps.inc.php); dzien zamkniety, wiec bez bramki swiezosci
        $tempSlonce = outdoor_sun_fix($tempSlonce, $tempCien);
        $res = $db->query("SELECT ROUND(AVG(temperature),1) AS t, ROUND(AVG(humidity),0) AS hu FROM device684 WHERE DATE(ts)='$dateParam'");
        if ($res && $r = $res->fetch_assoc()) { $tempSal = $r['t'] !== null ? (float)$r['t'] : '--'; $humSal = $r['hu'] !== null ? (int)$r['hu'] : '--'; }
    }

    // Dane dobowego wykresu temperatur zeszly do card_temps_chart (ladowana do .sw-zone),
    // wiec ta karta nie robi tu zadnych zapytan wykresowych.
}

const STALE_AFTER_SEC    = 7200; // 2 h — pojedynczy element nie pushowal
const ZIGBEE_DOWN_SEC    = 300;  // 5 min — cala siec milczy => Z2M/dongle podejrzane
$now = time();
$ageCien   = $tsCien   ? ($now - strtotime($tsCien))   : PHP_INT_MAX;
$ageSlonce = $tsSlonce ? ($now - strtotime($tsSlonce)) : PHP_INT_MAX;
$ageSal    = $tsSal    ? ($now - strtotime($tsSal))    : PHP_INT_MAX;

$systemDown = $globalAgeSec > ZIGBEE_DOWN_SEC;

// stale overlay tylko dla dnia biezacego (historyczne = dane zamkniete, bez ❌)
$staleCien   = $isToday && ($systemDown || $ageCien   > STALE_AFTER_SEC);
$staleSlonce = $isToday && ($systemDown || $ageSlonce > STALE_AFTER_SEC);
$staleSal    = $isToday && ($systemDown || $ageSal    > STALE_AFTER_SEC);
$staleHum    = $staleSal;

// Niezmiennik slonce >= cien (inc/outdoor_temps.inc.php). Dopiero tutaj, bo potrzebuje flag stale:
// przestarzaly cien nie moze podciagac swiezego slonca.
if ($isToday && !$staleSlonce && !$staleCien) {
    $tempSlonce = outdoor_sun_fix($tempSlonce, $tempCien);
}

// Progi komfortu salonu (kolor kafelka). Zima ideal 21,5, lato ideal ~23 — oba w zielonym (20-23,9).
// 24-25 = ciut za cieplo (pomarancz), >=26 za goraco (czerwony), <20 za zimno (niebieski).
$tSal = is_numeric($tempSal) ? (float)$tempSal : 99;
if      ($tSal >= 26.0) $tempColor = '#ff4444'; // za goraco
elseif  ($tSal >= 24.0) $tempColor = '#ffa500'; // ciut za cieplo
elseif  ($tSal <  20.0) $tempColor = '#44ccff'; // za zimno
else                    $tempColor = '#44ff44'; // komfort (20-23)

$humInt = is_numeric($humSal) ? (int)$humSal : 50;
if ($humInt < 45)       $humColor = '#C2B280';
elseif ($humInt > 55)   $humColor = '#2b9fe6';
else                    $humColor = '#44CCFF';

function stale_overlay() {
    return '<div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); font-size:44px; line-height:1; pointer-events:none;">❌</div>';
}
$dim = 'opacity:0.55; filter:grayscale(1);';

// --- CIEZKA CZESC: wykresy wody. Generowana TYLKO gdy karta rozwinieta. ---
// Dobowy wykres temperatur NIE jest tu generowany — to osobna karta card_temps_chart,
// dociagana do kontenera .sw-zone, zeby dala sie swipowac po dniach niezaleznie od kafelkow.
$waterChartDay = $waterChartMonth = '';
$dTitle = $mTitle = '';
if (card_expanded()):

// ---------------------------------------------------------------------------
// WODA CHLODNIC — dwa wykresy w rozwinieciu (pod wykresem temperatur), bez swipe:
//   1) litry per dzien miesiaca (miesiac wg wybranej daty karty)
//   2) m3 per miesiac (cala historia w klimat_ai, retencja 4 miesiace)
// klimat_ai ma 1 wiersz na minute (ai_wymienniki, cron * * * * *), wiec SUM(flaga) = minuty pracy.
// chlodnica_salon = wentylatory dev1045 (zawor dev1042 chodzi razem z nimi), nawiew = zawor dev1052.
// Przeplyw zalozony: 2 l/min kazda chlodnica (rotametr ustawiony na 2 l/min).
// ---------------------------------------------------------------------------
// define, nie const — const nie moze stac w bloku warunkowym (card_expanded)
define('WATER_LPM', 2.0);

$wMonthsPl    = [1=>'Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
$wMonthsShort = [1=>'Sty','Lut','Mar','Kwi','Maj','Cze','Lip','Sie','Wrz','Paź','Lis','Gru'];

// wykres 1 — dni wybranego miesiaca, litry
$mStart  = date('Y-m-01', strtotime($dateParam));
$mEnd    = date('Y-m-01', strtotime($mStart . ' +1 month'));
$lastDay = ($mStart === date('Y-m-01')) ? (int)date('j') : (int)date('t', strtotime($mStart));

$dCats = []; $dSalon = []; $dNawiew = [];
$dSum  = 0.0;
$perDay = [];
if ($db) {
    $res = $db->query("SELECT DAY(ts) AS d, SUM(chlodnica_salon) AS cs, SUM(nawiew) AS nv FROM klimat_ai WHERE ts >= '$mStart' AND ts < '$mEnd' GROUP BY DATE(ts)");
    if ($res) while ($r = $res->fetch_assoc()) {
        $perDay[(int)$r['d']] = [(float)$r['cs'] * WATER_LPM, (float)$r['nv'] * WATER_LPM];
    }
}
for ($d = 1; $d <= $lastDay; $d++) {
    $s = $perDay[$d][0] ?? 0.0;
    $n = $perDay[$d][1] ?? 0.0;
    $dCats[]   = (string)$d;
    $dSalon[]  = $s > 0 ? $s : null;
    $dNawiew[] = $n > 0 ? $n : null;
    $dSum += $s + $n;
}
$dTitle = 'Woda chłodnic · ' . ($wMonthsPl[(int)date('n', strtotime($mStart))] ?? '') . ' ' . date('Y', strtotime($mStart))
        . ' · ' . number_format($dSum, 0, ',', ' ') . ' l'
        . ($dSum >= 1000 ? ' (' . number_format($dSum / 1000.0, 1, ',', ' ') . ' m³)' : '')
        . ' <span style="opacity:.6">· 2 l/min każda</span>';

// wykres 2 — miesiace ktore maja dane, m3 (etykiety sumy nad slupkiem: gorny istniejacy segment)
$mCats = []; $mSalon = []; $mNawiew = []; $mLabTop = []; $mLabBase = [];
$mSum  = 0.0;
if ($db) {
    $res = $db->query("SELECT DATE_FORMAT(ts,'%Y-%m') AS m, SUM(chlodnica_salon) AS cs, SUM(nawiew) AS nv FROM klimat_ai GROUP BY m ORDER BY m ASC");
    if ($res) while ($r = $res->fetch_assoc()) {
        $s  = (float)$r['cs'] * WATER_LPM;
        $n  = (float)$r['nv'] * WATER_LPM;
        $mo = (int)substr($r['m'], 5, 2);
        $mCats[]   = ($wMonthsShort[$mo] ?? $r['m']) . ' ' . substr($r['m'], 2, 2);
        $mSalon[]  = $s > 0 ? $s / 1000.0 : null;
        $mNawiew[] = $n > 0 ? $n / 1000.0 : null;
        $lbl = ($s + $n) > 0 ? number_format(($s + $n) / 1000.0, 1, ',', ' ') : '';
        $mLabTop[]  = ($n > 0)  ? $lbl : '';
        $mLabBase[] = ($n <= 0) ? $lbl : '';
        $mSum += $s + $n;
    }
}
$mTitle = 'Woda chłodnic · per miesiąc · ' . number_format($mSum / 1000.0, 1, ',', ' ') . ' m³ łącznie';

$waterLegend = [
    ['color' => '#64b5f6', 'label' => 'Chłodnica Salon',       'type' => 'bar'],
    ['color' => '#1565c0', 'label' => 'Chłodnica Rekuperator', 'type' => 'bar'],
];
$waterMargin = ['top' => 30, 'right' => 20, 'bottom' => 28, 'left' => 48];

// dzienny: bez etykiet nad slupkami (do 31 slupkow = tlok)
$waterChartDay = chart_render([
    'width' => 800, 'height' => 260,
    'categories' => $dCats,
    'margin' => $waterMargin,
    'yaxis'  => [['color' => '#4fc3f7', 'decimals' => 0, 'grids' => 8,
                  'oy_label_above' => ['text' => 'l', 'color' => '#4fc3f7']]],
    'legend' => $waterLegend,
    'series' => [
        ['type' => 'bar', 'data' => $dSalon,  'color' => '#64b5f6', 'stack' => true, 'group' => 'w'],
        ['type' => 'bar', 'data' => $dNawiew, 'color' => '#1565c0', 'stack' => true, 'group' => 'w'],
    ],
]);

$waterChartMonth = chart_render([
    'width' => 800, 'height' => 220,
    'categories' => $mCats,
    'margin' => $waterMargin,
    'yaxis'  => [['color' => '#4fc3f7', 'decimals' => 1, 'grids' => 6,
                  'oy_label_above' => ['text' => 'm³', 'color' => '#4fc3f7']]],
    'legend' => $waterLegend,
    'series' => [
        ['type' => 'bar', 'data' => $mSalon,  'color' => '#64b5f6', 'stack' => true, 'group' => 'w',
         'labels' => $mLabBase, 'label_color' => '#9ab'],
        ['type' => 'bar', 'data' => $mNawiew, 'color' => '#1565c0', 'stack' => true, 'group' => 'w',
         'labels' => $mLabTop, 'label_color' => '#9ab'],
    ],
]);

endif; // card_expanded()
?>
<div id="temps-toggle">
<!-- PROBA 2026-08-23: kafelki zeszly na kamery (slonce/cien na parkingu, dom na dzwonku), a samo
     rozwiniecie jest dociagane z ikony "raporty" przy sekcji Ogrzewanie (card_klimat).
     Tabela jest tylko schowana, nie usunieta — wystarczy zdjac `display:none`, zeby wrocila. -->
<table style="display:none; width:100%; border-collapse:collapse; text-align:center;">
  <tr>
    <td style="width:25%; padding:6px 2px; position:relative;">
      <div style="<?= $staleSlonce ? $dim : '' ?>">
        <div style="font-size:32px; line-height:1;">🔆</div>
        <div style="font-size:21px; font-weight:300; color:#ffd633; margin-top:6px;"><?= $tempSlonce ?>°C</div>
      </div>
      <?= $staleSlonce ? stale_overlay() : '' ?>
    </td>
    <td style="width:25%; padding:6px 2px; border-left:1px solid rgba(255,255,255,0.08); position:relative;">
      <div style="<?= $staleCien ? $dim : '' ?>">
        <div style="font-size:32px; line-height:1;">☁️</div>
        <div style="font-size:21px; font-weight:300; color:#90a4ae; margin-top:6px;"><?= $tempCien ?>°C</div>
      </div>
      <?= $staleCien ? stale_overlay() : '' ?>
    </td>
    <td style="width:25%; padding:6px 2px; border-left:1px solid rgba(255,255,255,0.08); position:relative;">
      <div style="<?= $staleSal ? $dim : '' ?>">
        <div style="font-size:32px; line-height:1;">🏠</div>
        <div style="font-size:21px; font-weight:300; color:<?= $tempColor ?>; margin-top:6px;"><?= $tempSal ?>°C</div>
      </div>
      <?= $staleSal ? stale_overlay() : '' ?>
    </td>
    <td style="width:25%; padding:6px 2px; border-left:1px solid rgba(255,255,255,0.08); position:relative;">
      <div style="<?= $staleHum ? $dim : '' ?>">
        <div style="font-size:32px; line-height:1;">💧</div>
        <div style="font-size:21px; font-weight:300; color:<?= $humColor ?>; margin-top:6px;"><?= $humSal ?>%</div>
      </div>
      <?= $staleHum ? stale_overlay() : '' ?>
    </td>
  </tr>
</table>
</div>
<!-- rozwijana czesc na wlasnym tle (ten sam idiom co .therm-wrap w style.inc.css) — widac, ze to
     osobna sekcja, nie ciag dalszy kafelkow -->
<?php if (card_expanded()): ?>
<div id="temps-chart" class="sw-stop" style="margin-top:8px; padding:10px; background:rgba(255,255,255,0.04); border-radius:10px;">
  <!-- ZAKLADKI: dawne taby raportow "Bufor" i "Piec" mieszkaja teraz tutaj, pod temperaturami —
       to ten sam temat (cieplo w domu). Kazda zakladka dociaga swoja karte po kliknieciu. -->
  <div id="tc-tabs" style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin-bottom:10px;">
    <button class="tc-vbtn on" data-tc="bufor">Bufor</button>
    <button class="tc-vbtn" data-tc="temp">Temperatury</button>
    <button class="tc-vbtn" data-tc="split">Rozbicie</button>
    <button class="tc-vbtn" data-tc="pellet">Pellet</button>
  </div>
  <div id="card_tc_rep" class="sw-zone" style="position:relative;display:none;">
    <div class="card-sw"><div style="text-align:center;color:#666;font-size:12px;padding:30px 0;">…</div></div>
  </div>
  <div id="tc-temp">
  <!-- .sw-zone = wlasna strefa swipe. Swipujesz TYLKO ten wykres (dzien po dniu);
       kafelki wyzej stoja i pokazuja stan biezacy. Kontener musi miec id card_<divId>
       i .card-sw w srodku — resztą zajmuje sie index/swipe.inc.js. -->
  <div id="card_temps_chart" class="sw-zone" style="position:relative;">
    <div class="card-sw"><div style="text-align:center;color:#666;font-size:12px;padding:30px 0;">…</div></div>
  </div>
  <div style="text-align:center; font-size:12px; color:#9ab; padding:10px 0 2px;"><?= $dTitle ?></div>
  <?= $waterChartDay ?>
  <div style="text-align:center; font-size:12px; color:#9ab; padding:10px 0 2px;"><?= $mTitle ?></div>
  <?= $waterChartMonth ?>
  </div>
</div>
<?php endif; ?>

<script>
(function() {
  var tog = document.getElementById('temps-toggle');
  if (tog) {
      // Klik przeladowuje karte z/bez expand=1 — zwinieta nie generuje wykresow wcale.
      tog.onclick = function() { cardToggle('temps'); };
  }

  // Dobowy wykres: wlasna strefa swipe, 8 pozycji (7 dni temu .. dzis), start na dzis.
  var $z = $('#card_temps_chart');
  if ($z.length && !$z.data('sw-init')) {
      $z.data('sw-init', 1);
      var days = [];
      for (var i = 7; i >= 0; i--) {
          var d = new Date(); d.setDate(d.getDate() - i);
          var iso = d.getFullYear() + '-' + ('0'+(d.getMonth()+1)).slice(-2) + '-' + ('0'+d.getDate()).slice(-2);
          days.push({ panel: 'home', tab: 'default', card: 'temps_chart', params: { date: iso } });
      }
      var start = window.tempsChartIdx != null ? window.tempsChartIdx : days.length - 1;
      $z.data('swipe', days)
        .attr('data-swipe-idx', String(start))
        .attr('data-swipe-default-idx', String(days.length - 1));
      // refresh 0 — dobowy wykres nie musi sie odswiezac sam; kafelki maja wlasny cykl 30 s
      loadCard('home', 'default', 'temps_chart', 0, null, 'temps_chart', days[start].params);
  }

  // --- ZAKLADKI RAPORTOW CIEPLA (dawne taby Bufor i Piec) ---
  // Ten sam idiom co w card_pstryk_load: jedna strefa swipe `tc_rep`, lista pozycji per zakladka.
  function tcShow(list, idx) {
      var $z = $('#card_tc_rep');
      if (!$z.length) return;
      if (idx < 0) idx = 0;
      if (idx >= list.length) idx = list.length - 1;
      $z.data('swipe', list)
        .attr('data-swipe-idx', String(idx))
        .attr('data-swipe-default-idx', String(list.length - 1));
      window.swIdx = window.swIdx || {};
      window.swIdx.tc_rep = idx;
      loadCard('home', 'default', list[idx].card, 0, null, 'tc_rep', list[idx].params);
  }

  var TC = {
      // Bufor: 8 dni wstecz, start na dzis
      bufor: (function(){ var a = []; for (var i = 7; i >= 0; i--)
                  a.push({panel:'home', tab:'default', card:'bufor', params:{days_back: i}}); return a; })(),
      split: [{panel:'home', tab:'default', card:'buffer_split', params:{}}],
      // Pellet: koszty i porownanie z pradem — jedna lista pod swipe'em
      pellet: [
          {panel:'home', tab:'default', card:'pellet_month',    params:{}},
          {panel:'home', tab:'default', card:'pellet_year',     params:{}},
          {panel:'home', tab:'default', card:'heating_compare', params:{hc_view:'monthly'}},
          {panel:'home', tab:'default', card:'heating_compare', params:{hc_view:'yearly'}},
          {panel:'home', tab:'default', card:'energia_total',   params:{}}
      ]
  };
  var TC_START = { bufor: 7, split: 0, pellet: 0 };

  function tcTab(name) {
      window.tcTab = name;
      $('#tc-tabs .tc-vbtn').removeClass('on').filter('[data-tc="' + name + '"]').addClass('on');
      if (name === 'temp') { $('#tc-temp').show(); $('#card_tc_rep').hide(); return; }
      $('#tc-temp').hide();
      $('#card_tc_rep').show();
      window.tcIdx = window.tcIdx || {};
      var idx = (window.tcIdx[name] != null) ? window.tcIdx[name] : TC_START[name];
      tcShow(TC[name], idx);
  }

  $('#tc-tabs').off('click.tc').on('click.tc', '.tc-vbtn', function() { tcTab($(this).data('tc')); });
  if ($('#tc-tabs').length) tcTab(window.tcTab || 'bufor');

  // swipe.inc.js wola ten hook po gescie — zapamietaj pozycje biezacej zakladki
  var _prevOnIdx = window.swOnIdx;
  window.swOnIdx = function(divId) {
      if (typeof _prevOnIdx === 'function') _prevOnIdx(divId);
      if (divId === 'tc_rep' && window.tcTab && window.tcTab !== 'temp') {
          window.tcIdx = window.tcIdx || {};
          window.tcIdx[window.tcTab] = (window.swIdx || {}).tc_rep;
      }
  };
})();
</script>
