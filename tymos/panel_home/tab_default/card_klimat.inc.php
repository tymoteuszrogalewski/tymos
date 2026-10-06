<?php
// Widget sterowania klimatem/basenem (panel_heat, pod schematem reku).
// Kazda funkcja: Auto + reczny override. Segmentowane kontrolki, kategorie jako karty.
// Podswietlenie: wybrany tryb = pelny niebieski (.on); w Auto aktualny stan = kropka (.cur).
if ($db) { $db->select_db('tymos'); }
?>
<style>
.therm{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex:0 0 auto;width:340px;max-width:62%}
.therm-cur{color:#8a8a8a;font-size:12px;min-width:46px;text-align:right}
.therm-btn{width:36px;height:34px;border:none;border-radius:8px;background:#262626;color:#dcdcdc;font-size:20px;line-height:1;cursor:pointer;-webkit-tap-highlight-color:transparent}
.therm-btn:active{background:#3a3a3a}
.therm-tgt{min-width:46px;text-align:center;font-size:15px;font-weight:600;color:#fff}
</style>

<div class="klm" id="klm">

  <?php include __DIR__ . '/card_reku_flow.inc.php'; ?>

  <div class="klm-cat">
    <div class="klm-cat-h"><span class="ic">🔥</span>Ogrzewanie
      <button id="piec-rap" title="Wykresy: temperatury, bufor, pellet">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 20h18"/><rect x="5" y="12" width="3.4" height="6"/><rect x="10.3" y="8" width="3.4" height="10"/><rect x="15.6" y="4" width="3.4" height="14"/></svg>
      </button>
      <button id="piec-gear" title="Dosypanie pelletu" style="margin-left:12px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      </button>
    </div>
    <!-- Nastawy trybu (sezon, piec, podlogowka) schowane pod gear 2026-08-29: to rzeczy
         ustawiane raz na sezon, a zajmowaly trzy wiersze nad termostatami, ktore rusza sie
         codziennie. Siedza na GORZE panelu, nad dosypaniem pelletu. -->
    <div id="piec-cfg" style="display:none">
    <div class="klm-r"><div class="klm-lbl">Sezon</div><div class="seg" data-key="tryb_klimatu">
      <button class="seg-btn" data-val="grzej">Grzanie</button>
      <button class="seg-btn" data-val="chlodz">Chłodzenie</button>
      <button class="seg-btn" data-val="off">OFF</button>
    </div></div>
    <div class="klm-sub" id="sezon-sub">&nbsp;</div>
    <div class="klm-r"><div class="klm-lbl">Piec pellet</div><div class="seg" data-key="piec_wlaczony">
      <button class="seg-btn" data-val="1">ON</button>
      <button class="seg-btn" data-val="0">OFF</button>
    </div></div>
    <div class="klm-sub">Grzałki tylko wsparciem</div>
    <div class="klm-r"><div class="klm-lbl">Podłogówka</div><div class="seg" data-key="podlogowka_wlaczona">
      <button class="seg-btn" data-val="1">ON</button>
      <button class="seg-btn" data-val="0">OFF</button>
    </div></div>
      <div id="piec-cfg-box"></div>
    </div>
    <!-- #piec-rap-in osobno, bo .html() na samym #piec-rap-box zmiotloby X okna (winShow) -->
    <div id="piec-rap-box" style="display:none"><div id="piec-rap-in"></div></div>
    <div class="klm-r"><div class="klm-lbl">Salon</div><div class="therm" data-therm="salon" data-min="18" data-max="25">
      <span class="therm-cur" id="th-salon-cur">–°</span>
      <button class="therm-btn" data-d="-0.5">−</button>
      <span class="therm-tgt" id="th-salon-tgt">–</span>
      <button class="therm-btn" data-d="0.5">+</button>
    </div></div>
    <div class="klm-r"><div class="klm-lbl">Liwia</div><div class="therm" data-therm="liwia" data-min="18" data-max="24">
      <span class="therm-cur" id="th-liwia-cur">–°</span>
      <button class="therm-btn" data-d="-0.5">−</button>
      <span class="therm-tgt" id="th-liwia-tgt">–</span>
      <button class="therm-btn" data-d="0.5">+</button>
    </div></div>
  </div>

</div>

<script>
(function(){
  var $k = $('#klm');
  if (!$k.length || $k.data('init')) return;
  $k.data('init', 1);

  $k.on('click', '.seg-btn', function(){
    var $b = $(this);
    if ($b.prop('disabled')) return;
    var $seg = $b.closest('.seg'), key = $seg.attr('data-key'), val = $b.attr('data-val');
    if (!key || val == null) return;
    // optymistycznie: w tej grupie podswietl klikniety (do czasu poll)
    $seg.find('.seg-btn').removeClass('on cur');
    $b.addClass('on');
    if (key === 'tryb_klimatu') sezonSub(val);
    $.post('api.php', {action: 'klimat_set', key: key, value: val}, function(){ setTimeout(poll, 1200); }, 'json');
  });

  // DOSYPANIE PELLETU — formularz z dawnego taba Piec, dociagany zebatka przy "Ogrzewanie".
  // To jedyna rzecz z tamtego taba, ktora jest AKCJA, a nie raportem; raporty poszly pod
  // temperatury (card_temps, zakladka Pellet).
  // Od 2026-09-04 nastawy otwieraja sie w OKNIE nad panelem (winShow, cards.inc.js); element
  // zostaje w karcie, wiec delegowane handlery .seg-btn i hlMode() dzialaja bez zmian.
  $k.on('click', '#piec-gear', function(){
    var $wrap = $('#piec-cfg'), btn = this;
    $(btn).addClass('on');
    winShow($wrap[0], function(){ $(btn).removeClass('on'); });
    // Nastawy sa w `#piec-cfg` na stale; doladowywane jest tylko dosypanie pelletu, wiec
    // leci do osobnego `#piec-cfg-box` — inaczej .html() zdmuchnaloby wiersze nad nim.
    var $box = $('#piec-cfg-box');
    if (!$box.data('loaded')) {
      $box.data('loaded', 1).html('<div style="padding:10px 2px;color:#777;font-size:13px">Ładowanie…</div>');
      $.get('cnt.php?panel=home&tab=default&card=pellet_add&_=' + Date.now(), function(html){
        $box.html(html);
        execScripts($box[0]);
      });
    }
  });

  // RAPORTY CIEPLA — ikona wykresu przy "Ogrzewanie" dociaga ROZWINIECIE karty `temps`
  // (`expand=1`): zakladki Temperatury | Bufor | Rozbicie | Pellet + wykresy wody chlodnic.
  // Kafelki temperatur w tamtej karcie sa schowane (display:none), bo zeszly na kamery,
  // wiec przychodzi tu sama czesc raportowa. Ten sam idiom co #piec-gear wyzej.
  $k.on('click', '#piec-rap', function(){
    var $box = $('#piec-rap-box'), $in = $('#piec-rap-in'), btn = this;
    $(btn).addClass('on');
    winShow($box[0], function(){ $(btn).removeClass('on'); });
    if (!$box.data('loaded')) {
      $box.data('loaded', 1);
      $in.html('<div style="padding:10px 2px;color:#777;font-size:13px">Ładowanie…</div>');
      $.get('cnt.php?panel=home&tab=default&card=temps&expand=1&_=' + Date.now(), function(html){
        $in.html(html);
        execScripts($in[0]);
      });
    }
  });

  // Termostaty: +/- docelowej (helper salon_termostat/liwia_termostat), z limitami per pokoj
  $k.on('click', '.therm-btn', function(){
    var $t = $(this).closest('.therm'), ent = $t.attr('data-therm');
    var mn = parseFloat($t.attr('data-min')), mx = parseFloat($t.attr('data-max'));
    var $tgt = $t.find('.therm-tgt'), cur = parseFloat($tgt.text().replace(',', '.'));
    if (isNaN(cur)) return;
    var v = Math.round((cur + parseFloat($(this).attr('data-d'))) * 10) / 10;
    if (v < mn || v > mx) return;
    $tgt.text(v.toFixed(1).replace('.', ','));
    $.post('api.php', {action: 'termostat_set', entity: ent, value: v});
  });

  // Opis pod Sezonem: co ten przelacznik realnie bramkuje. Sezon ustawia sie raz na pol roku,
  // wiec po miesiacach nikt nie pamieta, ktore automaty on gasi — linijka mowi to wprost.
  var SEZON_OPIS = {
    grzej:  'Nagrzewnica salonu grzeje; reku na AUTO, chłodnice i roleta na słońce wyłączone',
    chlodz: 'Reku chłodzi i wietrzy, chłodnice i free-cool pracują, roleta zasłania słońce; nagrzewnica OFF',
    off:    'Nic nie steruje klimatem: reku na AUTO, chłodnice i nagrzewnica OFF'
  };
  function sezonSub(val){ $('#sezon-sub').text(SEZON_OPIS[val] || ''); }

  function grp(key){ return $k.find('.seg[data-key="' + key + '"] .seg-btn'); }
  // Toggle bez Auto (piec/podlogowka/podlewanie): tylko wybrany=.on.
  function hlToggle(key, cur){ var $g = grp(key); $g.removeClass('on cur'); $g.filter('[data-val="' + cur + '"]').addClass('on'); }
  function poll(){
    if (!$('#klm').length) return;
    ['salon', 'liwia'].forEach(function(ent){
      $.getJSON('api.php?action=termostat_state&entity=' + ent, function(d){
        if (!d) return;
        if (d.temp   != null && !isNaN(parseFloat(d.temp))) $('#th-' + ent + '-cur').text(parseFloat(d.temp).toFixed(1).replace('.', ',') + '°');
        if (d.target != null) $('#th-' + ent + '-tgt').text(parseFloat(d.target).toFixed(1).replace('.', ','));
      });
    });
    $.getJSON('api.php?action=klimat_state', function(d){
      if (!d || !d.ok) return;
      hlToggle('tryb_klimatu', d.tryb_klimatu);
      sezonSub(d.tryb_klimatu);
      hlToggle('piec_wlaczony', d.piec);
      hlToggle('podlogowka_wlaczona', d.podlogowka);
    });
  }
  poll();
  if (window.klmPollTimer) clearInterval(window.klmPollTimer);
  window.klmPollTimer = setInterval(poll, 10000);
  if (typeof cardOnUnload === 'function') cardOnUnload(function() { clearInterval(window.klmPollTimer); window.klmPollTimer = null; });
})();
</script>
