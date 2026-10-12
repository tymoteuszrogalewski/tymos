<?php
// card_hifi — wieza Technics SC-C70MK2, minimalistyczny pasek pod kamera dzwonka (2026-10-12, wariant E z mockupow).
// Zastepuje stara karte (naglowek, wiersz glosnosci, co gra) i nakladke z przyciskami na kadrze dzwonka:
// na jasnym niebie przyciski na obrazie byly slabo widoczne, a tu stoja na stalym, ciemnym tle.
//   wiersz 1 (HIFI_TITLE_VISIBLE): okladka, tytul, wykonawca · zrodlo
//   wiersz 2: poprzedni / stop-graj / nastepny oraz ciszej / glosnosc / glosniej — szerokie pola dotyku
// Steruje lokalnym API wiezy (inc/hifi.inc.php, endpointy hifi_state / hifi_set) — bez aplikacji Technicsa i bez pilota.
// Wieza sama sie wlacza, gdy cos zacznie na niej grac (na kablu LAN), i sama wylacza po czasie,
// wiec przycisku zasilania nie ma. Przelacznikow zrodel nie ma (CD / OPT tylko przez IR).
$hifiTitle = !defined('HIFI_TITLE_VISIBLE') || HIFI_TITLE_VISIBLE;
?>
<style>
.hifi .wda-cat{padding:8px;display:flex;flex-direction:column;gap:8px}
.hifi .hifi-now{display:flex;align-items:center;gap:10px;min-height:44px}
.hifi .hifi-now img{width:44px;height:44px;border-radius:6px;object-fit:cover;background:#262626;flex:0 0 44px}
.hifi .hifi-now .tx{min-width:0;flex:1;white-space:nowrap}
.hifi .hifi-now .t{font-size:15px;font-weight:600;color:#eee;overflow:hidden;text-overflow:ellipsis}
.hifi .hifi-now .a{font-size:12px;color:#999;overflow:hidden;text-overflow:ellipsis}
.hifi .hifi-now.idle img,.hifi .hifi-now.idle .tx{opacity:.45}
.hifi .hifi-row{display:flex;gap:8px}
.hifi .hifi-grp{display:flex;align-items:center;flex:1;background:rgba(255,255,255,0.06);border-radius:10px}
.hifi .hifi-grp button{flex:1;height:44px;background:none;border:none;border-radius:10px;color:#ddd;cursor:pointer;padding:0;
  display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent;touch-action:manipulation}
.hifi .hifi-grp button:active{background:rgba(255,255,255,0.08);transform:scale(0.93)}
.hifi .hifi-grp svg{width:28px;height:28px;fill:currentColor;display:block}
.hifi .hifi-grp #hifi-pp{color:#4a9eff}
.hifi .hifi-vol{flex:0 0 40px;text-align:center;font:700 18px/1 system-ui,sans-serif;color:#fff}
.hifi .hifi-vol.mute{color:#666;text-decoration:line-through}
</style>

<div class="wda hifi" id="hifi">
  <div class="wda-cat">
    <?php if ($hifiTitle): ?>
    <!-- CO GRA: ostatni utwor zostaje po zatrzymaniu (wyszarzony), zeby bylo czym wznowic -->
    <div class="hifi-now idle" id="hifi-now">
      <img id="hifi-cover" alt="" style="display:none">
      <div class="tx"><div class="t" id="hifi-title">HiFi</div><div class="a" id="hifi-artist">…</div></div>
    </div>
    <?php endif; ?>
    <div class="hifi-row">
      <div class="hifi-grp">
        <button data-ctl="previous" title="Poprzedni"><svg viewBox="0 0 24 24"><path d="M6 5h2v14H6zM20 5v14L9 12z"/></svg></button>
        <button data-ctl="pp" id="hifi-pp" title="Stop / graj"><svg viewBox="0 0 24 24"><path id="hifi-pp-ic" d="M7 5v14l12-7z"/></svg></button>
        <button data-ctl="next" title="Następny"><svg viewBox="0 0 24 24"><path d="M16 5h2v14h-2zM4 5v14l11-7z"/></svg></button>
      </div>
      <div class="hifi-grp">
        <button data-d="-1" title="Ciszej"><svg viewBox="0 0 24 24"><path d="M5 8h14l-7 9z"/></svg></button>
        <span class="hifi-vol" id="hifi-vol">–</span>
        <button data-d="1" title="Głośniej"><svg viewBox="0 0 24 24"><path d="M5 16h14l-7-9z"/></svg></button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var $w = $('#hifi');
  if (!$w.length || $w.data('init')) return;
  $w.data('init', 1);

  var last = null;   // ostatni grany utwor — zostaje na ekranie po zatrzymaniu
  var st = '';

  function show(d){
    if (!d || !d.ok) {
      $('#hifi-vol').text('–'); st = '';
      if (!last) { $('#hifi-title').text('HiFi'); $('#hifi-artist').text(d && d.offline ? 'wyłączona / brak połączenia' : ''); }
      return;
    }
    $('#hifi-vol').text(d.volume).toggleClass('mute', !!d.mute);
    st = d.state;
    if (d.title) last = {title: d.title, artist: d.artist, cover: d.cover, src: d.src};
    else if (!last && d.last) last = {title: d.last.title, artist: d.last.artist, cover: d.last.cover, src: d.last.src};
    // Ikona srodkowego przycisku: gdy gra — STOP (kwadrat), inaczej trojkat „graj”. Pauza w Spotify Connect
    // i tak zatrzymuje odtwarzanie i zrywa sesje (stan „stopped”), wiec kwadrat mowi prawde.
    $('#hifi-pp-ic').attr('d', st === 'playing' ? 'M6 6h12v12H6z' : 'M7 5v14l12-7z');
    // Druga linia: wykonawca · zrodlo, gdy gra; inaczej wykonawca · pauza / nie gra
    var state = st === 'playing' ? (d.srcName || '') : (st === 'paused' ? 'pauza' : 'nie gra');
    if (last) {
      $('#hifi-title').text(last.title);
      $('#hifi-artist').text([last.artist, state].filter(Boolean).join(' · '));
      if (last.cover && $('#hifi-cover').attr('src') !== last.cover) $('#hifi-cover').attr('src', last.cover);
      $('#hifi-cover').toggle(!!last.cover);
    } else {
      $('#hifi-title').text('HiFi');
      $('#hifi-artist').text(st === 'playing' ? state : 'Spotify nie gra');
    }
    $('#hifi-now').toggleClass('idle', st !== 'playing');
  }

  function poll(){
    if (!$('#hifi').length) return;
    if (document.hidden) return;
    $.getJSON('api.php?action=hifi_state&_=' + Date.now(), show);
  }

  // Glosnosc: liczba zmienia sie OD RAZU (optymistycznie), a poll i tak potwierdzi stan z wiezy.
  $w.on('click', 'button[data-d]', function(){
    var d = parseInt($(this).attr('data-d'), 10), cur = parseInt($('#hifi-vol').text(), 10);
    if (!isNaN(cur)) $('#hifi-vol').text(Math.max(0, Math.min(100, cur + d)));
    $.post('api.php', {action: 'hifi_set', op: 'vol', delta: d}, function(r){
      if (r && r.ok) $('#hifi-vol').text(r.volume); else poll();
    }, 'json');
  });

  $w.on('click', 'button[data-ctl]', function(){
    var c = $(this).attr('data-ctl');
    if (c === 'pp') {
      if (st === 'playing') c = 'pause';
      else if (st === 'paused') c = 'play';
      else if (last && last.src === 'spotify') {
        // Zatrzymany Spotify: wznowienie ostatniej sesji
        $.post('api.php', {action: 'hifi_set', op: 'src', src: 'spotify'}, function(){ setTimeout(poll, 1500); }, 'json');
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
