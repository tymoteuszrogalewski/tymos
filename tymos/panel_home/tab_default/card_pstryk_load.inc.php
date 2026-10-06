<?php
/**
 * card_pstryk_load — rozwiniecie karty `pstryk` (klik w wykres cen). OSOBNA karta w layoucie,
 * tuz pod `pstryk` — patrz index.inc.php. Nie div w srodku tamtej karty, bo swipe wymienia CALA
 * zawartosc karty i rozwiniecie jechaloby razem z wykresem cen, a potem znikalo.
 * Od 2026-09-04 karta pokazuje sie w OKNIE nad panelem (winShow w card_pstryk), a w kolumnie
 * stoi schowana — winShow/winHide steruja jej display, tu tylko pilnujemy stanu zamknietego.
 *
 * Sam nic nie rysuje. Trzyma dwa NIEZALEZNE kontenery swipe:
 *   #card_pstryk_live — okno 2,5 h, swipe co 2 h wstecz (?off=minuty)
 *   #card_pstryk_day  — cala doba, swipe po dniach (?date=)
 * Przyciski ±24 h / Teraz przesuwaja OBA kontenery naraz; swipe dalej rusza kazdy z osobna.
 *
 * Klasa `sw-stop` (patrz index/swipe.inc.js) to bariera gestu: przeciagniecie po marginesach
 * miedzy wykresami umiera tutaj, zamiast wedrowac do karty nadrzednej.
 */
?>
<style>
/* ten sam styl przelacznika co Dzien/Miesiac/Rok w card_reku_flow (.rkf-vbtn) */
.pl-vbtn:disabled{opacity:.35;cursor:default}
</style>
<!-- to samo tlo co rozwiniecie w card_temps (#temps-chart) — rozwiniecie ma sie odcinac od karty -->
<div class="sw-stop" style="padding:10px; background:rgba(255,255,255,0.04); border-radius:10px;">
  <!-- ZAKLADKI: dawny panel raportow Pstryka mieszka teraz tutaj. Kazda zakladka dociaga swoja karte
       dopiero po kliknieciu — zwiniete rozwiniecie nie generuje ani jednego PNG-a na Pi. -->
  <div id="pl-tabs" style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin-bottom:10px;">
    <button class="pl-vbtn on" data-tab="moc">Moc</button>
    <button class="pl-vbtn" data-tab="koszt">Koszty</button>
    <button class="pl-vbtn" data-tab="avg">Ceny</button>
    <button class="pl-vbtn" data-tab="energa">vs Energa</button>
    <button class="pl-vbtn" data-tab="power">Moc h</button>
    <button class="pl-vbtn" data-tab="volt">Napięcie</button>
    <button class="pl-vbtn" data-tab="zu">Zużycie</button>
  </div>

  <!-- Drugi poziom zakladki "Zuzycie": wybor urzadzenia. Ten sam raport co dawny tab_zuzycie,
       tylko po jednym naraz — nikt nie oglada osmiu wykresow jednoczesnie. -->
  <div id="pl-zu" style="display:none;gap:6px;justify-content:center;flex-wrap:wrap;margin-bottom:10px;">
    <button class="pl-vbtn on" data-dev="dom">Dom</button>
    <button class="pl-vbtn" data-dev="grzalka_bufor">Grzałka góra</button>
    <button class="pl-vbtn" data-dev="grzalka_bufor2">Grzałka dół</button>
    <button class="pl-vbtn" data-dev="pralka">Pralka</button>
    <button class="pl-vbtn" data-dev="suszarka">Suszarka</button>
    <button class="pl-vbtn" data-dev="zmywarka">Zmywarka</button>
    <button class="pl-vbtn" data-dev="rekuperator">Reku</button>
    <button class="pl-vbtn" data-dev="pompa_ogrod">Pompa ogród</button>
  </div>

  <div id="pl-moc">
    <div style="display:flex;gap:6px;justify-content:center;margin-bottom:10px;">
      <button class="pl-vbtn" id="pl-prev">−24 h</button>
      <button class="pl-vbtn" id="pl-now">Teraz</button>
      <button class="pl-vbtn" id="pl-next">+24 h</button>
    </div>
    <div id="card_pstryk_live" class="sw-zone" style="position:relative;">
      <div class="card-sw"><div style="text-align:center;color:#666;font-size:12px;padding:30px 0;">…</div></div>
    </div>
    <div id="card_pstryk_day" class="sw-zone" style="position:relative;">
      <div class="card-sw"><div style="text-align:center;color:#666;font-size:12px;padding:30px 0;">…</div></div>
    </div>
  </div>

  <div id="card_pl_rep" class="sw-zone" style="position:relative;display:none;">
    <div class="card-sw"><div style="text-align:center;color:#666;font-size:12px;padding:30px 0;">…</div></div>
  </div>
</div>
<script>
(function() {
  var $card = $('#card_pstryk_load');

  // Karta siedzi w layoucie na stale, wiec przy zwinietym rozwinieciu chowa sie sama i NIE dociaga
  // zadnego wykresu — Pi nie generuje PNG-ow, ktorych nikt nie oglada.
  if (!window.pstrykLoadOpen) { $card.hide(); return; }

  // Okno 2,5 h przesuwane co 2 h (`off` w minutach). Krok 2 h, a nie 2,5 h, zeby 24 h dzielilo sie
  // rowno — przyciski ±24 h skacza wtedy o 12 pozycji i trafiaja w te sama godzine.
  // Sasiednie pozycje zachodza na siebie o 30 min, wiec nic nie ginie na szwie.
  var STEP_MIN = 120, DAY_STEPS = 12, MAX_MIN = 10080;   // 7 dni
  var live = [];
  for (var m = MAX_MIN; m >= 0; m -= STEP_MIN) {
      live.push({ panel: 'home', tab: 'default', card: 'pstryk_live', params: { off: m } });
  }

  // Dni: idx 0 = 7 dni temu, ostatni = dzis
  var days = [];
  for (var i = 7; i >= 0; i--) {
      var d = new Date(); d.setDate(d.getDate() - i);
      var iso = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
      days.push({ panel: 'home', tab: 'default', card: 'pstryk_day', params: { date: iso } });
  }

  // Pozycje swipe przezywaja przeladowanie tej karty dzieki window.swIdx (swipe.inc.js).
  // Odswieza sie tylko okno biezace ("teraz" / "dzis") — cofniete w przeszlosc juz sie nie zmieni.
  function show(divId, list, idx) {
      var $z = $('#card_' + divId);
      if (!$z.length) return;
      if (idx < 0) idx = 0;
      if (idx >= list.length) idx = list.length - 1;
      $z.data('swipe', list)
        .attr('data-swipe-idx', String(idx))
        .attr('data-swipe-default-idx', String(list.length - 1));
      window.swIdx = window.swIdx || {};
      window.swIdx[divId] = idx;
      var refresh = (idx === list.length - 1) ? DEFAULT_REFRESH : 0;
      loadCard('home', 'default', list[idx].card, refresh, null, divId, list[idx].params);
      return idx;
  }

  function liveIdx() {
      var i = (window.swIdx && window.swIdx.pstryk_live != null) ? window.swIdx.pstryk_live : live.length - 1;
      return (i < 0 || i >= live.length) ? live.length - 1 : i;
  }

  function dayIdx() {
      var i = (window.swIdx && window.swIdx.pstryk_day != null) ? window.swIdx.pstryk_day : days.length - 1;
      return (i < 0 || i >= days.length) ? days.length - 1 : i;
  }

  // Stan przyciskow: „Teraz" podswietlone gdy OBA wykresy stoja na biezacym oknie, skoki wygaszone
  // dopiero gdy oba dobily do konca listy — inaczej przycisk gasnie, choc drugi wykres ma jeszcze zapas.
  function paint() {
      var li = liveIdx(), di = dayIdx();
      $('#pl-now').toggleClass('on', li === live.length - 1 && di === days.length - 1);
      $('#pl-prev').prop('disabled', li === 0 && di === 0);
      $('#pl-next').prop('disabled', li === live.length - 1 && di === days.length - 1);
  }

  // delta w dobach: -1 / +1. Oba wykresy jada razem — okno 2,5 h o 12 krokow, doba o 1 dzien.
  function jump(delta) {
      show('pstryk_live', live, liveIdx() + delta * DAY_STEPS);
      show('pstryk_day', days, dayIdx() + delta);
      paint();
  }

  document.getElementById('pl-prev').onclick = function() { jump(-1); };
  document.getElementById('pl-next').onclick = function() { jump(1); };
  document.getElementById('pl-now').onclick  = function() {
      show('pstryk_live', live, live.length - 1);
      show('pstryk_day', days, days.length - 1);
      paint();
  };

  // --- ZAKLADKI RAPORTOW ---
  // Kazda lista to ten sam idiom co `live`/`days`: pozycje swipe dla jednego kontenera.
  // Ostatnia pozycja = domyslna, wiec zakladki koszt/ceny/energa startuja na widoku per rok.
  function dayList(card) {
      var a = [];
      for (var i = 7; i >= 0; i--) {
          var d = new Date(); d.setDate(d.getDate() - i);
          var iso = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
          a.push({ panel: 'home', tab: 'default', card: card, params: { view: 'daily', date: iso } });
      }
      return a;
  }
  function v(card, view, extra) {
      var p = { view: view };
      if (extra) for (var k in extra) p[k] = extra[k];
      return { panel: 'home', tab: 'default', card: card, params: p };
  }

  var TABS = {
      koszt:  dayList('pstryk_koszt').concat([v('pstryk_koszt','monthly'), v('pstryk_koszt','yearly'), v('pstryk_koszt','peryear')]),
      avg:    (function(){ var a = []; for (var i = 12; i >= 0; i--) { var d = new Date(); d.setMonth(d.getMonth() - i);
                  a.push(v('pstryk_avg','monthly',{month: d.getFullYear() + '-' + ('0'+(d.getMonth()+1)).slice(-2)})); }
                  return a.concat([v('pstryk_avg','yearly'), v('pstryk_avg','peryear')]); })(),
      energa: dayList('pstryk_vs_energa').concat([v('pstryk_vs_energa','monthly'), v('pstryk_vs_energa','yearly'), v('pstryk_vs_energa','peryear')]),
      power:  dayList('pstryk_power_day').concat([v('pstryk_power_day','monthly'), v('pstryk_power_day','yearly')]),
      volt:   dayList('pstryk_voltage_day').concat([v('pstryk_voltage_day','monthly'), v('pstryk_voltage_day','yearly')])
  };
  // Zuzycie per urzadzenie — ta sama lista widokow dla kazdego (8 dni + miesiac + rok).
  function zuList(dev) {
      return dayList('zu_' + dev).concat([v('zu_' + dev, 'monthly'), v('zu_' + dev, 'yearly')]);
  }

  // Start: energa/moc/napiecie na dzis (idx 7 w liscie dni), ceny na biezacym miesiacu (idx 12).
  // KOSZTY startuja na widoku ROCZNYM per miesiac (idx 9: 8 dni + monthly + yearly) — user 2026-09-07;
  // dni i miesiac sa pod swipe'em w prawo, per rok w lewo.
  var TAB_START = { koszt: 9, avg: 12, energa: 7, power: 7, volt: 7 };

  function showTab(name) {
      window.plTab = name;
      $('#pl-tabs .pl-vbtn').removeClass('on').filter('[data-tab="' + name + '"]').addClass('on');
      if (name === 'moc') {
          $('#pl-moc').show();
          $('#pl-zu').hide();
          $('#card_pl_rep').hide();
          return;
      }
      $('#pl-moc').hide();
      $('#card_pl_rep').show();
      if (name === 'zu') {
          $('#pl-zu').css('display', 'flex');
          showDev(window.plDev || 'dom');
          return;
      }
      $('#pl-zu').hide();
      var list = TABS[name];
      // pozycja swipe per zakladka — powrot na zakladke wraca tam, gdzie sie skonczylo
      window.plIdx = window.plIdx || {};
      var idx = (window.plIdx[name] != null) ? window.plIdx[name]
              : (TAB_START[name] != null ? TAB_START[name] : list.length - 1);
      window.swIdx = window.swIdx || {};
      window.swIdx.pl_rep = idx;
      show('pl_rep', list, idx);
  }

  // Urzadzenie w zakladce "Zuzycie" — wlasny klucz pozycji swipe (`zu:<dev>`), zeby przeskok
  // miedzy pralka a suszarka nie gubil dnia, na ktorym stales.
  function showDev(dev) {
      window.plDev = dev;
      $('#pl-zu .pl-vbtn').removeClass('on').filter('[data-dev="' + dev + '"]').addClass('on');
      var list = zuList(dev), key = 'zu:' + dev;
      window.plIdx = window.plIdx || {};
      var idx = (window.plIdx[key] != null) ? window.plIdx[key] : 7;
      window.swIdx = window.swIdx || {};
      window.swIdx.pl_rep = idx;
      show('pl_rep', list, idx);
  }

  $('#pl-tabs').on('click', '.pl-vbtn', function() { showTab($(this).data('tab')); });
  $('#pl-zu').on('click', '.pl-vbtn', function() { showDev($(this).data('dev')); });

  showTab(window.plTab || 'moc');
  if ((window.plTab || 'moc') === 'moc') {
      show('pstryk_live', live, liveIdx());
      show('pstryk_day', days, dayIdx());
  }
  paint();

  // Swipe zmienia idx poza tym skryptem — swipe.inc.js wola ten hook po kazdym gescie.
  window.swOnIdx = function(divId) {
      if (divId === 'pstryk_live' || divId === 'pstryk_day') paint();
      // zapamietaj pozycje swipe biezacej zakladki, zeby powrot do niej trafial w to samo miejsce
      if (divId === 'pl_rep' && window.plTab && window.plTab !== 'moc') {
          window.plIdx = window.plIdx || {};
          var key = (window.plTab === 'zu') ? ('zu:' + window.plDev) : window.plTab;
          window.plIdx[key] = (window.swIdx || {}).pl_rep;
      }
  };
})();
</script>
