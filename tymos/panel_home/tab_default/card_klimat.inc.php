<?php
// Widget sterowania klimatem/basenem (panel_heat). Od 2026-10-18 JEDNA karta: diagram reku,
// pod nim jeden wiersz z oboma termostatami i zebatka nastaw (bez naglowka Ogrzewanie). Raporty
// ciepla sa zakladkami okna wykresow reku (klik w wymiennik, card_reku_flow).
// Kazda funkcja: Auto + reczny override. Segmentowane kontrolki, kategorie jako karty.
// Podswietlenie: wybrany tryb = pelny niebieski (.on); w Auto aktualny stan = kropka (.cur).
if ($db) { $db->select_db('tymos'); }
?>
<style>
/* Diagram reku wchodzi do tej samej karty, wiec traci wlasna ramke. */
.klm-one{padding:8px 10px 6px}
.klm-one > .rkf{background:none;border:none;padding:0;border-radius:0}
/* Oba termostaty w JEDNYM wierszu, po polowie. Przyciski +/- zostaja 36x34 jak wszedzie;
   miejsce daje nazwa nad biezaca temperatura (dwie linijki). */
.th2{display:flex;align-items:center;gap:10px;border-top:1px solid #2a2a2a;margin-top:2px;padding-top:6px}
.th2 .therm{display:flex;align-items:center;gap:6px;flex:1;min-width:0}
.th2-n{flex:1;display:flex;flex-direction:column;line-height:1.2;min-width:0}
.th2-n b{font-size:14px;font-weight:400;color:#ddd}
.therm-cur{color:#8a8a8a;font-size:12px}
.th2 #piec-gear{margin-left:0}
.therm-btn{width:36px;height:34px;border:none;border-radius:8px;background:#262626;color:#dcdcdc;font-size:20px;line-height:1;cursor:pointer;-webkit-tap-highlight-color:transparent}
.therm-btn:active{background:#3a3a3a}
.therm-tgt{min-width:40px;text-align:center;font-size:15px;font-weight:600;color:#fff}
</style>

<div class="klm" id="klm">

  <div class="klm-cat klm-one">
  <?php include __DIR__ . '/card_reku_flow.inc.php'; ?>

  <div class="th2">
    <div class="therm" data-therm="salon" data-min="18" data-max="25">
      <div class="th2-n"><b>Salon</b><span class="therm-cur" id="th-salon-cur">–°</span></div>
      <button class="therm-btn" data-d="-0.5">−</button>
      <span class="therm-tgt" id="th-salon-tgt">–</span>
      <button class="therm-btn" data-d="0.5">+</button>
    </div>
    <div class="therm" data-therm="liwia" data-min="18" data-max="24">
      <div class="th2-n"><b>Liwia</b><span class="therm-cur" id="th-liwia-cur">–°</span></div>
      <button class="therm-btn" data-d="-0.5">−</button>
      <span class="therm-tgt" id="th-liwia-tgt">–</span>
      <button class="therm-btn" data-d="0.5">+</button>
    </div>
    <button id="piec-gear" title="Dosypanie pelletu">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </button>
  </div>

    <!-- Nastawy trybu (sezon, piec) schowane pod gear 2026-08-29: to rzeczy
         ustawiane raz na sezon, a zajmowaly trzy wiersze nad termostatami, ktore rusza sie
         codziennie. Siedza na GORZE panelu, nad dosypaniem pelletu. Podlogowka przeszla
         2026-10-18 do rzedu ikon reku (card_reku_flow). -->
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
      <div id="piec-cfg-box"></div>
    </div>
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
  // Toggle bez Auto (piec/podlewanie): tylko wybrany=.on.
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
    });
  }
  poll();
  if (window.klmPollTimer) clearInterval(window.klmPollTimer);
  window.klmPollTimer = setInterval(poll, 10000);
  if (typeof cardOnUnload === 'function') cardOnUnload(function() { clearInterval(window.klmPollTimer); window.klmPollTimer = null; });
})();
</script>
