<?php
// Konfiguracja podlewania — strefy + godziny okien. Dociagana przez cnt.php dopiero po kliknieciu
// w zebatke przy naglowku "Podlewanie" w widgecie klimatu (card_klimat.inc.php), zeby nie siedziala
// w pamieci iPada, gdy nikt jej nie oglada. Karta CELOWO nie jest wpisana w index.inc.php —
// scanCards() skanuje pliki, wiec cnt.php ja poda, a layout jej sam nie renderuje.
//
// Globalnego wlacznika podlewania tu NIE MA: rzadzi helper `irrigation_mode` (Auto/MANUAL/OFF
// w widgecie klimatu), a stary `irrigation_enabled` jest w actions/irrigation.php juz tylko
// fallbackiem migracyjnym.
//
// Zrodlo: dawny panel_podlewanie (skasowany 2026-08-22, historia w repo).
?>
<div id="irr-zones"></div>
<div style="margin-top:12px">
  <button id="irr-add-zone" style="background:#f9a825;color:#000;font-weight:600;border:none;border-radius:8px;padding:10px 22px;font-size:14px;cursor:pointer">+ Dodaj strefe</button>
</div>

<style>
.irr-zone{background:rgba(255,255,255,0.04);border-radius:10px;padding:14px;margin-bottom:10px}
.irr-zone .irr-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.irr-zone .irr-row:last-child{margin-bottom:0}
.irr-select{background:rgba(255,255,255,0.06);border:none;border-radius:10px;color:var(--text);padding:12px 14px;font-size:16px;outline:none;box-sizing:border-box;-webkit-appearance:none;padding-right:28px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%239a9a9a'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center}
.irr-days{gap:5px}
.irr-days .irr-day-btn{flex:1;min-width:32px;padding:8px 2px;font-size:12px}
.irr-mode-toggle{flex:0 0 auto}
</style>

<script>
(function(){
  var zones = [], channels = [];

  function load(cb) {
    $.getJSON('api.php?action=irrigation', function(d) {
      if (!d.ok) return;
      zones = d.zones || [];
      channels = d.channels || [];
      render();
      if (cb) cb();
    });
  }

  function save(zoneId, field, value) {
    var data = {action:'irrigation', sub_action:'update_zone', zone_id:zoneId};
    data[field] = value;
    $.post('api.php', data);
  }

  function render() {
    var $c = $('#irr-zones').empty();
    zones.forEach(function(z, i) {
      var $z = $('<div class="irr-zone" data-id="' + z.id + '">');

      // Row 1: kanal (flex:1) + toolbar (▲▼✕) po prawej
      var $r1 = $('<div class="irr-row">');
      var $chSel = $('<select class="irr-select irr-channel" style="width:100%"><option value="">-- kanal --</option></select>');
      var chFound = !z.dev_channel;
      channels.forEach(function(ch) {
        if (ch.value === z.dev_channel) chFound = true;
        $chSel.append('<option value="' + ch.value + '"' + (ch.value === z.dev_channel ? ' selected' : '') + '>' + $('<span>').text(ch.label).html() + '</option>');
      });
      if (!chFound) {
        $chSel.prepend('<option value="' + z.dev_channel + '" selected style="color:#f44336">Usuniety (' + z.dev_channel + ')</option>');
      }
      $r1.append($('<div style="flex:1;min-width:0">').append($chSel));
      var $tb = $('<div class="item-toolbar">');
      if (i > 0)                $tb.append('<span class="move-btn irr-move" data-dir="up">▲</span>');
      if (i < zones.length - 1) $tb.append('<span class="move-btn irr-move" data-dir="down">▼</span>');
      $tb.append('<span class="move-btn danger irr-del">✕</span>');
      $r1.append($tb);
      $z.append($r1);

      // Row 2: toggle + czas (lewo) + mode buttons (prawo)
      var $r2 = $('<div class="irr-row">');
      $r2.append('<label class="toggle" style="margin:0"><input type="checkbox" class="irr-enabled"' + (z.enabled==='1'||z.enabled===1 ? ' checked' : '') + '><span></span></label>');
      var $dur = $('<select class="irr-select irr-duration" style="width:92px"></select>');
      for (var m = 5; m <= 60; m += 5) {
        $dur.append('<option value="' + m + '"' + (parseInt(z.duration_min)==m ? ' selected' : '') + '>' + m + ' min</option>');
      }
      $r2.append($dur);
      // Dwa niezalezne toggle: Rano / Wiecz. (mode: oba=oba, tylko rano=rano, tylko wieczor=wieczor, oba off=none)
      var $modeWrap = $('<div style="display:flex;gap:6px;margin-left:auto">');
      var ranoOn = (z.mode === 'rano' || z.mode === 'oba');
      var wiecOn = (z.mode === 'wieczor' || z.mode === 'oba');
      $modeWrap.append('<button class="pill-btn icon irr-mode-toggle' + (ranoOn ? ' active' : '') + '" data-part="rano" title="Rano">🌞</button>');
      $modeWrap.append('<button class="pill-btn icon irr-mode-toggle' + (wiecOn ? ' active' : '') + '" data-part="wieczor" title="Wieczór">🌛</button>');
      $r2.append($modeWrap);
      $z.append($r2);

      // Row 3: dni tygodnia (Pn..Nd) — maska 7 znakow 0/1, domyslnie wszystkie dni
      var days = (typeof z.days === 'string' && z.days.length === 7) ? z.days : '1111111';
      var $r3 = $('<div class="irr-row irr-days">');
      ['Pn','Wt','Śr','Cz','Pt','So','Nd'].forEach(function(lbl, di){
        $r3.append('<button class="pill-btn icon irr-day-btn' + (days[di]==='1' ? ' active' : '') + '" data-day="' + di + '">' + lbl + '</button>');
      });
      $z.append($r3);

      $c.append($z);
    });
  }

  // Events (delegated) — off() zapobiega duplikatom przy ponownym ladowaniu karty
  var $z = $('#irr-zones');
  $z.off('.irr');
  $z.on('click.irr', '.irr-move', function(){
    var zid = $(this).closest('.irr-zone').data('id');
    var dir = $(this).data('dir');
    $.post('api.php', {action:'irrigation', sub_action:'move_zone', zone_id:zid, direction:dir}, function(){ load(); });
  });
  $z.on('change.irr', '.irr-channel', function(){
    save($(this).closest('.irr-zone').data('id'), 'dev_channel', $(this).val());
  });
  $z.on('change.irr', '.irr-duration', function(){
    save($(this).closest('.irr-zone').data('id'), 'duration_min', $(this).val());
  });
  $z.on('change.irr', '.irr-enabled', function(){
    save($(this).closest('.irr-zone').data('id'), 'enabled', this.checked ? 1 : 0);
  });
  $z.on('click.irr', '.irr-mode-toggle', function(){
    var $zone = $(this).closest('.irr-zone'), zid = $zone.data('id');
    $(this).toggleClass('active');
    var rano = $zone.find('.irr-mode-toggle[data-part=rano]').hasClass('active');
    var wiec = $zone.find('.irr-mode-toggle[data-part=wieczor]').hasClass('active');
    var mode = (rano && wiec) ? 'oba' : (rano ? 'rano' : (wiec ? 'wieczor' : 'none'));
    save(zid, 'mode', mode);
  });
  $z.on('click.irr', '.irr-day-btn', function(){
    var $zone = $(this).closest('.irr-zone'), zid = $zone.data('id');
    $(this).toggleClass('active');
    var mask = '';
    $zone.find('.irr-day-btn').each(function(){ mask += $(this).hasClass('active') ? '1' : '0'; });
    save(zid, 'days', mask);
  });
  $z.on('click.irr', '.irr-del', function(){
    var $btn = $(this);
    if ($btn.hasClass('confirming')) {
      var zid = $btn.closest('.irr-zone').data('id');
      $.post('api.php', {action:'irrigation', sub_action:'delete_zone', zone_id:zid}, function(){ load(); });
      return;
    }
    $btn.addClass('confirming').text('✓').css({background:'var(--red,#e74c3c)', color:'#fff'});
    setTimeout(function(){
      if ($btn.hasClass('confirming')) $btn.removeClass('confirming').text('✕').css({background:'', color:''});
    }, 3000);
  });
  $('#irr-add-zone').off('click').on('click', function(){
    var $b = $(this);
    if ($b.prop('disabled')) return;
    $b.prop('disabled', true);
    $.post('api.php', {action:'irrigation', sub_action:'add_zone'}, function(){ load(function(){ $b.prop('disabled', false); }); });
  });

  load();
})();
</script>

<?php
$helpers = [];
$r = $db->query("SELECT name, value FROM helpers WHERE name LIKE 'irrigation_%'");
if ($r) while ($row = $r->fetch_assoc()) $helpers[$row['name']] = $row['value'];
$morning = $helpers['irrigation_morning_hour'] ?? '06:00';
$evening = $helpers['irrigation_evening_hour'] ?? '20:00';
$frost = $helpers['irrigation_frost_threshold'] ?? '5';
$mH = (int)explode(':', $morning)[0];
$eH = (int)explode(':', $evening)[0];
?>
<div style="border-top:1px solid rgba(255,255,255,0.08);margin:16px 0 12px"></div>

<style>
.irr-set-row{display:flex;gap:12px;flex-wrap:wrap;align-items:stretch}
.irr-set-box{display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(255,255,255,0.04);border-radius:10px;padding:14px 18px}
.irr-set-label{font-size:11px;color:var(--text-dim);margin-bottom:6px}
.irr-set-ctrl{display:flex;align-items:center;gap:0}
.irr-set-btn{width:40px;height:40px;border:none;border-radius:8px;background:rgba(255,255,255,0.08);color:var(--text);font-size:20px;cursor:pointer;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent;touch-action:manipulation;user-select:none}
.irr-set-btn:active{background:rgba(255,255,255,0.15)}
.irr-set-val{min-width:44px;text-align:center;font-size:18px;font-weight:600;color:var(--text);padding:0 8px}
</style>

<div class="irr-set-row">
  <div class="irr-set-box">
    <div class="irr-set-label">Rano</div>
    <div class="irr-set-ctrl">
      <button class="irr-set-btn" data-target="irr-mh" data-step="-1">−</button>
      <div class="irr-set-val" id="irr-mh"><?= $mH ?></div>
      <button class="irr-set-btn" data-target="irr-mh" data-step="1">+</button>
    </div>
  </div>
  <div class="irr-set-box">
    <div class="irr-set-label">Wieczor</div>
    <div class="irr-set-ctrl">
      <button class="irr-set-btn" data-target="irr-eh" data-step="-1">−</button>
      <div class="irr-set-val" id="irr-eh"><?= $eH ?></div>
      <button class="irr-set-btn" data-target="irr-eh" data-step="1">+</button>
    </div>
  </div>
  <div class="irr-set-box">
    <div class="irr-set-label">Przymrozki — pomija rano</div>
    <div class="irr-set-ctrl">
      <button class="irr-set-btn" data-target="irr-frost" data-step="-1">−</button>
      <div class="irr-set-val" id="irr-frost"><?= (int)$frost ?>°C</div>
      <button class="irr-set-btn" data-target="irr-frost" data-step="1">+</button>
    </div>
  </div>
</div>

<script>
(function(){
  function saveH(name, val) {
    $.post('api.php', {action:'irrigation', sub_action:'update_helper', helper_name:name, helper_value:val});
  }

  function handleBtn() {
    var target = $(this).data('target');
    var step = parseInt($(this).data('step'));
    var $val = $('#' + target);
    var cur = parseInt($val.text());

    if (target === 'irr-mh') {
      var nv = Math.min(12, Math.max(3, cur + step));
      $val.text(nv);
      saveH('irrigation_morning_hour', (nv < 10 ? '0' : '') + nv + ':00');
    } else if (target === 'irr-eh') {
      var nv = Math.min(23, Math.max(17, cur + step));
      $val.text(nv);
      saveH('irrigation_evening_hour', (nv < 10 ? '0' : '') + nv + ':00');
    } else if (target === 'irr-frost') {
      var nv = Math.min(15, Math.max(-5, cur + step));
      $val.text(nv + '\u00B0C');
      saveH('irrigation_frost_threshold', nv);
    }
  }
  $('.irr-set-btn').off('click').on('click', handleBtn);
})();
</script>
