<?php
// card_hifi — wieza Technics SC-C70MK2 (2026-10-08, na probe na samym dole). Glosnosc -/+ co 1 (skala 0-100; 5 i 2 byly za duzym skokiem),
// aktualna glosnosc i zrodlo, co gra (okladka, tytul, poprzedni / stop-graj / nastepny). Przelacznikow zrodel na razie nie ma (CD / OPT tylko przez IR). Steruje lokalnym API wiezy
// (inc/hifi.inc.php, endpointy hifi_state / hifi_set) — bez aplikacji Technicsa i bez pilota.
// Wieza sama sie wlacza, gdy cos zacznie na niej grac (na kablu LAN), i sama wylacza po czasie,
// wiec przycisku zasilania nie ma.
// HIFI_CARD_CONTROLS = false (config) chowa przyciski glosnosci i prev / stop / next — zostaja same informacje.
// Lokalnie steruje sie z nakladki na kadrze dzwonka (card_camera_doorbell, 2026-10-10).
$hifiCtl = !defined('HIFI_CARD_CONTROLS') || HIFI_CARD_CONTROLS;
?>
<style>
/* Wlasne kopie stylow z card_woda / card_klimat (wsw-lbl, seg--flat, therm) — karta ma dzialac tez wtedy,
   gdy tamtych kart nie ma w layoucie. */
.hifi .wsw-lbl{display:flex;align-items:center;flex-wrap:wrap;gap:7px;flex:1;min-width:0;font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#7fb0ff}
.hifi .wsw-lbl .ic{font-size:14px}
.hifi .seg--flat{display:flex;flex:0 0 auto;width:auto;max-width:none;background:none;border-radius:0;overflow:visible;gap:15px}
.hifi .seg--flat .seg-btn{flex:0 0 auto;padding:12px 4px;font-size:12px;font-weight:600;letter-spacing:.04em;color:#6b6b6b;background:none;border-left:none}
.hifi .seg--flat .seg-btn.on{color:#4a9eff;background:none;font-weight:700}
.hifi .therm{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex:0 0 auto}
.hifi .klm-r{min-height:44px}
.hifi .hifi-src{font-size:13px;color:#999;margin-left:8px;font-weight:400;letter-spacing:0;text-transform:none}
.hifi .hifi-vol{font-size:24px;font-weight:300;min-width:44px;text-align:center}
.hifi .hifi-vol.mute{color:#666;text-decoration:line-through}
.hifi .hifi-msg{font-size:11px;color:#a08040;min-height:14px}
.hifi .hifi-now{display:flex;align-items:center;gap:10px;padding:4px 0 6px}
.hifi .hifi-now img{width:48px;height:48px;border-radius:6px;object-fit:cover;background:#262626;flex:0 0 48px}
.hifi .hifi-now .t{font-size:15px;color:#ddd}
.hifi .hifi-now .a{font-size:12px;color:#888}
.hifi .hifi-now .tx{min-width:0;flex:1}
.hifi .hifi-now.idle img,.hifi .hifi-now.idle .tx{opacity:.45}
.hifi .hifi-ctl{display:flex;align-items:center;gap:6px;flex:0 0 auto}
.hifi .hifi-ctl button{background:#2a2a2a;border:none;border-radius:6px;color:#9cc0ff;cursor:pointer;width:44px;height:44px;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent;touch-action:manipulation}
.hifi .hifi-ctl button:active{background:#333;transform:scale(0.93)}
.hifi .hifi-ctl svg{width:20px;height:20px;fill:currentColor}
.hifi.noctl .hifi-ctl button{display:none}
</style>

<div class="wda hifi<?= $hifiCtl ? '' : ' noctl' ?>" id="hifi">
  <div class="wda-cat">
    <!-- Kolejnosc (2026-10-08, user): naglowek ze zrodlem, glosnosc, ZAWARTOSC zrodla, przelaczniki zrodel na dole.
         Zawartosc to dzis blok Spotify (okladka, tytul, przyciski); dla CD / OPT dojdzie wlasny blok w tym samym miejscu. -->
    <div class="klm-r">
      <div class="wsw-lbl"><span class="ic">🎵</span>HiFi<span class="hifi-src" id="hifi-src">…</span></div>
    </div>
    <div class="klm-r">
      <div class="wsw-lbl">Głośność</div>
      <!-- Glosnosc: trojkaty dol / gora w stylu przyciskow prev / next (2026-10-09, user: zamiast − / +) -->
      <div class="hifi-ctl">
        <button data-d="-1" title="Ciszej"><svg viewBox="0 0 24 24"><path d="M5 8h14l-7 9z"/></svg></button>
        <span class="hifi-vol" id="hifi-vol">–</span>
        <button data-d="1" title="Głośniej"><svg viewBox="0 0 24 24"><path d="M5 16h14l-7-9z"/></svg></button>
      </div>
    </div>
    <!-- CO GRA: okladka, tytul, wykonawca — ukryte, gdy nic nie gra albo zrodlo nie podaje tytulu -->
    <div class="hifi-now" id="hifi-now" style="display:none">
      <img id="hifi-cover" alt="">
      <div class="tx"><div class="t" id="hifi-title"></div><div class="a" id="hifi-artist"></div></div>
      <div class="hifi-ctl">
        <button data-ctl="previous" title="Poprzedni"><svg viewBox="0 0 24 24"><path d="M6 5h2v14H6zM20 5v14L9 12z"/></svg></button>
        <button data-ctl="pp" id="hifi-pp" title="Stop / graj"><svg viewBox="0 0 24 24"><path id="hifi-pp-ic" d="M6 6h12v12H6z"/></svg></button>
        <button data-ctl="next" title="Następny"><svg viewBox="0 0 24 24"><path d="M16 5h2v14h-2zM4 5v14l11-7z"/></svg></button>
      </div>
    </div>
    <!-- Przelaczniki zrodel (CD / OPT / SPOTIFY) usuniete 2026-10-08: CD i wejscia obsluguje glowny procesor
         wiezy, ktorego API sieciowe nie widzi (przy CD / OPT zglasza tylko „stopped”). Wroca z nadajnikiem IR (ESP32).
         Spotify wznawia przycisk „graj”. -->
    <div class="hifi-msg" id="hifi-msg"></div>
  </div>
</div>

<script>
(function(){
  var $w = $('#hifi');
  if (!$w.length || $w.data('init')) return;
  $w.data('init', 1);

  var last = null;   // ostatni grany utwor — zostaje na ekranie po zatrzymaniu
  function msg(t){ $('#hifi-msg').text(t || ''); }

  function show(d){
    if (!d || !d.ok) {
      $('#hifi-vol').text('–'); $('#hifi-src').text(d && d.offline ? 'wyłączona / brak połączenia' : '');
      return;
    }
    $('#hifi-vol').text(d.volume).toggleClass('mute', !!d.mute);
    // Gdy Spotify nie gra, wieza nie mowi, czy stoi, czy gra CD / OPT (TV) — wiec bez „zatrzymane”, tylko „nie gra”.
    if (d.title) last = {title: d.title, artist: [d.artist, d.album].filter(Boolean).join(' · '), cover: d.cover, src: d.src};
    else if (!last && d.last) last = {title: d.last.title, artist: [d.last.artist, d.last.album].filter(Boolean).join(' · '), cover: d.last.cover, src: d.last.src};
    var src = d.state === 'playing' ? (d.srcName || '—') : (d.state === 'paused' ? (d.srcName || '') + ' (pauza)' : 'Spotify nie gra');
    $('#hifi-src').text(src);
    // Ikona srodkowego przycisku: gdy gra — STOP (kwadrat), inaczej trojkat „graj”. Pauza w Spotify Connect
    // i tak zatrzymuje odtwarzanie i zrywa sesje (stan „stopped”), wiec kwadrat mowi prawde.
    $('#hifi-pp-ic').attr('d', d.state === 'playing' ? 'M6 6h12v12H6z' : 'M7 5v14l12-7z');
    $('#hifi-pp').data('state', d.state);
    // Po pauzie Spotify Connect zglasza „stopped” i nie podaje juz utworu. Ostatni utwor zostaje wtedy
    // na ekranie (wyszarzony), zeby bylo czym wznowic — przycisk „graj” wznawia sesje Spotify.
    if (last) {
      $('#hifi-title').text(last.title);
      $('#hifi-artist').text(last.artist);
      if (last.cover && $('#hifi-cover').attr('src') !== last.cover) $('#hifi-cover').attr('src', last.cover);
      $('#hifi-cover').toggle(!!last.cover);
      $('#hifi-now').toggleClass('idle', d.state !== 'playing').show();
    } else {
      $('#hifi-now').hide();
    }
  }

  function poll(){
    if (!$('#hifi').length) return;
    $.getJSON('api.php?action=hifi_state', show);
  }

  // Glosnosc: liczba zmienia sie OD RAZU (optymistycznie), a poll i tak potwierdzi stan z wiezy.
  $w.on('click', '.hifi-ctl button[data-d]', function(){
    var d = parseInt($(this).attr('data-d'), 10), cur = parseInt($('#hifi-vol').text(), 10);
    if (!isNaN(cur)) $('#hifi-vol').text(Math.max(0, Math.min(100, cur + d)));
    $.post('api.php', {action: 'hifi_set', op: 'vol', delta: d}, function(r){
      if (r && r.ok) $('#hifi-vol').text(r.volume); else poll();
    }, 'json');
  });

  $w.on('click', '.hifi-ctl button[data-ctl]', function(){
    var c = $(this).attr('data-ctl');
    if (c === 'pp') {
      var st = $('#hifi-pp').data('state');
      if (st === 'playing') c = 'pause';
      else if (st === 'paused') c = 'play';
      else if (last && last.src === 'spotify') {
        // Zatrzymany Spotify: wznowienie ostatniej sesji (to samo, co przycisk SPOTIFY)
        $.post('api.php', {action: 'hifi_set', op: 'src', src: 'spotify'}, function(r){
          if (!r || !r.ok) msg((r && r.error) || 'Nie udało się wznowić');
          setTimeout(poll, 1500);
        }, 'json');
        return;
      }
      else c = 'play';
    }
    $.post('api.php', {action: 'hifi_set', op: 'ctl', ctl: c}, function(){ setTimeout(poll, 800); }, 'json');
  });


  poll();
  if (window.hifiPollTimer) clearInterval(window.hifiPollTimer);
  window.hifiPollTimer = setInterval(poll, 3000);   // nowy utwor pokazuje sie najpozniej po 3 s
  if (typeof cardOnUnload === 'function') cardOnUnload(function(){ clearInterval(window.hifiPollTimer); window.hifiPollTimer = null; });
})();
</script>
