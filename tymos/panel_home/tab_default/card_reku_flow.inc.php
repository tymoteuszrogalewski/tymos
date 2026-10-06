<?php
// Ciemny dynamiczny diagram reku (flow). DOM=LEWO, DWOR=PRAWO. Czerpnia=gora-prawo, wyrzutnia=dol-prawo.
// Strumienie krzyzuja sie przez wymiennik; kolor wg temp, strzalki pokazuja kierunek.
// Coil POST: nagrzewnica/chlodnica na nawiewie, za wymiennikiem (dev16/dev851). Coila PRE na
// czerpni NIE MA — nie istnieje i w tym roku nie powstanie, wiec nie ma tez jego ikony.
//   off = szary + X; grzanie = czerwony; chlodzenie = niebieski. Pill-e = same wartosci (pozycja = co jest co).
// Coil POST to JEDNA ikona (wezownica) przebarwiana rola — czerwona gdy grzeje, niebieska gdy
// chlodzi (2026-08-29). Wczesniej stala tam para falka+sniezynka, ale to jedno urzadzenie i para
// sugerowala dwa; sniezynka zostaje na diagramie tylko jako kontrolka chlodzenia salonu.
// Pod diagramem JEDEN rzad kontrolek: bieg | bypass | chlodzenie (salon, reku-post, kuchnia).
// Dane z api reku_diagram + klimat_state. v3.
?>
<style>
.rkf svg{width:100%;height:auto;display:block}
.rkf .val{fill:#f0f0f0;font:600 15.6px sans-serif}
.rkf .val .h{font-weight:400;fill:#9a9a9a}
.rkf .sub{fill:#c8c8c8;font:500 12px sans-serif}
.rkf .komf{fill:#8a8a8a;font:600 17px sans-serif}
.rkf .pillbg{fill:#1e1e1e;stroke:#333;stroke-width:1}
.rkf .exch{fill:rgba(255,255,255,0.09);stroke:#8f8f8f;stroke-width:1.8}
.rkf .top{fill:#bbb;font:600 11px sans-serif}
.rkf .cap{fill:#666;font:700 9px sans-serif;letter-spacing:.12em}
.rkf .coill{stroke:#888;stroke-width:1}
.rkf .devsep{stroke:#2a2a2a;stroke-width:1}
/* Kontrolki wprost na diagramie, w jednym rzedzie: bieg | bypass | chlodzenie.
   JEDNA ikona = JEDEN przelacznik. Ikona pokazuje AKTUALNY STAN, a klik przewija tryb w kolko
   (bieg: A -> OFF -> I -> II -> III; reszta: A -> OFF -> ON). Male "A" w indeksie gornym mowi
   tylko tyle, ze stan ustawil automat. ZERO ramek i teł. Klikalnosc daje NIEWIDZIALNY prostokat
   `.hit`, zeby palec trafial takze w luzy miedzy ikonami. */
.rkf .ctl{cursor:pointer}
.rkf .ctl .hit{fill:transparent}
.rkf .ctl .ico{color:#8a8a8a;opacity:.45}
.rkf .ctl .ico.lit{color:#4a9eff;opacity:1}
.rkf .ctl .ico.lit.cool{color:#5ab6ff}
.rkf .ctl .ico.lit.fan{color:#57c97a}
/* "A" stoi ZAWSZE (2026-09-04): niebieskie = steruje automat, szare = przelaczone z reki.
   Wczesniej znikalo poza Auto — bez litery nie bylo widac, ze to w ogole da sie oddac automatowi. */
.rkf .badge{fill:#8a8a8a;opacity:.45;font:700 13px sans-serif}
.rkf .badge.lit{fill:#4a9eff;opacity:1}
/* Jedna zasada dla wszystkich kontrolek: KOLOR = dziala teraz, SZARY = nie dziala,
   PRZEKRESLENIE = wymuszone OFF z reki. Auto, ktore akurat nie chlodzi/nie dmucha, jest
   szare BEZ kreski — bo jest gotowe, a nie zablokowane. */
.rkf .slash{stroke:#8a8a8a;stroke-width:2.8;stroke-linecap:round;opacity:.45}
</style>

<div class="rkf" id="rkf-toggle">
<!-- Rzad kontrolek: ikony 44 jednostki SVG (2026-09-04) — glif ma byc tej wielkosci, co SAMA
     STRZALKA gora/dol rolety w nakladce dzwonka (~47 px), nie jej ramka. Wczesniej 39.
     PRZELICZNIK, zeby nie zgadywac przy nastepnej zmianie: karta skaluje viewBox 680 do
     szerokosci SVG (szerokosc karty minus padding 2x10), wiec 1 jednostka = szerokosc/680,
     na tablecie ~1,28 px. Wysokosc viewBoxu 196 = 140 (diagram) + 56 (rzad ikon 44 + luzy).
     UKLAD 2026-08-29: strumienie splaszczone do poziom-ukos-poziom, przez co diagram zmiescil sie
     w 190 jednostkach zamiast 236 (bylo 0..290, jest 0..190). -->
<svg viewBox="0 0 680 196" preserveAspectRatio="xMidYMid meet">
  <defs>
    <symbol id="ic-heat" viewBox="0 0 24 24">
      <g fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
        <path d="M4 7 q3 -4 6 0 t6 0"/><path d="M4 13 q3 -4 6 0 t6 0"/><path d="M4 19 q3 -4 6 0 t6 0"/>
      </g>
    </symbol>
    <symbol id="ic-snow" viewBox="0 0 24 24">
      <path fill="currentColor" d="M20.79,13.95L18.46,14.57L16.46,13.44V10.56L18.46,9.43L20.79,10.05L21.31,8.12L19.54,7.65L20,5.88L18.07,5.36L17.45,7.69L15.45,8.82L13,7.4V5.12L14.71,3.41L13.29,2L12,3.29L10.71,2L9.29,3.41L11,5.12V7.4L8.55,8.82L6.55,7.69L5.93,5.36L4,5.88L4.46,7.65L2.69,8.12L3.21,10.05L5.54,9.43L7.54,10.56V13.44L5.54,14.57L3.21,13.95L2.69,15.88L4.46,16.35L4,18.12L5.93,18.64L6.55,16.31L8.55,15.18L11,16.6V18.88L9.29,20.59L10.71,22L12,20.71L13.29,22L14.71,20.59L13,18.88V16.6L15.45,15.18L17.45,16.31L18.07,18.64L20,18.12L19.54,16.35L21.31,15.88L20.79,13.95Z"/>
    </symbol>
    <!-- Bypass OTWARTY = strumienie ida obok siebie, rownolegle. Bez scianek kanalu:
         przy tym rozmiarze zlewaly sie ze strzalkami w jedna plame. -->
    <symbol id="ic-flow" viewBox="0 0 24 24">
      <g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <!-- Groty: nozki 3,8 jednostki pod 30 stopni (2026-09-07, bylo 5,2) — dluzsze zlewaly sie
             ze soba miedzy strzalkami przy stroke 2. -->
        <path d="M3 7h13.2"/><polyline points="13.7 5.1 17 7 13.7 8.9"/>
        <path d="M3 17h13.2"/><polyline points="13.7 15.1 17 17 13.7 18.9"/>
      </g>
    </symbol>
    <!-- Bypass ZAMKNIETY = strumienie ida przez wymiennik, czyli sie KRZYZUJA (to samo X, co
         w duzym diagramie wyzej). Nie przekreslenie — bo to nie jest „nic sie nie dzieje". -->
    <symbol id="ic-flowX" viewBox="0 0 24 24">
      <g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <!-- Groty skrocone 2026-09-07 (nozki 5,8 -> 3,8, kat 30 stopni od trzonu): wewnetrzne nozki
             obu grotow konczyly sie 2,6 jednostki od siebie i przy stroke 2 zlewaly w jedna plame. -->
        <!-- Poczatki i groty w rogach kwadratu 4..20 (2026-09-07): starty (4,4) i (4,20), czubki (20,20) i (20,4),
             przeciecie dokladnie w (12,12). Nozki grotow 3,8 pod 30 stopni. -->
        <path d="M4 4 L19.2 19.2"/><polyline points="16.3 19 20 20 19 16.3"/>
        <path d="M4 20 L19.2 4.8"/><polyline points="16.3 5 20 4 19 7.7"/>
      </g>
    </symbol>
    <symbol id="ic-fan" viewBox="0 0 24 24">
      <path fill="currentColor" d="M12,11A1,1 0 0,0 11,12A1,1 0 0,0 12,13A1,1 0 0,0 13,12A1,1 0 0,0 12,11M12.5,2C17,2 17.11,5.57 14.75,6.75C13.76,7.24 13.32,8.29 13.13,9.22C13.61,9.42 14.03,9.73 14.35,10.13C18.05,8.13 22.03,8.92 22.03,12.5C22.03,17 18.46,17.1 17.28,14.73C16.78,13.74 15.72,13.3 14.79,13.11C14.59,13.59 14.28,14 13.88,14.34C15.87,18.03 15.08,22 11.5,22C7,22 6.91,18.42 9.27,17.24C10.26,16.75 10.68,15.71 10.87,14.78C10.39,14.58 9.97,14.27 9.65,13.87C5.96,15.86 2,15.07 2,11.5C2,7 5.56,6.89 6.74,9.26C7.24,10.25 8.29,10.68 9.22,10.87C9.41,10.39 9.73,9.97 10.14,9.65C8.15,5.96 8.94,2 12.5,2Z"/>
    </symbol>
  </defs>

  <!-- STRUMIENIE (przerysowane 2026-08-29 wg szkicu usera): poziom u gory -> ukos -> poziom u dolu.
       Poziome koncowki daja miejsce na pille i skracaja diagram w pionie o ~20%. Zalamania
       zaokraglone krzywa Q z punktem kontrolnym w samym wierzcholku. Poczatki gorne (y=18)
       i konce dolne (y=118) zrownane, wiec obie linie sa swoimi lustrzanymi odbiciami.
       Nawiew = niebieski: czerpnia P-gora -> dom L-dol. Wywiew = czerwony: dom L-gora -> wyrzutnia P-dol. -->
  <!-- DUCHY: skrajne polozenia wywiewu. Ciemnoszare, pod spodem — pokazuja, odkad dokad
       wedruje czerwona przy otwieraniu bypassu. Lewy = 0 % (przez wymiennik), prawy = 100 %
       (za wymiennikiem, bez krzyzowania z nawiewem). Rysowane raz, w JS sie nie zmieniaja. -->
  <path id="byp-ghost0" class="ghost" fill="none" stroke="#2f2f2f" stroke-width="9" stroke-linecap="round" stroke-linejoin="round"/>
  <path id="byp-ghost1" class="ghost" fill="none" stroke="#2f2f2f" stroke-width="9" stroke-linecap="round" stroke-linejoin="round"/>

  <path id="flow-supply"  d="M650 18 H432 Q410 18 392 31 L288 105 Q270 118 248 118 H40"
        fill="none" stroke="#5ab6ff" stroke-width="9" stroke-linecap="round" stroke-linejoin="round" opacity="0.92"/>
  <path id="flow-extract" d="M30 18 H248 Q270 18 288 31 L392 105 Q410 118 432 118 H640"
        fill="none" stroke="#ff7a5c" stroke-width="9" stroke-linecap="round" stroke-linejoin="round" opacity="0.92"/>

  <!-- groty na koncach poziomow, w tej samej osi Y -->
  <polygon id="a-sup-out" points="40,106 40,130 16,118"/>
  <polygon id="a-ext-out" points="640,106 640,130 664,118"/>

  <!-- WYMIENNIK: mniejszy (50x36 zamiast 80x52), bo stan bypassu niesie teraz sama linia,
       a nie napis w srodku. -->
  <polygon class="exch" points="340,32 390,68 340,104 290,68"/>

  <!-- WARTOSCI. Gorne pod swoja pozioma linia, dolne nad swoja. Lewy dol to JEDEN rzad:
       temperatura za wezownica, ikona wezownicy, nawiew z wymiennika — poziomy odcinek daje
       na to 170 jednostek szerokosci, wiec wszystko miesci sie w jednej linii.
       Tekst NIE jest wysrodkowany w ramce: lewe pille maja text-anchor=start (x=12), prawe
       text-anchor=end (x=68). Przy `middle` rozne szerokosci ramek (80 vs 52) dawaly rozny
       odstep tresci od brzegu i wartosci nie stały w jednej osi. -->
  <g id="p_extract" transform="translate(28,30)"><rect class="pillbg" x="0" width="80" height="28" rx="8"/><text id="v_extract" x="11" y="19" class="val" text-anchor="start">—</text></g>
  <g id="p_outdoor" transform="translate(648,30)"><rect class="pillbg" x="-80" width="80" height="28" rx="8"/><text id="v_outdoor" x="-11" y="19" class="val" text-anchor="end">—</text></g>

  <!-- Cel komfortu (2026-09-04, na zyczenie usera): w wolnym polu MIEDZY pillami wywiewu
       (u gory) a nawiewu (nizej), na prawo od nich — z dala od rzedu kontrolek, gdzie wczesniej
       stal przy chlodnicy salonu. `komfort_target` prowadzi sezonowo ai_rekuperator (zima 21,5 / lato 23,0). -->
  <text id="v_komfort" x="194" y="70" class="komf" text-anchor="middle">—</text>
  <g id="p_post" transform="translate(28,76)"><rect class="pillbg" x="0" width="52" height="28" rx="8"/><text id="v_post" x="11" y="19" class="val" text-anchor="start">—</text></g>
  <use id="post-coil" href="#ic-heat" x="86" y="77" width="26" height="26" style="color:#8a8a8a;opacity:.42"/>
  <g id="p_supply" transform="translate(118,76)"><rect class="pillbg" x="0" width="80" height="28" rx="8"/><text id="v_supply" x="11" y="19" class="val" text-anchor="start">—</text></g>

  <g id="p_exhaust" transform="translate(648,76)"><rect class="pillbg" x="-80" width="80" height="28" rx="8"/><text id="v_exhaust" x="-11" y="19" class="val" text-anchor="end">—</text></g>

  <!-- JEDEN RZAD KONTROLEK, DWIE GRUPY (2026-08-29):
       LEWA = sam rekuperator: bieg wentylatorow | bypass | wezownica nawiewu.
       PRAWA = odbiorniki poza centrala: chlodnica salonu | przewiew kuchni.
       Wczesniej wszystkie piec stalo w rownych odstepach i bypass z wezownica wygladaly na
       czesc prawej grupy, choc naleza do reku. Przerwa miedzy grupami niesie te roznice. -->
  <line class="devsep" x1="30" y1="140" x2="650" y2="140"/>

  <g class="ctl" id="bieg-ctl">
    <rect class="hit" x="10" y="141" width="160" height="54"/>
    <text class="badge" id="bieg-a" x="14" y="157">A</text>
    <use class="ico" id="bieg-f1" href="#ic-fan" x="26"  y="145" width="44" height="44"/>
    <use class="ico" id="bieg-f2" href="#ic-fan" x="72"  y="145" width="44" height="44"/>
    <use class="ico" id="bieg-f3" href="#ic-fan" x="118" y="145" width="44" height="44"/>
  </g>

  <g class="ctl" id="byp-ctl">
    <rect class="hit" x="174" y="141" width="70" height="54"/>
    <text class="badge" id="byp-a" x="178" y="157">A</text>
    <use class="ico lit" id="byp-on"  href="#ic-flow"  x="190" y="145" width="44" height="44"/>
    <use class="ico lit" id="byp-off" href="#ic-flowX" x="190" y="145" width="44" height="44"/>
  </g>

  <g class="ctl" id="chl-reku" data-key="tryb_chlodnice_nawiew">
    <rect class="hit" x="252" y="141" width="70" height="54"/>
    <text class="badge" x="256" y="157">A</text>
    <use class="ico cool" href="#ic-heat" x="268" y="145" width="44" height="44"/>
    <line class="slash" x1="267" y1="190" x2="312" y2="146"/>
  </g>

  <g class="ctl" id="chl-salon" data-key="tryb_chlodnice">
    <rect class="hit" x="522" y="141" width="70" height="54"/>
    <text class="badge" x="526" y="157">A</text>
    <use class="ico cool" href="#ic-snow" x="538" y="145" width="44" height="44"/>
    <line class="slash" x1="537" y1="190" x2="582" y2="146"/>
  </g>

  <g class="ctl" id="chl-kuch" data-key="tryb_freecool">
    <rect class="hit" x="600" y="141" width="80" height="54"/>
    <text class="badge" x="604" y="157">A</text>
    <use class="ico fan" href="#ic-fan" x="616" y="145" width="44" height="44"/>
    <line class="slash" x1="615" y1="190" x2="660" y2="146"/>
  </g>

  <!-- Klik otwierajacy wykresy lapie TYLKO wymiennik, nie caly diagram (2026-08-30). Wczesniej
       pole szlo przez cala szerokosc do y=136, a rzad kontrolek zaczyna sie na 145 — przy
       celowaniu w ikony palec za czesto trafial w diagram i otwieral wykresy. Romb jest tu
       nieco wiekszy od rysowanego (`.exch`), zeby dalo sie w niego trafic bez mierzenia. -->
  <polygon id="rkf-hit" points="340,20 402,68 340,116 278,68" fill="transparent" style="cursor:pointer"/>
</svg>
</div>

<!-- Wykresy reku — od 2026-09-04 w OKNIE nad panelem (winShow, cards.inc.js), nie jako wstawka pod
     diagramem. Tresc NIE jest generowana razem z karta: dociagamy ja przez loadCard dopiero po
     kliknieciu w wymiennik, wiec zamkniete nie kosztuje ani jednego zapytania. Karty leza
     w panel_home/tab_default (przeniesione z raportow/ogrzewanie 2026-08-17). -->
<div id="rkf-charts" style="display:none">
  <div style="display:flex;gap:6px;justify-content:center;margin-bottom:10px;">
    <button class="rkf-vbtn on" data-view="daily">Dzień</button>
    <button class="rkf-vbtn" data-view="monthly">Miesiąc</button>
    <button class="rkf-vbtn" data-view="yearly">Rok</button>
  </div>
  <div id="card_rkf_temps"><div style="text-align:center;color:#666;font-size:12px;padding:20px 0;">…</div></div>
  <div id="card_rkf_fans" style="margin-top:10px;"></div>
</div>

<script>
(function(){
  var $c = $('.rkf'); if (!$c.length || $c.data('init')) return; $c.data('init', 1);

  // --- rozwijane wykresy reku ---
  var box = document.getElementById('rkf-charts');
  var tog = document.getElementById('rkf-toggle');
  if (box && tog) {
      var view = window.rkfView || 'daily';

      function paint() {
          $('.rkf-vbtn').each(function(){ $(this).toggleClass('on', $(this).data('view') === view); });
      }
      function pull() {
          // refresh 0 — wykresy dobowe nie musza sie odswiezac same; diagram wyzej ma wlasny poll 10 s
          loadCard('home', 'default', 'temps_esp',  0, null, 'rkf_temps', { view: view });
          loadCard('home', 'default', 'reku_fans',  0, null, 'rkf_fans',  { view: view });
      }
      var hit = document.getElementById('rkf-hit');
      if (hit) hit.onclick = function() {
          winShow(box);
          paint(); pull();
      };
      $('.rkf-vbtn').on('click', function(e) {
          e.stopPropagation();
          view = window.rkfView = $(this).data('view');
          paint(); pull();
      });
  }

  // Kontrolki na diagramie. Klik PRZEWIJA tryb w kolko (nie ma osobnych przyciskow na tryb),
  // a to, co widac, to zawsze AKTUALNY STAN. stopPropagation, bo caly diagram jest przelacznikiem
  // wykresow — bez tego kazde ustawienie biegu otwieraloby albo zamykalo wykresy pod spodem.
  // Wszedzie ta sama kolejnosc: z Auto pierwszy klik WYLACZA. Bez tego bieg gasl, a chlodzenie
  // przy tym samym gescie ruszalo — i palec sie mylil.
  var SEQ_BIEG = ['auto', 'off', '1', '2', '3'];   // A -> OFF -> I -> II -> III -> A
  var SEQ_TRI  = ['auto', 'off', 'on'];            // A -> OFF -> ON -> A
  var st = {};                                     // ostatni klimat_state (do rysowania i do cyklu)

  function next(seq, cur){ var i = seq.indexOf(String(cur)); return seq[(i + 1) % seq.length]; }
  function vis(id, yes){ var e = document.getElementById(id); if (e) e.style.display = yes ? '' : 'none'; }

  // Rysowanie ze `st`. W Auto pokazujemy to, co Auto WLASNIE USTAWILO (fan_speed / bypass_open /
  // realny stan urzadzenia), bo sam napis "auto" nic nie mowi o tym, co sie dzieje.
  function draw(){
      var bieg = String(st.reku_bieg_mode || 'auto');
      var SPD  = {Low: '1', Medium: '2', High: '3', Away: 'off'};
      var eb   = (bieg === 'auto') ? (SPD[st.fan_speed] || 'off') : bieg;
      $('#bieg-a').toggleClass('lit', bieg === 'auto');
      // Bieg nie ma przekreslenia: przy trzech smiglach kreska na jednym czytala sie jak "to jedno
      // zepsute". Nie tracimy nic, bo OFF z reki od OFF z automatu odroznia badge — trzy szare
      // + niebieskie "A" to Away z automatu, trzy szare + szare "A" to wylaczone recznie.
      // Trzy smigla stoja ZAWSZE — szare sa skala, niebieskie odczytem. Widac naraz, ktory bieg
      // chodzi i ile jeszcze zostalo w zapasie; wczesniej brakujace smigla byly ukrywane i przy
      // I biegu nie dalo sie odroznic "najnizszy z trzech" od "jedyny, jaki jest".
      // OFF (z reki albo z automatu w Away) = trzy szare; kreska dochodzi tylko przy OFF z reki.
      var lvl = ({'1': 1, '2': 2, '3': 3})[eb] || 0;
      $('#bieg-f1').toggleClass('lit', lvl >= 1);
      $('#bieg-f2').toggleClass('lit', lvl >= 2);
      $('#bieg-f3').toggleClass('lit', lvl >= 3);

      var byp = String(st.reku_bypass_mode || 'auto');
      var ey  = (byp === 'auto') ? (st.bypass_open ? 'on' : 'off') : byp;
      $('#byp-a').toggleClass('lit', byp === 'auto');
      vis('byp-on',  ey === 'on');
      vis('byp-off', ey === 'off');

      [['chl-reku',  'tryb_chlodnice_nawiew', 'nawiew_on'],
       ['chl-salon', 'tryb_chlodnice',        'chlodnice_on'],
       ['chl-kuch',  'tryb_freecool',         'freecool_on']].forEach(function(p){
          var mode = String(st[p[1]] || 'auto');
          var lit  = (mode === 'on') || (mode === 'auto' && !!(+st[p[2]]));
          $('#' + p[0] + ' .badge').toggleClass('lit', mode === 'auto');
          $('#' + p[0] + ' .slash').css('display', mode === 'off' ? '' : 'none');
          $('#' + p[0] + ' .ico').toggleClass('lit', lit);
      });
  }

  // Klik: policz nastepny tryb, narysuj OD RAZU (bez czekania na poll — inaczej ikona stoi
  // martwa przez sekunde i czlowiek klika drugi raz), dopiero potem zapis i poll kontrolny.
  function cycle(key, seq){
      var val = next(seq, st[key] || 'auto');
      st[key] = val;
      if (key !== 'reku_bieg_mode' && key !== 'reku_bypass_mode' && val !== 'auto') {
          st[{tryb_chlodnice: 'chlodnice_on', tryb_freecool: 'freecool_on',
              tryb_chlodnice_nawiew: 'nawiew_on'}[key]] = (val === 'on') ? 1 : 0;
      }
      draw();
      $.post('api.php', {action: 'klimat_set', key: key, value: val},
             function(){ setTimeout(pollCtl, 1200); }, 'json');
  }
  $c.on('click', '#bieg-ctl',  function(e){ e.stopPropagation(); cycle('reku_bieg_mode', SEQ_BIEG); });
  $c.on('click', '#byp-ctl',   function(e){ e.stopPropagation(); cycle('reku_bypass_mode', SEQ_TRI); });
  $c.on('click', '#chl-reku',  function(e){ e.stopPropagation(); cycle('tryb_chlodnice_nawiew', SEQ_TRI); });
  $c.on('click', '#chl-salon', function(e){ e.stopPropagation(); cycle('tryb_chlodnice', SEQ_TRI); });
  $c.on('click', '#chl-kuch',  function(e){ e.stopPropagation(); cycle('tryb_freecool', SEQ_TRI); });

  // Stan biegu, bypassu i chlodnic siedzi w klimat_state (te same helpery, co segmenty w widgecie klimatu).
  function pollCtl(){
      if (!$('.rkf').length) return;
      $.getJSON('api.php?action=klimat_state', function(d){
          if (!d || !d.ok) return;
          st = d;
          // BRAK ODCZYTU => kreska zamiast liczby; nie zgadujemy nastawy.
          var kt = parseFloat(d.komfort_target);
          set('v_komfort', isNaN(kt) ? '\u21E2 —' : '\u21E2 ' + kt.toFixed(1).replace('.', ',') + '\u00B0C');
          draw();
      });
  }
  draw();
  pollCtl();
  // Karta wchodzi przez include w card_klimat, wiec siedzi na ekranie glownym, a jej skrypt leci
  // od nowa przy kazdym przejsciu panelu. Bez tego guardu kazde przejscie dokladalo kolejny poller,
  // a stary tykal do przeladowania strony.
  if (window.rkfCtlTimer) clearInterval(window.rkfCtlTimer);
  window.rkfCtlTimer = setInterval(pollCtl, 15000);
  if (typeof cardOnUnload === 'function') cardOnUnload(function() { clearInterval(window.rkfCtlTimer); window.rkfCtlTimer = null; });

  function tcol(t){ if (t == null || isNaN(t)) return '#888'; t = Math.max(14, Math.min(27, t)); var f = (t-14)/13;
    return 'rgb(' + Math.round(90+f*165) + ',' + Math.round(170-f*80) + ',' + Math.round(255-f*185) + ')'; }
  function n(v){ return (v == null || isNaN(v)) ? null : parseFloat(v); }
  function fmt(t, h){ t = n(t); var s = (t == null) ? '—' : (t.toFixed(1).replace('.', ',') + '°'); if (h != null && !isNaN(h)) s += ' ' + Math.round(h) + '%'; return s; }
  function set(id, txt){ var e = document.getElementById(id); if (e) e.textContent = txt; }
  var NS = 'http://www.w3.org/2000/svg';
  function setTH(id, t, h){ // temp (bold) + wilg (chudsza, .h)
    var e = document.getElementById(id); if (!e) return;
    while (e.firstChild) e.removeChild(e.firstChild);
    var a = document.createElementNS(NS, 'tspan'); a.textContent = (t == null || isNaN(t)) ? '—' : (t.toFixed(1).replace('.', ',') + '°'); e.appendChild(a);
    if (h != null && !isNaN(h)) { var b = document.createElementNS(NS, 'tspan'); b.setAttribute('class', 'h'); b.setAttribute('dx', '5'); b.textContent = Math.round(h) + '%'; e.appendChild(b); }
  }
  function fill(id, col){ var e = document.getElementById(id); if (e) e.setAttribute('fill', col); }

  // ikona: aktywna = pelny kolor; nieaktywna = szara + przygaszona (mniej rzuca sie w oczy)
  function ic(id, on, col){ var e = document.getElementById(id); if (!e) return; e.style.color = on ? col : '#8a8a8a'; e.style.opacity = on ? '1' : '.42'; }
  var HOT = '#ff453a', COOL = '#5ab6ff';

  // Ramki wartosci rosna do tresci. Rect ma stala szerokosc tylko w HTML (zanim przyjda dane);
  // po kazdym odczycie mierzymy tekst przez getBBox i ustawiamy ramke na jego szerokosc + 2*PAD.
  // Lewe pille kotwicza sie LEWA krawedzia (rect x=0, tekst start), prawe PRAWA (rect x=-w,
  // tekst end) — dzieki temu 24,0 61% i 19,3 zaczynaja sie w tej samej pionowej osi, a 18,0 83%
  // i 23,4 63% w niej koncza, niezaleznie od dlugosci wartosci.
  var PILL_PAD = 11, PILL_GAP = 10;
  function fitPill(gid){
      var g = document.getElementById(gid); if (!g) return 0;
      var t = g.querySelector('text'), r = g.querySelector('rect');
      if (!t || !r) return 0;
      var bb;
      try { bb = t.getBBox(); } catch (e) { return 0; }   // karta ukryta => getBBox rzuca
      if (!bb || !bb.width) return parseFloat(r.getAttribute('width')) || 0;
      var w = Math.ceil(bb.width) + 2 * PILL_PAD;
      r.setAttribute('width', w);
      r.setAttribute('x', (t.getAttribute('text-anchor') === 'end') ? -w : 0);
      return w;
  }
  function fitPills(){
      fitPill('p_extract'); fitPill('p_outdoor'); fitPill('p_exhaust');
      // Lewy dolny rzad jest jeden pod druga wartoscia, wiec ikona i nawiew musza sie przesunac
      // o tyle, ile urosla ramka temperatury za wezownica.
      var wPost = fitPill('p_post');
      var coil  = document.getElementById('post-coil');
      var sup   = document.getElementById('p_supply');
      if (!coil || !sup) return;
      // ZEROWY odstep i to WIZUALNIE, nie w liczbach. Falka `#ic-heat` NIE jest wysrodkowana
      // w swoim polu: kreski ida w viewBoxie od x=4 do x=16 z 24, wiec przy skali 26/24 ink zajmuje
      // 87,8..100,8 z pola 83,5..109,5 — 4,3 jednostki luzu z lewej i az 8,7 z prawej (zmierzone
      // przez getBBox na wyrenderowanej karcie, nie policzone z glowy). Gdyby ikone dokleic do
      // pigulki na styk WSPOLRZEDNYCH, ten luz zostalby widoczny. Kompensujemy go, zeby
      // temperatura, falka i nawiew czytaly sie jako jeden ciag. PILL_GAP zostaje dla reszty pill.
      var COIL_W = 26, COIL_INK_L = 4.3, COIL_INK_R = 8.7;
      var cx = 28 + wPost - COIL_INK_L;
      coil.setAttribute('x', cx);
      sup.setAttribute('transform', 'translate(' + Math.round(cx + COIL_W - COIL_INK_R) + ',76)');
      fitPill('p_supply');
  }

  // Sciezka wywiewu dla zadanego bypassu (0-100). Wierzcholki jada liniowo miedzy polozeniem
  // "przez wymiennik" a "za wymiennikiem"; zalamania zaokraglone tym samym promieniem co w HTML.
  // Polozenie 100 %: ukos schodzi TUZ ZA wymiennikiem (konczy sie on na x=390), nie na skraju.
  // Przy wiekszym przesunieciu obie linie rozjezdzaly sie tak, ze skrzyzowanie robilo sie ledwo
  // widocznym stykiem — a to wlasnie ono niesie informacje, ze strumienie sie mijaja.
  var BYP_V1 = [270, 355], BYP_V2 = [410, 485], BYP_R = 22, BYP_Y1 = 18, BYP_Y2 = 118;
  function extractPath(byp){
      var t   = Math.max(0, Math.min(100, byp)) / 100;
      var v1  = BYP_V1[0] + (BYP_V1[1] - BYP_V1[0]) * t;
      var v2  = BYP_V2[0] + (BYP_V2[1] - BYP_V2[0]) * t;
      var dx  = v2 - v1, dy = BYP_Y2 - BYP_Y1;
      var len = Math.sqrt(dx * dx + dy * dy) || 1;
      var ux  = dx / len * BYP_R, uy = dy / len * BYP_R;
      return 'M30 ' + BYP_Y1 +
             ' H' + (v1 - BYP_R).toFixed(1) +
             ' Q' + v1.toFixed(1) + ' ' + BYP_Y1 + ' ' + (v1 + ux).toFixed(1) + ' ' + (BYP_Y1 + uy).toFixed(1) +
             ' L' + (v2 - ux).toFixed(1) + ' ' + (BYP_Y2 - uy).toFixed(1) +
             ' Q' + v2.toFixed(1) + ' ' + BYP_Y2 + ' ' + (v2 + BYP_R).toFixed(1) + ' ' + BYP_Y2 +
             ' H640';
  }
  (function drawGhosts(){
      var g0 = document.getElementById('byp-ghost0'), g1 = document.getElementById('byp-ghost1');
      if (g0) g0.setAttribute('d', extractPath(0));
      if (g1) g1.setAttribute('d', extractPath(100));
  })();

  function poll(){
    if (!$('.rkf').length) return;
    $.getJSON('api.php?action=reku_diagram', function(d){
      if (!d || !d.ok) return;
      setTH('v_outdoor', n(d.outdoor_t), d.outdoor_h);  // czerpnia: temp+wilg z wlasnego czujnika centrali (dev527)
      setTH('v_supply',  n(d.supply_t),  d.supply_h);
      setTH('v_extract', n(d.extract_t), d.extract_h);
      setTH('v_exhaust', n(d.exhaust_t), d.exhaust_h);
      setTH('v_post',    n(d.post_t), null);
      fitPills();

      var byp = (d.bypass != null) ? Math.round(d.bypass) : null;

      // BYPASS RYSOWANY KSZTALTEM, nie napisem: przy 0 % wywiew idzie ukosem przez wymiennik
      // i krzyzuje sie z nawiewem, przy 100 % zalamania przesuwaja sie w prawo tak, ze ukos mija
      // wymiennik i linie w ogole sie nie przecinaja — czyli dokladnie to, co robi otwarty bypass.
      // Poczatek (30,18) i koniec (640,118) stoja w miejscu; wedruja tylko dwa wierzcholki.
      // BRAK ODCZYTU => rysujemy jak przy 0 %, bo zamkniety bypass to stan spoczynkowy.
      var fe2 = document.getElementById('flow-extract');
      if (fe2) fe2.setAttribute('d', extractPath(byp == null ? 0 : byp));

      // kolory rola: nawiew=niebieski (swieze/chlodne z dworu), wywiew=czerwony (cieple z domu). Grubosc wg przeplywu.
      var SUP = '#4a9eff', EXT = '#f2603f';
      var fs = document.getElementById('flow-supply'), fe = document.getElementById('flow-extract');
      if (fs){ fs.setAttribute('stroke', SUP); fs.setAttribute('stroke-width', d.sflow ? Math.max(6, Math.min(14, 4 + d.sflow/40)) : 9); }
      if (fe){ fe.setAttribute('stroke', EXT); fe.setAttribute('stroke-width', d.eflow ? Math.max(6, Math.min(14, 4 + d.eflow/40)) : 9); }
      fill('a-sup-out', SUP); fill('a-ext-out', EXT);

      // Coil POST rysuje sie z ROBOTY, jaka wykonal, a nie ze stanu zaworu: delta = temp za coilem
      // (dev851) minus temp nawiewu z wymiennika (dev527). Plus = grzeje, minus = chlodzi.
      // Progi ASYMETRYCZNE: chlodzenie od -0,5, bo tam kazdy ulamek stopnia to realna robota
      // (zawor moze byc przydlawiony), a grzanie dopiero od +2, bo na nawiewie samo z siebie
      // podnosi sie o kreske — opory kanalu i rozjazd miedzy dwoma czujnikami.
      // Ikony coila PRE tu NIE MA — pre-coil na czerpni nie istnieje i w tym roku nie powstanie.
      // (Gdyby wrocil: trzeba by osobnego czujnika na samym wlocie — dev10 = Patio stoi w cieniu
      // ogrodu, wiec delta liczona z niego klamalaby.)
      // Jedna ikona, kolor niesie role: czerwona = grzeje, niebieska = chlodzi, szara = nic nie robi.
      var dPost = (n(d.post_t) != null && n(d.supply_t) != null) ? (n(d.post_t) - n(d.supply_t)) : null;
      var postCool = (dPost != null && dPost < -0.5);
      var postHeat = (dPost != null && dPost >  2);
      ic('post-coil', postCool || postHeat, postCool ? COOL : HOT);

    });
  }
  poll();
  if (window.rkfPollTimer) clearInterval(window.rkfPollTimer);
  window.rkfPollTimer = setInterval(poll, 10000);
  if (typeof cardOnUnload === 'function') cardOnUnload(function() { clearInterval(window.rkfPollTimer); window.rkfPollTimer = null; });
})();
</script>
