<?php
/**
 * Dzwieki lektora — lista plikow z tymos/snd/ z odsluchem i tekstem do skopiowania.
 *
 * Teksty sa TU, a nie w bazie, bo powstaja razem z plikiem: user generuje mp3 w ElevenLabs
 * z konkretnej frazy i wgrywa gotowy plik. Trzymanie ich obok listy oznacza, ze przy nagrywaniu
 * nowej wersji nie trzeba szukac, co dokladnie bylo powiedziane.
 *
 * KONWENCJA TEKSTU (patrz reference_tymos_sounds): zaczynac od wielokropka — pierwsze ~0,3 s ginie
 * w halasie domu; konczyc kropka, bo bez niej glos brzmi urwany. „Uwaga." dla zdarzen bramkowanych
 * alarmem, „Alarm!" dla zalania, ktore leci niezaleznie od zazbrojenia.
 *
 * Nazwa dzwieku (kolumna `tymos_sounds.sound`) = nazwa pliku bez .mp3 — doorbell.inc.js bierze liste
 * wprost z katalogu snd/ (api tymos_sound_list), wiec ta tablica sluzy TYLKO opisom i tekstom.
 */
$GEN = 'https://elevenlabs.io/app/speech-synthesis/text-to-speech';

$SND = [
    ['file'=>'pralka.mp3',                    'opis'=>'Pralka skończyła',
     'txt'=>'... Pranie zakończone. Proszę przełożyć je do suszarki.'],
    ['file'=>'pralka_czeka.mp3',              'opis'=>'Pralka skończyła, suszarka jeszcze pracuje',
     'txt'=>'... Pranie zakończone. Ale suszarka jeszcze pracuje - dam znać kiedy skończy.'],
    ['file'=>'suszarka.mp3',                  'opis'=>'Suszarka skończyła',
     'txt'=>'... Suszenie zakończone. Proszę wyjąć pranie z suszarki.'],
    ['file'=>'suszarka_pralka.mp3',           'opis'=>'Suszarka skończyła, w pralce czeka wsad',
     'txt'=>'... Suszenie zakończone. Możesz już przełożyć ubrania czekające w pralce na suszenie.'],
    ['file'=>'gofrownica_off.mp3',            'opis'=>'Gofrownica wyłączona po 10 min',
     'txt'=>'... Gofrownica została wyłączona po dziesięciu minutach. Włącz ją ponownie, jeśli chcesz gofrować dalej.'],
    ['file'=>'alarm_parking.mp3',             'opis'=>'Osoba na parkingu',
     'txt'=>'... Uwaga. Wykryto osobę na parkingu.'],
    ['file'=>'alarm_ogrod.mp3',               'opis'=>'Osoba w ogrodzie',
     'txt'=>'... Uwaga. Wykryto osobę w ogrodzie.'],
    ['file'=>'alarm_garaz.mp3',               'opis'=>'Otwarcie drzwi garażu',
     'txt'=>'... Uwaga. Wykryto otwarcie drzwi w garażu.'],
    ['file'=>'alarm_przedsionek.mp3',       'opis'=>'Otwarcie drzwi wejściowych (przedsionek)',
     'txt'=>'... Uwaga. Wykryto otwarcie drzwi wejściowych.'],
    ['file'=>'alarm_zalanie_techniczny.mp3',  'opis'=>'Zalanie — pomieszczenie techniczne',
     'txt'=>'... Alarm! Zalanie w pomieszczeniu technicznym.'],
    ['file'=>'alarm_zalanie_kuchnia.mp3',     'opis'=>'Zalanie — kuchnia',
     'txt'=>'... Alarm! Zalanie w kuchni.'],
];
$dir = __DIR__ . '/../../snd/';
?>
<style>
.snd-row{display:flex;align-items:center;gap:12px;padding:10px 2px;border-bottom:1px solid rgba(255,255,255,0.06)}
.snd-row:last-child{border-bottom:none}
.snd-play{flex:0 0 auto;width:40px;height:40px;border:none;border-radius:50%;background:#2a2a2a;color:#7fb0ff;
          cursor:pointer;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent}
.snd-play:active{background:#3a3a3a}
.snd-play.playing{background:#1c3350;color:#9cf}
.snd-mid{flex:1;min-width:0}
.snd-name{font-size:13px;font-weight:600;color:#ddd}
.snd-file{font-size:11px;color:#666;font-family:ui-monospace,Menlo,monospace}
.snd-txt{font-size:12px;color:#9a9a9a;margin-top:3px;line-height:1.35}
.snd-miss{color:#ff6b6b;font-size:11px}
.snd-copy{flex:0 0 auto;background:#2a2a2a;border:none;border-radius:6px;color:#7799cc;cursor:pointer;
          padding:7px 14px;font-size:12px;-webkit-tap-highlight-color:transparent}
.snd-copy:active,.snd-copy.done{background:#1c3350;color:#9cf}
.snd-gen{display:block;margin-top:14px;padding:10px 2px;font-size:13px;color:#7fb0ff;text-decoration:none}
.snd-gen span{color:#666;font-size:11px;display:block;margin-top:2px}
</style>

<div class="card-title">Dźwięki lektora</div>
<div id="sndList">
<?php foreach ($SND as $s):
    $exists = is_file($dir . $s['file']); ?>
  <div class="snd-row">
    <button class="snd-play" data-src="snd/<?= rawurlencode($s['file']) ?>" title="Odsłuchaj"<?= $exists ? '' : ' disabled style="opacity:.35"' ?>>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
    </button>
    <div class="snd-mid">
      <div class="snd-name"><?= htmlspecialchars($s['opis']) ?></div>
      <div class="snd-file"><?= htmlspecialchars($s['file']) ?><?= $exists ? '' : ' <span class="snd-miss">— BRAK PLIKU</span>' ?></div>
      <div class="snd-txt"><?= htmlspecialchars($s['txt']) ?></div>
    </div>
    <button class="snd-copy" data-txt="<?= htmlspecialchars($s['txt'], ENT_QUOTES) ?>">copy</button>
  </div>
<?php endforeach; ?>
</div>

<a class="snd-gen" href="<?= $GEN ?>" target="_blank" rel="noopener">
  Generator głosu — ElevenLabs
  <span>głos „asystent męski”; gotowy plik wrzuć do tymos/snd/ pod nazwą z listy</span>
</a>

<script>
(function(){
  var $c = $('#sndList');
  if (!$c.length || $c.data('init')) return;
  $c.data('init', 1);

  // Zwykly <audio>, nie WebAudio: to panel admina otwierany palcem, wiec gest usera jest zawsze,
  // a odsluch ma byc natychmiastowy bez dekodowania bufora. Jeden odtwarzacz na cala liste —
  // klik w kolejny dzwiek przerywa poprzedni, zamiast nakladac sciezki.
  var au = null, cur = null;
  function stop() {
    if (au) { au.pause(); au = null; }
    if (cur) { cur.classList.remove('playing'); cur = null; }
  }
  $c.on('click', '.snd-play', function(){
    var b = this;
    if (cur === b) { stop(); return; }
    stop();
    au = new Audio(b.getAttribute('data-src') + '?_=' + Date.now());
    cur = b; b.classList.add('playing');
    au.onended = au.onerror = function(){ stop(); };
    au.play().catch(function(){ stop(); });
  });

  $c.on('click', '.snd-copy', function(){
    var b = this, t = b.getAttribute('data-txt');
    function ok(){ b.classList.add('done'); b.textContent = 'skopiowane'; setTimeout(function(){ b.classList.remove('done'); b.textContent = 'copy'; }, 1200); }
    // navigator.clipboard wymaga HTTPS albo localhost — TymOS jedzie po HTTPS, ale gdyby
    // kiedys poszlo po IP bez certu, zostaje fallback na ukryte pole i execCommand.
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(t).then(ok).catch(fallback);
    } else fallback();
    function fallback(){
      var ta = document.createElement('textarea');
      ta.value = t; ta.style.cssText = 'position:fixed;opacity:0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); ok(); } catch(e) {}
      document.body.removeChild(ta);
    }
  });
})();
</script>
