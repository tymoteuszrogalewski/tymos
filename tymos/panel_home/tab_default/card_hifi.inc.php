<?php
// card_hifi — wieza Technics SC-C70MK2, minimalistyczny pasek pod kamera dzwonka (2026-10-12, wariant E z mockupow).
// Zastepuje stara karte (naglowek, wiersz glosnosci, co gra) i nakladke z przyciskami na kadrze dzwonka:
// na jasnym niebie przyciski na obrazie byly slabo widoczne, a tu stoja na stalym, ciemnym tle.
//   wiersz 1 (HIFI_TITLE_VISIBLE): okladka, tytul, wykonawca · zrodlo
//   wiersz 2: poprzedni / pauza-graj (pauza = wyciszenie) / nastepny / stop (prawdziwe zatrzymanie, wieza sama sie potem wylacza) oraz ciszej / glosnosc / glosniej — szerokie pola dotyku
// Steruje lokalnym API wiezy (inc/hifi.inc.php, endpointy hifi_state / hifi_set) — bez aplikacji Technicsa i bez pilota.
// Wieza sama sie wlacza, gdy cos zacznie na niej grac (na kablu LAN), i sama wylacza po czasie,
// wiec przycisku zasilania nie ma. Przelacznikow zrodel nie ma (CD / OPT tylko przez IR).
$hifiTitle = defined('HIFI_TITLE_VISIBLE') && HIFI_TITLE_VISIBLE;   // domyslnie ukryty (2026-10-12, user), wlacza sie w configu
?>
<style>
/* Bez ramki karty (2026-10-12, user: szkoda miejsca) — zostaja same dwie szare grupy przyciskow */
.hifi .wda-cat{padding:0;background:none;border:none;display:flex;flex-direction:column;gap:8px}
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
.hifi .hifi-vol svg{width:26px;height:26px;fill:#888;display:block;margin:0 auto}
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
        <button data-ctl="pp" id="hifi-pp" title="Pauza (wyciszenie) / graj"><svg viewBox="0 0 24 24"><path id="hifi-pp-ic" d="M7 5v14l12-7z"/></svg></button>
        <button data-ctl="next" title="Następny"><svg viewBox="0 0 24 24"><path d="M16 5h2v14h-2zM4 5v14l11-7z"/></svg></button>
        <!-- STOP (2026-10-12, user): bez niego wieza nigdy sie nie wylaczy, bo pauza tylko wycisza i muzyka gra dalej -->
        <button data-ctl="stop" title="Stop"><svg viewBox="0 0 24 24"><path d="M6 6h12v12H6z"/></svg></button>
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
  var st = '', mute = false;   // mute = pauza z panelu (wyciszenie, patrz klik w srodkowy przycisk)
  var vol = null;   // glosnosc z wiezy; przy wyciszeniu w polu liczby stoi przekreslony glosnik
  var SPK_X = '<svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9z"/><path d="M16 9l6 6M22 9l-6 6" stroke="#888" stroke-width="2" stroke-linecap="round" fill="none"/></svg>';
  function paintVol(){
    if (mute) $('#hifi-vol').html(SPK_X);
    else $('#hifi-vol').text(vol === null ? '–' : vol);
  }

  function show(d){
    if (!d || !d.ok) {
      vol = null; st = ''; paintVol();
      if (!last) { $('#hifi-title').text('HiFi'); $('#hifi-artist').text(d && d.offline ? 'wyłączona / brak połączenia' : ''); }
      return;
    }
    st = d.state;
    mute = !!d.mute;
    vol = d.volume;
    paintVol();
    if (d.title) last = {title: d.title, artist: d.artist, cover: d.cover, src: d.src};
    else if (!last && d.last) last = {title: d.last.title, artist: d.last.artist, cover: d.last.cover, src: d.last.src};
    paintPp();
    // Druga linia: wykonawca · zrodlo, gdy gra; inaczej wykonawca · pauza / nie gra
    var state = st === 'playing' ? (mute ? 'wyciszone' : (d.srcName || '')) : (st === 'paused' ? 'pauza' : 'nie gra');
    if (last) {
      $('#hifi-title').text(last.title);
      $('#hifi-artist').text([last.artist, state].filter(Boolean).join(' · '));
      if (last.cover && $('#hifi-cover').attr('src') !== last.cover) $('#hifi-cover').attr('src', last.cover);
      $('#hifi-cover').toggle(!!last.cover);
    } else {
      $('#hifi-title').text('HiFi');
      $('#hifi-artist').text(st === 'playing' ? state : 'Spotify nie gra');
    }
    $('#hifi-now').toggleClass('idle', st !== 'playing' || mute);
  }

  // Ikona srodkowego przycisku: gra i nie jest wyciszona = PAUZA (dwie kreski), inaczej trojkat „graj”.
  function paintPp(){
    $('#hifi-pp-ic').attr('d', st === 'playing' && !mute ? 'M6 5h4v14H6zM14 5h4v14h-4z' : 'M7 5v14l12-7z');
  }
  function setMute(on){
    mute = on; paintPp(); paintVol();
    $.post('api.php', {action: 'hifi_set', op: 'mute', on: on ? 1 : 0}, function(){ setTimeout(poll, 800); }, 'json');
  }

  function poll(){
    if (!$('#hifi').length) return;
    if (document.hidden) return;
    $.getJSON('api.php?action=hifi_state&_=' + Date.now(), show);
  }

  // Glosnosc: liczba zmienia sie OD RAZU (optymistycznie), a poll i tak potwierdzi stan z wiezy.
  $w.on('click', 'button[data-d]', function(){
    var d = parseInt($(this).attr('data-d'), 10);
    if (vol !== null) { vol = Math.max(0, Math.min(100, vol + d)); paintVol(); }
    $.post('api.php', {action: 'hifi_set', op: 'vol', delta: d}, function(r){
      if (r && r.ok) { vol = r.volume; paintVol(); } else poll();
    }, 'json');
  });

  $w.on('click', 'button[data-ctl]', function(){
    var c = $(this).attr('data-ctl');
    if (c === 'stop') {
      // Prawdziwe zatrzymanie Spotify (pause = koniec sesji Spotify Connect) + odciszenie, zeby wieza
      // nie zostala wyciszona dla nastepnego zrodla (telefon, TV). Po czasie bez grania wieza sama sie wylacza.
      if (mute) setMute(false);
      c = 'pause';
    }
    else     if (c === 'pp') {
      // PAUZA = WYCISZENIE (2026-10-12): pause w Spotify Connect zrywa sesje i „graj” zaczyna playliste od nowa.
      // Wyciszona muzyka gra dalej po cichu, a odciszenie wraca do tej samej glosnosci (wieza jej nie zmienia).
      // Wyciszona i zatrzymana (np. wieza wylaczyla sie w trakcie): odcisza i wznawia jednym kliknieciem.
      if (mute) { setMute(false); if (st === 'playing') return; }
      else if (st === 'playing') { setMute(true); return; }
      if (st === 'paused') c = 'play';
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
