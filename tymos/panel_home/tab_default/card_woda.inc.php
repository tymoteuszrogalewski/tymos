<?php
// card_woda — Podlewanie i Basen wyjete z widgetu klimatu (2026-08-23) i przeniesione do PRAWEJ
// kolumny, pod kalendarz smieci. To osobne tematy od ogrzewania/chlodzenia, a przy okazji lewa
// kolumna zrobila sie za dluga. Kolejnosc: Podlewanie, Basen, Taras (lampy tarasowe), Oswietlenie (ruch w domu).
//
// Karta jest samodzielna: ma wlasny poll `klimat_state` i wlasne podswietlanie, zeby nie zalezec
// od tego, czy card_klimat akurat istnieje w layoucie.
?>
<style>
#irr-manual{display:flex;flex-direction:column;gap:2px;margin-top:2px;border-left:2px solid rgba(127,176,255,0.25);padding-left:6px}
/* PLASKI wariant przelacznika (2026-08-29): bez ramki i wypelnienia, same slowa. Wybor niesie
   sam kolor, wiec trzy wiersze mieszcza sie w miejscu, gdzie wczesniej byly dwie karty.
   `.on` = wybrany TRYB, `.cur` = stan, w ktorym urzadzenie jest teraz — w Auto podswietlaja sie
   OBA (np. AUTO + OFF), i to zastepuje kropke, ktora wczesniej oznaczala stan biezacy. */
.wsw-lbl{display:flex;align-items:center;gap:7px;flex:1;min-width:0;font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#7fb0ff}
.wsw-lbl .ic{font-size:14px}
.seg--flat{display:flex;flex:0 0 auto;width:auto;max-width:none;background:none;border-radius:0;overflow:visible;gap:15px}
.seg--flat .seg-btn{flex:0 0 auto;padding:12px 4px;font-size:12px;font-weight:600;letter-spacing:.04em;color:#6b6b6b;background:none;border-left:none}
.seg--flat .seg-btn.on,.seg--flat .seg-btn.cur{color:#4a9eff;background:none;font-weight:700}
.seg--flat .seg-btn.cur::after{display:none}
.seg--flat .seg-btn svg{display:block;width:17px;height:17px}
.wda .klm-r > #irr-gear{margin-left:9px;padding:12px 4px}
/* Wiersz musi miec ~44 px wysokosci — tyle, ile wiersz termostatu w ogrzewaniu, gdzie rozpychaja
   go przyciski +/-. Tu jest sam tekst, wiec wysokosc daje padding przyciskow: bez niego rzad miat
   24 px i palcem nie dalo sie trafic. Pole dotyku jest wieksze niz sam napis, i tak ma byc. */
.wda .klm-r{min-height:44px}
/* Puste miejsce tej samej szerokosci co zebatka — bez niego wiersze bez niej mialyby
   AUTO/ON/OFF przesuniete w prawo wzgledem podlewania. Gdy basen dostanie wlasna
   konfiguracje, `.gear-ph` podmienia sie na przycisk i nic sie nie przesuwa. */
.gear-ph{flex:0 0 24px;margin-left:9px}
</style>

<div class="wda" id="wda">
  <div class="wda-cat">

    <div class="klm-r">
      <div class="wsw-lbl"><span class="ic">💧</span>Podlewanie</div>
      <div class="seg seg--flat" data-key="irrigation_mode">
        <button class="seg-btn" data-val="auto">AUTO</button>
        <button class="seg-btn" data-val="manual">ON</button>
        <button class="seg-btn" data-val="off">OFF</button>
      </div>
      <button id="irr-gear" title="Konfiguracja podlewania"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></button>
    </div>
    <div id="irr-manual" style="display:none">
    <?php
      $__zr = $db->query("SELECT name, dev_channel FROM irrigation_zones WHERE dev_channel IS NOT NULL AND enabled=1 ORDER BY sort_order, id");
      if ($__zr) while ($__z = $__zr->fetch_assoc()):
    ?>
      <div class="klm-r"><div class="klm-lbl klm-sec"><?= htmlspecialchars($__z['name']) ?></div><div class="seg" data-manch="<?= htmlspecialchars($__z['dev_channel'], ENT_QUOTES) ?>">
        <button class="seg-btn" data-mact="on">ON</button>
        <button class="seg-btn" data-mact="off">OFF</button>
      </div></div>
    <?php endwhile; ?>
    </div>
    <!-- #irr-cfg-in osobno, bo .html() na samym #irr-cfg zmiotloby X okna (winShow) -->
    <div id="irr-cfg" style="display:none"><div id="irr-cfg-in"></div></div>

    <div class="klm-r">
      <div class="wsw-lbl"><span class="ic">🏊</span>Basen</div>
      <div class="seg seg--flat" data-key="force_basen">
        <button class="seg-btn" data-val="auto">AUTO</button>
        <button class="seg-btn" data-val="on" data-state="on">ON</button>
        <button class="seg-btn" data-val="off" data-state="off">OFF</button>
      </div>
      <span class="gear-ph"></span>
    </div>

    <div class="klm-r">
      <div class="wsw-lbl"><span class="ic">🏮</span>Taras</div>
      <div class="seg seg--flat" data-key="force_basen_swiatlo">
        <button class="seg-btn" data-val="auto">AUTO</button>
        <button class="seg-btn" data-val="on" data-state="on">ON</button>
        <button class="seg-btn" data-val="off" data-state="off">OFF</button>
      </div>
      <span class="gear-ph"></span>
    </div>

    <!-- SWIATLA NA RUCH w domu (2026-09-07, user): AUTO = normalnie; ON = swiatla zapalone ruchem NIE GASNA
         (majsterkowanie na drabinie daleko od czujki / nad czujka); OFF = ruch NIE ZAPALA swiatel, gaszenie
         dziala normalnie. Realizacja: helper swiatla_ruch_mode + warunki 'helper' w akcjach 4/12/14/18/20
         (wlaczanie, != off) i 11/13/15/17/19 (gaszenie, != on). Tryb trzyma do recznej zmiany, bez
         automatycznego powrotu. Garaz (kontaktron) i swiatla zewnetrzne (kamery) NIE podlegaja. -->
    <div class="klm-r">
      <div class="wsw-lbl"><span class="ic">💡</span>Oświetlenie</div>
      <div class="seg seg--flat" data-key="swiatla_ruch_mode">
        <button class="seg-btn" data-val="auto">AUTO</button>
        <button class="seg-btn" data-val="on">ON</button>
        <button class="seg-btn" data-val="off">OFF</button>
      </div>
      <span class="gear-ph"></span>
    </div>

  </div>
</div>

<script>
(function(){
  var $w = $('#wda');
  if (!$w.length || $w.data('init')) return;
  $w.data('init', 1);

  $w.on('click', '.seg-btn', function(){
    var $b = $(this), $seg = $b.closest('.seg'), key = $seg.attr('data-key'), val = $b.attr('data-val');
    if (!key || val == null) return;
    $seg.find('.seg-btn').removeClass('on cur');
    $b.addClass('on');
    // Strefy MANUAL pokazujemy i chowamy OD RAZU. Sa juz w DOM — renderuje je PHP przy ladowaniu
    // karty — wiec nie ma na co czekac. Wczesniej widocznosc ustawial dopiero poll po 1,2 s
    // i klikniecie wygladalo, jakby nic sie nie stalo. Poll i tak przyjdzie i uzupelni stan
    // przyciskow w strefach; gdy zapis padnie, cofnie tez samo pokazanie.
    if (key === 'irrigation_mode') $('#irr-manual').toggle(val === 'manual');
    $.post('api.php', {action: 'klimat_set', key: key, value: val}, function(){ setTimeout(poll, 1200); }, 'json');
  });

  // Sekcje MANUAL podlewania: reczne ON/OFF, jeden zawor naraz (irrigation_manual_set)
  $w.on('click', '#irr-manual .seg-btn', function(){
    var $b = $(this), ch = $b.closest('.seg').attr('data-manch'), act = $b.attr('data-mact');
    $b.closest('.seg').find('.seg-btn').removeClass('on');
    $b.addClass('on');
    $.post('api.php', {action: 'irrigation_manual_set', channel: ch, mact: act}, function(){ setTimeout(poll, 1200); }, 'json');
  });

  // KONFIGURACJA PODLEWANIA — dociagana z cnt.php dopiero po kliknieciu w zebatke; od 2026-09-04
  // w OKNIE nad panelem (winShow, cards.inc.js). Zamkniecie okna przeladowuje CALA karte, bo lista
  // sekcji MANUAL jest renderowana PHP-em i po dodaniu/usunieciu strefy musi powstac od nowa.
  $w.on('click', '#irr-gear', function(){
    var $box = $('#irr-cfg'), $in = $('#irr-cfg-in');
    $(this).addClass('on');
    winShow($box[0], function(){ loadCard('home', 'default', 'woda', 0, 'fade'); });
    if (!$box.data('loaded')) {
      $box.data('loaded', 1);
      $in.html('<div style="padding:10px 2px;color:#777;font-size:13px">Ładowanie…</div>');
      $.get('cnt.php?panel=home&tab=default&card=irrigation_cfg&_=' + Date.now(), function(html){
        $in.html(html);
        execScripts($in[0]);
      });
    }
  });

  function grp(key){ return $w.find('.seg[data-key="' + key + '"] .seg-btn'); }
  function hlMode(key, modeVal, actual){
    var $g = grp(key); $g.removeClass('on cur');
    $g.filter('[data-val="' + modeVal + '"]').addClass('on');
    if (actual != null) $g.filter('[data-state="' + actual + '"]').addClass('cur');
  }
  function hlToggle(key, cur){ var $g = grp(key); $g.removeClass('on cur'); $g.filter('[data-val="' + cur + '"]').addClass('on'); }
  function hlManual(zones){
    (zones || []).forEach(function(z){
      var $seg = $('#irr-manual .seg[data-manch="' + z.channel + '"]');
      if (!$seg.length) return;
      $seg.find('.seg-btn').removeClass('on');
      $seg.find('[data-mact="' + (z.on ? 'on' : 'off') + '"]').addClass('on');
    });
  }

  function poll(){
    if (!$('#wda').length) return;
    $.getJSON('api.php?action=klimat_state', function(d){
      if (!d || !d.ok) return;
      hlMode('force_basen', d.force_basen, d.basen_on ? 'on' : 'off');
      hlMode('force_basen_swiatlo', d.force_basen_swiatlo, d.basen_swiatlo_on ? 'on' : 'off');
      hlToggle('swiatla_ruch_mode', d.swiatla_ruch_mode || 'auto');
      var _im = d.irrigation_mode || 'off';
      hlToggle('irrigation_mode', _im);
      if (_im === 'manual') { $('#irr-manual').show(); hlManual(d.irrigation_zones); }
      else { $('#irr-manual').hide(); }
    });
  }
  poll();
  if (window.wdaPollTimer) clearInterval(window.wdaPollTimer);
  window.wdaPollTimer = setInterval(poll, 10000);
  if (typeof cardOnUnload === 'function') cardOnUnload(function() { clearInterval(window.wdaPollTimer); window.wdaPollTimer = null; });
})();
</script>
