// TymOS panel_admin → Akcje — builder UI (triggers, conditions, actions).
// Wymaga: window.triggersData, window.conditionsData, window.actionsData, window._devList,
// window._devFields, window._helpers. Ladowane przez card_main.inc.php <script>.

var _triggerTypes = {state:'Stan urządzenia',cron:'Cykliczne (CRON)',sun:'Słońce (wschód/zachód)'};

function _loadFields(devId, cb) {
  window._devFields = window._devFields || {};
  window._devFieldsLoading = window._devFieldsLoading || {};
  if (window._devFields[devId]) { cb(window._devFields[devId]); return; }
  if (window._devFieldsLoading[devId]) { setTimeout(function(){_loadFields(devId,cb)},100); return; }
  window._devFieldsLoading[devId] = true;
  $.getJSON('api.php', {action:'device_fields', dev_id:devId}, function(data) {
    var map = {};
    data.forEach(function(d) { map[d.f] = {label:d.label, on:d.on, val:d.val, unit:d.unit, distinct:d.distinct}; });
    window._devFields[devId] = map;
    delete window._devFieldsLoading[devId];
    cb(map);
  });
}

function renderTriggers() {
  var $area = $('#triggers_area').empty();
  triggersData.forEach(function(tr, i) {
    var hasBtns = triggersData.length > 1;
    var $card = $('<div class="sub-card">');
    if (i > 0) $area.append('<div class="logic-sep">LUB (OR)</div>');
    if (hasBtns) {
      var $btns = $('<div class="item-toolbar" style="justify-content:flex-end;margin-bottom:8px">');
      if (i > 0) $btns.append('<span class="move-btn" onclick="moveItem(triggersData,'+i+',-1,renderTriggers)">▲</span>');
      if (i < triggersData.length-1) $btns.append('<span class="move-btn" onclick="moveItem(triggersData,'+i+',1,renderTriggers)">▼</span>');
      $btns.append('<span class="move-btn danger" onclick="triggersData.splice('+i+',1);renderTriggers()">✕</span>');
      $card.append($btns);
    }
    var $typeSel = $('<select data-i="'+i+'" class="tr-type" style="width:100%;margin-bottom:10px">');
    $.each(_triggerTypes, function(k,v){ $typeSel.append('<option value="'+k+'"'+(tr.type===k?' selected':'')+'>'+v+'</option>'); });
    $card.append($typeSel);
    var t = tr.type || 'state';
    if (t === 'cron') {
      var cronPresets = {'* * * * *':'Co minutę','*/2 * * * *':'Co 2 minuty','*/5 * * * *':'Co 5 minut','*/10 * * * *':'Co 10 minut','*/15 * * * *':'Co 15 minut','*/30 * * * *':'Co 30 minut','0 * * * *':'Co godzinę','_custom':'Własne wyrażenie CRON...'};
      var curCron = tr.cron || '* * * * *';
      var isCustom = !(curCron in cronPresets);
      var $presetSel = $('<select class="tr-cron-preset" data-i="'+i+'" style="width:100%;margin-bottom:8px">');
      $.each(cronPresets, function(k,v){ $presetSel.append('<option value="'+k+'"'+(((!isCustom && k===curCron)||(isCustom && k==='_custom'))?' selected':'')+'>'+v+'</option>'); });
      var $customInput = $('<input type="text" class="tr-cron" data-i="'+i+'" value="'+curCron+'" placeholder="* * * * *" style="width:100%;margin-top:8px;'+(isCustom?'':'display:none')+'">');
      $card.append('<div class="form-group"><label>Częstotliwość</label></div>');
      $card.find('.form-group').last().append($presetSel).append($customInput);
    } else if (t === 'sun') {
      $card.append('<div class="form-row"><div class="form-group"><label>Typ</label><select class="tr-sun-type" data-i="'+i+'"><option value="sunrise"'+(tr.sun_type==='sunrise'?' selected':'')+'>Wschod</option><option value="sunset"'+(tr.sun_type==='sunset'?' selected':'')+'>Zachod</option></select></div><div class="form-group"><label>Offset (min)</label><input type="number" class="tr-sun-offset" data-i="'+i+'" value="'+(tr.sun_offset||0)+'" style="width:80px"></div></div>');
    } else {
      var $devSel = $('<select class="tr-dev w-full" data-i="'+i+'">');
      if (!tr.dev_id) $devSel.append('<option value="" selected>-- wybierz --</option>');
      if (tr.dev_id && !(window._devList||[]).some(function(d){return d.id==tr.dev_id;})) {
        $devSel.append('<option value="'+tr.dev_id+'" selected style="color:#f44336">⚠ Usunięte urządzenie (id='+tr.dev_id+')</option>');
      }
      (window._devList||[]).forEach(function(d){ $devSel.append('<option value="'+d.id+'"'+(d.id==tr.dev_id?' selected':'')+'>'+d.device+'</option>'); });
      var curDevId = tr.dev_id || '';
      var $fieldSel = $('<select class="tr-field w-full" data-i="'+i+'">');
      var fieldsMap = (window._devFields||{})[curDevId];
      if (!fieldsMap && curDevId) {
        $fieldSel.append('<option value="">Ładowanie...</option>');
        _loadFields(curDevId, function() { renderTriggers(); });
      } else {
        fieldsMap = fieldsMap || {};
        var trChLabels = {};
        (window._devList||[]).forEach(function(d){ if(d.id==curDevId && d.ch) trChLabels=d.ch; });
        var onFields = [], offFields = [];
        $.each(fieldsMap, function(f, info) {
          var label = (info.label || f);
          if ((f === 'switch_1' || f === 'state_l1') && trChLabels['1']) label = trChLabels['1'];
          if ((f === 'switch_2' || f === 'state_l2') && trChLabels['2']) label = trChLabels['2'];
          if (info.unit) label += ' (' + info.unit + ')';
          if (info.val) label += ' [' + info.val + ']';
          if (info.on) onFields.push({f:f, label:label});
          else offFields.push({f:f, label:label});
        });
        onFields.forEach(function(o){ $fieldSel.append('<option value="'+o.f+'"'+(o.f===tr.field?' selected':'')+'>'+o.label+'</option>'); });
        if (offFields.length && onFields.length) $fieldSel.append('<option disabled>───</option>');
        offFields.forEach(function(o){ $fieldSel.append('<option value="'+o.f+'" style="color:#666"'+(o.f===tr.field?' selected':'')+'>'+o.label+'</option>'); });
        if (!tr.field && $fieldSel.find('option').length) triggersData[i].field = $fieldSel.find('option:first').val();
      }
      $card.append('<div class="form-row"><div class="form-group flex-1"><label>Urządzenie</label></div><div class="form-group flex-1"><label>Właściwość</label></div></div>');
      $card.find('.form-group').eq(0).append($devSel);
      $card.find('.form-group').eq(1).append($fieldSel);
      $card.append('<div class="form-row"><div class="form-group"><label>Operator</label><select class="tr-op" data-i="'+i+'"><option value=""'+((!tr.op||tr.op==='')?' selected':'')+'>(dowolny)</option><option value="="'+(tr.op==='='?' selected':'')+'>= </option><option value=">"'+(tr.op==='>'?' selected':'')+'>></option><option value="<"'+(tr.op==='<'?' selected':'')+'>< </option><option value=">="'+(tr.op==='>='?' selected':'')+'>>= </option><option value="<="'+(tr.op==='<='?' selected':'')+'><=</option><option value="!="'+(tr.op==='!='?' selected':'')+'>!=</option></select></div><div class="form-group flex-1"><label>Wartość</label><input type="text" class="tr-val w-full" data-i="'+i+'" value="'+(tr.value||'')+'" placeholder="puste = każda zmiana"></div></div>');
    }
    $area.append($card);
  });
  $area.find('.tr-type').on('change', function(){ var idx=$(this).data('i'); triggersData[idx].type=$(this).val(); if($(this).val()==='cron' && !triggersData[idx].cron) triggersData[idx].cron='* * * * *'; renderTriggers(); });
  $area.find('.tr-dev').on('change', function(){
    var idx = $(this).data('i');
    triggersData[idx].dev_id=$(this).val();
    triggersData[idx].field='';
    renderTriggers();
  });
  $area.find('.tr-field').on('change', function(){ triggersData[$(this).data('i')].field=$(this).val(); });
  $area.find('.tr-op').on('change', function(){ triggersData[$(this).data('i')].op=$(this).val(); });
  $area.find('.tr-val').on('input', function(){ triggersData[$(this).data('i')].value=$(this).val(); });
  $area.find('.tr-cron-preset').on('change', function(){
    var idx=$(this).data('i'), v=$(this).val(), $custom=$(this).siblings('.tr-cron');
    if(v==='_custom'){ $custom.show().focus(); } else { $custom.hide(); triggersData[idx].cron=v; $custom.val(v); }
  });
  $area.find('.tr-cron').on('input', function(){ triggersData[$(this).data('i')].cron=$(this).val(); });
  $area.find('.tr-sun-type').on('change', function(){ triggersData[$(this).data('i')].sun_type=$(this).val(); });
  $area.find('.tr-sun-offset').on('input', function(){ triggersData[$(this).data('i')].sun_offset=parseInt($(this).val())||0; });
  $area.find('.tr-dev').each(function(){ var idx=$(this).data('i'); if(!triggersData[idx].dev_id) triggersData[idx].dev_id=$(this).val(); });
  $area.find('.tr-field').each(function(){ var idx=$(this).data('i'); if(!triggersData[idx].field) triggersData[idx].field=$(this).val(); });
  makeSearchable($area.find('.tr-dev'));
  makeSearchable($area.find('.tr-field'));
  makeSearchable($area.find('.tr-type'));
  makeSearchable($area.find('.tr-op'));
  makeSearchable($area.find('.tr-cron-preset'));
  makeSearchable($area.find('.tr-sun-type'));
}

function addTrigger() {
  triggersData.push({type:'state',dev_id:'',field:'',op:'',value:''});
  renderTriggers();
}

// === AKCJE (WYKONAJ) ===
var _actionTypes = {mqtt_set:'Ustaw stan (MQTT)',tymos_sound:'Dźwięk na tymosu',telegram:'Wyślij Telegram',telegram_photo:'Telegram ze zdjęciem',script:'Uruchom skrypt',sleep:'Czekaj (sleep)'};
var _soundPresets = {ping:'Pojedynczy',ping_short:'Pojedynczy krótki',doorbell:'Dzwonek (ding-dong)',alarm:'Alarm (pilny)'};
var _payloadPresets = {'{"state":"ON"}':'Włącz','{"state":"OFF"}':'Wyłącz','{"state":"TOGGLE"}':'Przełącz','_custom':'Własny...'};
var _coverPresets = {'{"state":"OPEN"}':'Otwórz','{"state":"CLOSE"}':'Zamknij','{"state":"STOP"}':'Stop','_custom':'Własny...'};
function _isCover(devId) {
  var m = ''; (window._devList||[]).forEach(function(d){ if(d.id==devId) m=d.model||''; });
  return m === 'MINI-ZBRBS';
}
function _is2gang(devId) {
  var fields = (window._devFields||{})[devId] || {};
  return (('switch_1' in fields) && ('switch_2' in fields)) || (('state_l1' in fields) && ('state_l2' in fields));
}
function _get2gangPresets(devId) {
  var fields = (window._devFields||{})[devId] || {};
  var isZb = ('state_l1' in fields);
  var k1 = isZb ? 'state_l1' : 'switch_1';
  var k2 = isZb ? 'state_l2' : 'switch_2';
  var ch = {};
  (window._devList||[]).forEach(function(d){ if (d.id==devId && d.ch) ch=d.ch; });
  var l1 = ch['1'] || 'Kanal 1';
  var l2 = ch['2'] || 'Kanal 2';
  var p = {};
  p['{"'+k1+'":"ON"}'] = l1 + ' — ON';
  p['{"'+k1+'":"OFF"}'] = l1 + ' — OFF';
  p['{"'+k2+'":"ON"}'] = l2 + ' — ON';
  p['{"'+k2+'":"OFF"}'] = l2 + ' — OFF';
  p['{"'+k1+'":"ON","'+k2+'":"ON"}'] = 'Oba ON';
  p['{"'+k1+'":"OFF","'+k2+'":"OFF"}'] = 'Oba OFF';
  p['_custom'] = 'Własny...';
  return p;
}

function renderActions() {
  var $area = $('#actions_area').empty();
  actionsData.forEach(function(act, i) {
    var hasBtns = actionsData.length > 1;
    var $card = $('<div class="sub-card">');
    if (hasBtns) {
      var $btns = $('<div class="item-toolbar" style="justify-content:flex-end;margin-bottom:8px">');
      if (i > 0) $btns.append('<span class="move-btn" onclick="moveItem(actionsData,'+i+',-1,renderActions)">▲</span>');
      if (i < actionsData.length-1) $btns.append('<span class="move-btn" onclick="moveItem(actionsData,'+i+',1,renderActions)">▼</span>');
      $btns.append('<span class="move-btn danger" onclick="actionsData.splice('+i+',1);renderActions()">✕</span>');
      $card.append($btns);
    }
    var $typeSel = $('<select class="aa-type" data-i="'+i+'" style="width:100%;margin-bottom:10px">');
    $.each(_actionTypes, function(k,v){ $typeSel.append('<option value="'+k+'"'+(act.type===k?' selected':'')+'>'+v+'</option>'); });
    $card.append($typeSel);

    if (act.type === 'mqtt_set') {
      var $devSel = $('<select class="aa-dev w-full" data-i="'+i+'">');
      if (!act.dev_id) $devSel.append('<option value="" selected>-- wybierz --</option>');
      if (act.dev_id && !(window._devList||[]).some(function(d){return d.id==act.dev_id;})) {
        $devSel.append('<option value="'+act.dev_id+'" selected style="color:#f44336">⚠ Usunięte urządzenie (id='+act.dev_id+')</option>');
      }
      (window._devList||[]).forEach(function(d){ $devSel.append('<option value="'+d.id+'"'+(d.id==act.dev_id?' selected':'')+'>'+d.device+'</option>'); });
      var curDevId = act.dev_id || '';
      if (curDevId && !(window._devFields||{})[curDevId]) {
        _loadFields(curDevId, function() { renderActions(); });
      }
      if (!act.dev_id && curDevId) actionsData[i].dev_id = curDevId;
      var presets = _isCover(curDevId) ? _coverPresets : _is2gang(curDevId) ? _get2gangPresets(curDevId) : _payloadPresets;
      var curPayload = act.payload || Object.keys(presets)[0];
      if (!act.payload && curPayload) actionsData[i].payload = curPayload;
      var isCustom = !(curPayload in presets);
      var $presetSel = $('<select class="aa-preset" data-i="'+i+'" style="width:100%;margin-bottom:6px">');
      $.each(presets, function(k,v){ var $o=$('<option>').val(k).text(v); if((!isCustom&&k===curPayload)||(isCustom&&k==='_custom')) $o.prop('selected',true); $presetSel.append($o); });
      var $payloadInput = $('<input type="text" class="aa-payload" data-i="'+i+'" value="'+$('<span>').text(curPayload).html()+'" placeholder=\'{"key":"value"}\' style="width:100%;'+(isCustom?'':'display:none')+'">');
      $card.append('<div class="form-row"><div class="form-group flex-1"><label>Urządzenie</label></div><div class="form-group flex-1"><label>Stan</label></div></div>');
      $card.find('.form-group').eq(0).append($devSel);
      $card.find('.form-group').eq(1).append($presetSel).append($payloadInput);
    } else if (act.type === 'telegram') {
      $card.append('<div class="form-group"><label>Wiadomość</label><input type="text" class="aa-message w-full" data-i="'+i+'" value="'+(act.message||'')+'" placeholder="Tekst powiadomienia..."></div>');
    } else if (act.type === 'telegram_photo') {
      $card.append('<div class="form-group"><label>Wiadomość (podpis)</label><input type="text" class="aa-message w-full" data-i="'+i+'" value="'+(act.message||'')+'" placeholder="Tekst pod zdjęciem..."></div>');
      $card.append('<div class="form-group"><label>URL zdjęcia</label><input type="text" class="aa-photo-url w-full" data-i="'+i+'" value="'+(act.photo_url||'')+'" placeholder="http://..."></div>');
    } else if (act.type === 'script') {
      $card.append('<div class="form-group"><label>Ścieżka skryptu</label><input type="text" class="aa-script w-full" data-i="'+i+'" value="'+(act.script||'')+'" placeholder="/media/tym/..."></div>');
    } else if (act.type === 'tymos_sound') {
      var $soundSel = $('<select class="aa-sound" data-i="'+i+'" style="flex:1">');
      $.each(_soundPresets, function(k,v){ $soundSel.append('<option value="'+k+'"'+(act.sound===k?' selected':'')+'>'+v+'</option>'); });
      if (!act.sound) actionsData[i].sound = $soundSel.val();
      var $playBtn = $('<button class="btn btn-sm" style="background:var(--accent);color:#fff;white-space:nowrap" onclick="previewSound(this)">&#9654; Odtwórz</button>');
      $card.append('<div class="form-group"><label>Dźwięk</label><div style="display:flex;gap:8px;align-items:center"></div></div>');
      $card.find('.form-group').last().find('div').append($soundSel).append($playBtn);
    } else if (act.type === 'sleep') {
      $card.append('<div class="form-group"><label>Czas (sekundy)</label><input type="number" class="aa-sleep" data-i="'+i+'" value="'+(act.seconds||1)+'" min="1" style="width:100px"></div>');
    }
    $area.append($card);
  });
  $area.find('.aa-type').on('change', function(){ actionsData[$(this).data('i')].type=$(this).val(); renderActions(); });
  $area.find('.aa-dev').on('change', function(){ var idx=$(this).data('i'); actionsData[idx].dev_id=$(this).val(); actionsData[idx].payload=''; renderActions(); });
  $area.find('.aa-preset').on('change', function(){ var idx=$(this).data('i'), v=$(this).val(), $inp=$(this).siblings('.aa-payload'); if(v==='_custom'){$inp.show().focus();}else{$inp.hide();actionsData[idx].payload=v;$inp.val(v);} });
  $area.find('.aa-payload').on('input', function(){ actionsData[$(this).data('i')].payload=$(this).val(); });
  $area.find('.aa-message').on('input', function(){ actionsData[$(this).data('i')].message=$(this).val(); });
  $area.find('.aa-photo-url').on('input', function(){ actionsData[$(this).data('i')].photo_url=$(this).val(); });
  $area.find('.aa-script').on('input', function(){ actionsData[$(this).data('i')].script=$(this).val(); });
  $area.find('.aa-sound').on('change', function(){ actionsData[$(this).data('i')].sound=$(this).val(); });
  $area.find('.aa-sleep').on('input', function(){ actionsData[$(this).data('i')].seconds=parseInt($(this).val())||1; });
  $area.find('.aa-dev').each(function(){ var idx=$(this).data('i'); if(!actionsData[idx].dev_id) actionsData[idx].dev_id=$(this).val(); });
  $area.find('.aa-preset').each(function(){ var idx=$(this).data('i'); if(!actionsData[idx].payload){ var v=$(this).val(); if(v && v!=='_custom') actionsData[idx].payload=v; } });
  makeSearchable($area.find('.aa-dev'));
  makeSearchable($area.find('.aa-type'));
  makeSearchable($area.find('.aa-preset'));
}

function addAction() {
  actionsData.push({type:'mqtt_set', dev_id:'', payload:'{"state":"ON"}'});
  renderActions();
}

function moveItem(arr, idx, dir, renderFn) {
  var target = idx + dir;
  if (target < 0 || target >= arr.length) return;
  var tmp = arr[idx];
  arr[idx] = arr[target];
  arr[target] = tmp;
  renderFn();
}

// === WARUNKI ===
var _condTypes = {value:'Wartość pola',helper:'Wartość helpera',last_activity:'Nieaktywne od (czas od ostatniego odczytu)',sun:'Słońce (pora dnia)'};

function _buildValueInput(path, c) {
  var src = c.value_src || 'static';
  var $wrap = $('<div>');
  var $row = $('<div class="form-row end">');
  var $srcSel = $('<select class="cond-vsrc" data-path="'+path+'" style="width:180px"><option value="static"'+(src==='static'?' selected':'')+'>Stała wartość</option><option value="helper"'+(src==='helper'?' selected':'')+'>Helper</option></select>');
  $row.append($('<div class="form-group">').append('<label>Wartość z</label>').append($srcSel));
  if (src === 'static') {
    var curVal = '';
    var fieldInfo = ((window._devFields||{})[c.dev_id]||{})[c.field];
    if (fieldInfo && fieldInfo.val !== null && fieldInfo.val !== undefined && fieldInfo.val !== '') curVal = fieldInfo.val + (fieldInfo.unit ? ' ' + fieldInfo.unit : '');
    var ph = curVal ? 'aktualna: ' + curVal : 'wartość';
    var distinct = (fieldInfo && fieldInfo.distinct) ? fieldInfo.distinct : [];
    var isNumeric = distinct.length > 0 && distinct.every(function(v){ return !isNaN(v) && v !== ''; });
    var useDatalist = distinct.length > 0 && distinct.length <= 10 && (!isNumeric || distinct.length === 2);
    var $valGroup = $('<div class="form-group flex-1">').append('<label>Wartość</label>');
    if (useDatalist) {
      var $sel = $('<select class="cond-val w-full" data-path="'+path+'">');
      $sel.append('<option value="">-- wybierz --</option>');
      distinct.forEach(function(v){ $sel.append('<option value="'+v+'"'+(v==c.value?' selected':'')+'>'+v+'</option>'); });
      $valGroup.append($sel);
    } else {
      $valGroup.append('<input type="text" class="cond-val w-full" data-path="'+path+'" value="'+(c.value||'')+'" placeholder="'+ph+'">');
    }
    $row.append($valGroup);
  } else {
    var $hSel = $('<select class="cond-helper w-full" data-path="'+path+'">');
    (window._helpers||[]).forEach(function(h){
      var label = (h.label||h.name) + ' (' + (h.value||'') + (h.unit?' '+h.unit:'') + ')';
      $hSel.append('<option value="'+h.id+'"'+(h.id==c.helper_id?' selected':'')+'>'+label+'</option>');
    });
    $row.append($('<div class="form-group flex-1">').append('<label>Helper</label>').append($hSel));
    $row.append($('<div class="form-group" style="min-width:140px">').append('<label>+/- (offset)</label>').append('<input type="number" class="cond-offset" data-path="'+path+'" value="'+(c.offset||0)+'" step="0.5" style="width:140px">'));
  }
  $wrap.append($row);
  return $wrap;
}

function _buildDevFieldSelects(c, i, prefix) {
  var $devSel = $('<select class="'+prefix+'-dev w-full" data-path="'+i+'">');
  if (!c.dev_id) $devSel.append('<option value="" selected>-- wybierz --</option>');
  var devExists = false;
  (window._devList||[]).forEach(function(d){ if(d.id==c.dev_id) devExists=true; $devSel.append('<option value="'+d.id+'"'+(d.id==c.dev_id?' selected':'')+'>'+d.device+'</option>'); });
  if (c.dev_id && !devExists) {
    $devSel.prepend('<option value="'+c.dev_id+'" selected style="color:#e74c3c">⚠ usuniety (id:'+c.dev_id+')</option>');
    $devSel.css('border','1px solid #e74c3c');
  }
  var curDevId = c.dev_id || ($devSel.find('option:first').val());
  if (!c.dev_id && curDevId) c.dev_id = curDevId;
  var $fieldSel = $('<select class="'+prefix+'-field w-full" data-path="'+i+'">');
  var fieldsMap = (window._devFields||{})[curDevId];
  if (!fieldsMap && curDevId) {
    $fieldSel.append('<option value="">Ładowanie...</option>');
    _loadFields(curDevId, function() { renderConditions(); });
    return {$dev: $devSel, $field: $fieldSel};
  }
  fieldsMap = fieldsMap || {};
  var fieldExists = false;
  var onFields = [], offFields = [];
  var chLabels = {};
  (window._devList||[]).forEach(function(d){ if(d.id==curDevId && d.ch) chLabels=d.ch; });
  $.each(fieldsMap, function(f, info) {
    if(f===c.field) fieldExists=true;
    var label = (info.label || f);
    if ((f === 'switch_1' || f === 'state_l1') && chLabels['1']) label = chLabels['1'];
    if ((f === 'switch_2' || f === 'state_l2') && chLabels['2']) label = chLabels['2'];
    if (info.unit) label += ' (' + info.unit + ')';
    if (info.val !== null && info.val !== undefined && info.val !== '') label += ' [' + info.val + ']';
    if (info.on) onFields.push({f:f, label:label});
    else offFields.push({f:f, label:label});
  });
  if (c.field && !fieldExists && devExists) {
    $fieldSel.prepend('<option value="'+c.field+'" selected style="color:#e74c3c">⚠ '+c.field+' (brak)</option>');
    $fieldSel.css('border','1px solid #e74c3c');
  }
  onFields.forEach(function(o){ $fieldSel.append('<option value="'+o.f+'"'+(o.f===c.field?' selected':'')+'>'+o.label+'</option>'); });
  if (offFields.length && onFields.length) $fieldSel.append('<option disabled>───</option>');
  offFields.forEach(function(o){ $fieldSel.append('<option value="'+o.f+'" style="color:#666"'+(o.f===c.field?' selected':'')+'>'+o.label+'</option>'); });
  if (!c.field && $fieldSel.find('option:not(:disabled)').length) c.field = $fieldSel.find('option:not(:disabled):first').val();
  return {$dev: $devSel, $field: $fieldSel};
}

function _renderOneCondition(c, path, $parent, extraBtns) {
  var ct = c.type || 'value';
  var $card = $('<div class="sub-card" style="margin-bottom:6px">');
  var $toolbar = $('<div class="item-toolbar" style="justify-content:flex-end;margin-bottom:8px">');
  if (extraBtns) $toolbar.append(extraBtns);
  $toolbar.append('<span class="move-btn danger cond-remove" data-path="'+path+'">✕</span>');
  $card.append($toolbar);
  var $typeSel = $('<select class="cond-type" data-path="'+path+'" style="width:100%;margin-bottom:10px">');
  $.each(_condTypes, function(k,v){ $typeSel.append('<option value="'+k+'"'+(ct===k?' selected':'')+'>'+v+'</option>'); });
  $card.append($typeSel);
  if (ct === 'helper') {
    var hid = c.helper_id || '';
    var $hsel = $('<select class="cond-helper" data-path="'+path+'" style="width:100%;margin-bottom:8px">');
    $hsel.append('<option value="">-- wybierz helper --</option>');
    (window._helpers||[]).forEach(function(h){
      $hsel.append('<option value="'+h.id+'"'+(String(h.id)===String(hid)?' selected':'')+'>'+h.name+(h.value!==null?' ['+h.value+']':'')+(h.unit?' '+h.unit:'')+'</option>');
    });
    $card.append('<div class="form-group"><label>Helper</label></div>');
    $card.find('.form-group').last().append($hsel);
    var $opRow = $('<div class="form-row">');
    $opRow.append('<div class="form-group"><label>Operator</label><select class="cond-op" data-path="'+path+'" style="width:60px"><option value="="'+(c.op==='='?' selected':'')+'>= </option><option value=">"'+(c.op==='>'?' selected':'')+'>></option><option value="<"'+(c.op==='<'?' selected':'')+'>< </option><option value=">="'+(c.op==='>='?' selected':'')+'>>=</option><option value="<="'+(c.op==='<='?' selected':'')+'><=</option><option value="!="'+(c.op==='!='?' selected':'')+'>!=</option></select></div>');
    $opRow.append('<div class="form-group flex-1"><label>Wartość</label><input type="text" class="cond-val w-full" data-path="'+path+'" value="'+(c.value||'')+'"></div>');
    $card.append($opRow);
  } else if (ct === 'sun') {
    var sunMode = c.sun_mode || 'after_sunset';
    var sunOffset = c.sun_offset || 0;
    $card.append('<div class="form-row"><div class="form-group flex-1"><label>Warunek</label><select class="cond-sun-mode w-full" data-path="'+path+'"><option value="after_sunset"'+(sunMode==='after_sunset'?' selected':'')+'>Po zmroku (zachód → wschód)</option><option value="after_sunrise"'+(sunMode==='after_sunrise'?' selected':'')+'>Za dnia (wschód → zachód)</option><option value="before_sunrise"'+(sunMode==='before_sunrise'?' selected':'')+'>Przed wschodem</option><option value="before_sunset"'+(sunMode==='before_sunset'?' selected':'')+'>Przed zachodem</option></select></div><div class="form-group"><label>Offset (min)</label><input type="number" class="cond-sun-offset" data-path="'+path+'" value="'+sunOffset+'" style="width:80px"></div></div>');
    if (!c.sun_mode) c.sun_mode = sunMode;
  } else {
    var sels = _buildDevFieldSelects(c, path, 'cond');
    $card.append('<div class="form-row"><div class="form-group flex-1"><label>Urządzenie</label></div><div class="form-group flex-1"><label>Właściwość</label></div></div>');
    $card.find('.form-group').eq(0).append(sels.$dev);
    $card.find('.form-group').eq(1).append(sels.$field);
    if (ct === 'value') {
      var $valRow = _buildValueInput(path, c);
      var $opGroup = $('<div class="form-group"><label>Operator</label><select class="cond-op" data-path="'+path+'" style="width:70px"><option value="="'+(c.op==='='?' selected':'')+'>= </option><option value=">"'+(c.op==='>'?' selected':'')+'>></option><option value="<"'+(c.op==='<'?' selected':'')+'>< </option><option value=">="'+(c.op==='>='?' selected':'')+'>>=</option><option value="<="'+(c.op==='<='?' selected':'')+'><=</option><option value="!="'+(c.op==='!='?' selected':'')+'>!=</option></select></div>');
      $valRow.find('.form-row').first().prepend($opGroup);
      $card.append($valRow);
    } else if (ct === 'last_activity') {
      $card.append('<div class="form-row"><div class="form-group flex-1"><label>Wartość (opcjonalne)</label><input type="text" class="cond-val w-full" data-path="'+path+'" value="'+(c.value||'')+'" placeholder="np. true, ON, 1"></div><div class="form-group"><label>Czas nieaktywności (sekund)</label><input type="number" class="cond-seconds" data-path="'+path+'" value="'+(c.seconds||120)+'" style="width:100px"></div></div>');
    }
  }
  $parent.append($card);
}

function _getByPath(path) {
  var parts = path.split('.').map(Number);
  if (parts.length === 1) return conditionsData.items[parts[0]];
  return conditionsData.items[parts[0]].items[parts[1]];
}
function _setByPath(path, key, val) {
  var obj = _getByPath(path);
  if (obj) obj[key] = val;
}
function _removeByPath(path) {
  var parts = path.split('.').map(Number);
  if (parts.length === 1) {
    conditionsData.items.splice(parts[0], 1);
  } else {
    var group = conditionsData.items[parts[0]];
    group.items.splice(parts[1], 1);
    if (group.items.length === 1) {
      conditionsData.items[parts[0]] = group.items[0];
    }
  }
}
function _logicLabel(logic) { return logic === 'OR' ? 'LUB' : 'ORAZ'; }
function _logicColor(logic) { return '#f9a825'; }

function renderConditions() {
  var $area = $('#conditions_area').empty();
  if (!conditionsData.items.length) return;
  var mainLogic = conditionsData.logic || 'AND';
  $area.append('<div style="font-size:11px;color:var(--text-dim);margin-bottom:8px"><select class="no-upgrade" onchange="conditionsData.logic=this.value;renderConditions()" style="font-size:13px;background:#f9a825;color:#000;border:none;border-radius:6px;padding:6px 12px;-webkit-appearance:none;appearance:none;background-image:url(data:image/svg+xml,%3Csvg%20xmlns=%27http://www.w3.org/2000/svg%27%20width=%2712%27%20height=%2712%27%20viewBox=%270%200%2012%2012%27%3E%3Cpath%20fill=%27%23000%27%20d=%27M6%208L1%203h10z%27/%3E%3C/svg%3E);background-repeat:no-repeat;background-position:right 10px center;padding-right:30px;font-weight:500"><option value="AND"'+(mainLogic==='AND'?' selected':'')+'>ORAZ (AND)</option><option value="OR"'+(mainLogic==='OR'?' selected':'')+'>LUB (OR)</option></select></div>');
  conditionsData.items.forEach(function(item, i) {
    if (i > 0) $area.append('<div class="logic-sep sm">'+_logicLabel(mainLogic)+'</div>');
    if (item.logic) {
      var grpLogic = item.logic;
      var $group = $('<div style="border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:12px;margin-bottom:6px">');
      if (conditionsData.items.length > 1) {
        var $cBtns = $('<div style="display:flex;gap:4px;justify-content:flex-end;margin-bottom:6px">');
        if (i > 0) $cBtns.append('<span class="move-btn" onclick="moveItem(conditionsData.items,'+i+',-1,renderConditions)">▲</span>');
        if (i < conditionsData.items.length-1) $cBtns.append('<span class="move-btn" onclick="moveItem(conditionsData.items,'+i+',1,renderConditions)">▼</span>');
        $group.append($cBtns);
      }
      $group.append('<div style="font-size:11px;margin-bottom:6px"><select data-gi="'+i+'" class="group-logic no-upgrade" style="font-size:13px;background:#f9a825;color:#000;border:none;border-radius:6px;padding:6px 12px;-webkit-appearance:none;appearance:none;background-image:url(data:image/svg+xml,%3Csvg%20xmlns=%27http://www.w3.org/2000/svg%27%20width=%2712%27%20height=%2712%27%20viewBox=%270%200%2012%2012%27%3E%3Cpath%20fill=%27%23000%27%20d=%27M6%208L1%203h10z%27/%3E%3C/svg%3E);background-repeat:no-repeat;background-position:right 10px center;padding-right:30px;font-weight:500"><option value="AND"'+(grpLogic==='AND'?' selected':'')+'>ORAZ (AND)</option><option value="OR"'+(grpLogic==='OR'?' selected':'')+'>LUB (OR)</option></select></div>');
      item.items.forEach(function(sub, j) {
        if (j > 0) $group.append('<div class="logic-sep sm">'+_logicLabel(grpLogic)+'</div>');
        _renderOneCondition(sub, i+'.'+j, $group);
      });
      $group.append('<button class="btn btn-accent btn-sm" style="margin-top:4px" onclick="conditionsData.items['+i+'].items.push({type:\'value\',dev_id:\'\',field:\'\',op:\'=\',value:\'\'});renderConditions()">+ Warunek</button>');
      $group.append(' <button class="btn btn-danger btn-sm" style="margin-top:4px" onclick="tymosConfirmDelete(this,function(){conditionsData.items.splice('+i+',1);renderConditions()})">Usuń blok</button>');
      $area.append($group);
    } else {
      var moveBtns = '';
      if (conditionsData.items.length > 1) {
        if (i > 0) moveBtns += '<span class="move-btn" onclick="moveItem(conditionsData.items,'+i+',-1,renderConditions)">▲</span>';
        if (i < conditionsData.items.length-1) moveBtns += '<span class="move-btn" onclick="moveItem(conditionsData.items,'+i+',1,renderConditions)">▼</span>';
      }
      _renderOneCondition(item, ''+i, $area, moveBtns);
    }
  });
  $area.find('.group-logic').on('change', function(){ conditionsData.items[$(this).data('gi')].logic=$(this).val(); renderConditions(); });
  $area.find('.cond-remove').on('click', function(){ _removeByPath($(this).attr('data-path')); renderConditions(); });
  $area.find('.cond-type').on('change', function(){ _setByPath($(this).attr('data-path'),'type',$(this).val()); renderConditions(); });
  $area.find('.cond-dev').on('change', function(){ var p=$(this).attr('data-path'); _setByPath(p,'dev_id',$(this).val()); _setByPath(p,'field',''); renderConditions(); });
  $area.find('.cond-field').on('change', function(){ _setByPath($(this).attr('data-path'),'field',$(this).val()); _setByPath($(this).attr('data-path'),'value',''); renderConditions(); });
  $area.find('.cond-op').on('change', function(){ _setByPath($(this).attr('data-path'),'op',$(this).val()); });
  $area.find('.cond-val').on('input change', function(){ _setByPath($(this).attr('data-path'),'value',$(this).val()); });
  $area.find('.cond-vsrc').on('change', function(){ _setByPath($(this).attr('data-path'),'value_src',$(this).val()); renderConditions(); });
  $area.find('.cond-helper').on('change', function(){ _setByPath($(this).attr('data-path'),'helper_id',$(this).val()); });
  $area.find('.cond-offset').on('input', function(){ _setByPath($(this).attr('data-path'),'offset',parseFloat($(this).val())||0); });
  $area.find('.cond-seconds').on('input', function(){ _setByPath($(this).attr('data-path'),'seconds',parseInt($(this).val())||0); });
  $area.find('.cond-sun-mode').on('change', function(){ _setByPath($(this).attr('data-path'),'sun_mode',$(this).val()); });
  $area.find('.cond-sun-offset').on('input', function(){ _setByPath($(this).attr('data-path'),'sun_offset',parseInt($(this).val())||0); });
  makeSearchable($area.find('.cond-dev'));
  makeSearchable($area.find('.cond-field'));
  makeSearchable($area.find('.cond-type'));
  makeSearchable($area.find('.cond-op'));
  makeSearchable($area.find('.cond-vsrc'));
  makeSearchable($area.find('.cond-helper'));
  makeSearchable($area.find('.cond-sun-mode'));
}

function addCondition() {
  conditionsData.items.push({type:'value', dev_id:'', field:'', op:'=', value:''});
  renderConditions();
}
function addConditionGroup() {
  conditionsData.items.push({logic:'OR', items:[
    {type:'value', dev_id:'', field:'', op:'=', value:''},
    {type:'value', dev_id:'', field:'', op:'=', value:''}
  ]});
  renderConditions();
}

// --- Preview dzwieku (tymos_sound preset) ---
var _previewCtx = null;
function _previewNote(t, freq, dur, vol, type) {
  var o = _previewCtx.createOscillator(), g = _previewCtx.createGain();
  o.connect(g); g.connect(_previewCtx.destination);
  o.type = type || 'sine'; o.frequency.value = freq;
  g.gain.setValueAtTime(vol, t); g.gain.exponentialRampToValueAtTime(0.01, t + dur);
  o.start(t); o.stop(t + dur + 0.02);
}
function _previewRichNote(t, freq, dur, vol) {
  var types = ['sawtooth','square','triangle'], mults = [1,2,1.5], vols = [vol,vol*0.3,vol*0.2];
  for (var j = 0; j < 3; j++) _previewNote(t, freq*mults[j], dur, vols[j], types[j]);
}
var _previewSounds = {
  ping: function() { var t = _previewCtx.currentTime; _previewNote(t, 880, 0.5, 0.3); },
  ping_short: function() { var t = _previewCtx.currentTime; _previewNote(t, 880, 0.25, 0.3); },
  doorbell: function() {
    var t = _previewCtx.currentTime;
    var notes=[392,392,523,523,659,784], times=[0,0.12,0.24,0.36,0.48,0.6], durs=[0.1,0.1,0.1,0.1,0.15,0.4];
    for (var rep=0;rep<2;rep++) { var off=rep*1.1; for (var i=0;i<notes.length;i++) _previewRichNote(t+off+times[i],notes[i],durs[i],0.7); }
  },
  alarm: function() {
    var t = _previewCtx.currentTime;
    for (var rep=0;rep<4;rep++) { var off=rep*0.5; _previewNote(t+off,1200,0.15,0.5,'square'); _previewNote(t+off+0.25,800,0.15,0.5,'square'); }
  }
};
function previewSound(btn) {
  if (!_previewCtx) { try { _previewCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e) { alert('Brak AudioContext'); return; } }
  if (_previewCtx.state === 'suspended') _previewCtx.resume();
  var sel = $(btn).siblings('select.aa-sound');
  var sound = sel.val();
  if (_previewSounds[sound]) _previewSounds[sound]();
}

// === SAVE / DELETE / TOGGLE / DUPLICATE ===
function saveAction(id) {
  for (var ti = 0; ti < triggersData.length; ti++) {
    var tr = triggersData[ti];
    if ((tr.type === 'event' || tr.type === 'threshold') && tr.dev_id && !tr.field) {
      alert('Trigger #' + (ti+1) + ': pole "Właściwość" nie zostało wybrane. Wybierz pole i spróbuj ponownie.');
      return;
    }
  }
  if (conditionsData.items) {
    for (var ci = 0; ci < conditionsData.items.length; ci++) {
      var c = conditionsData.items[ci];
      if (c.dev_id && !c.field && c.type !== 'sun' && c.type !== 'helper') {
        alert('Warunek #' + (ci+1) + ': pole "Właściwość" nie zostało wybrane. Wybierz pole i spróbuj ponownie.');
        return;
      }
    }
  }
  var postData = {
    action: 'save_action', id: id,
    name: $('#act_name').val(),
    enabled: $('#act_enabled').is(':checked') ? 1 : 0,
    triggers_json: JSON.stringify(triggersData),
    conditions: conditionsData.items.length ? JSON.stringify(conditionsData) : '',
    actions_json: JSON.stringify(actionsData),
    min_interval: $('#act_min_interval').val(),
  };
  $.post('api.php', postData, function(resp){
    if (resp.reload) adminLoadTab('actions');
    else if (resp.error) alert('Błąd: ' + resp.error);
  }, 'json').fail(function(xhr){
    alert('Błąd zapisu: ' + xhr.status + ' ' + xhr.responseText.substring(0,200));
  });
}

function deleteAction(id) {
  $.post('api.php', {action:'delete_action', action_id:id}, function(resp){ if(resp.reload) adminLoadTab('actions'); }, 'json');
}

function toggleAction(id, enabled) {
  $.post('api.php', {action:'toggle_action', action_id:id, enabled:enabled}, function(resp){ if(resp.reload) adminLoadTab('actions'); }, 'json');
}

function duplicateAction() {
  var postData = {
    action: 'save_action', id: 0,
    name: ($('#act_name').val() || '') + ' (kopia)',
    enabled: 0,
    triggers_json: JSON.stringify(triggersData),
    conditions: conditionsData.items.length ? JSON.stringify(conditionsData) : '',
    actions_json: JSON.stringify(actionsData),
    min_interval: $('#act_min_interval').val(),
  };
  $.post('api.php', postData, function(resp){
    if (resp.reload) adminLoadTab('actions');
    else if (resp.error) alert('Błąd: ' + resp.error);
  }, 'json');
}
