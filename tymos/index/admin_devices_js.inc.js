// TymOS panel_admin → Urządzenia — event handlery globalne (bindowane raz, dzialaja dla kazdego rerenderu karty).
// Wymagają załadowania admin_js.inc.js (adminLoadTab).

// Klik wiersza listy — idz do detail
$(document).on('click', '.dev-row', function(){
  adminLoadTab('devices', {id: $(this).data('id')});
});

// Klik "✏️" obok nazwy — inline rename
$(document).on('click', '.admin-scope .rename-btn[data-dev]', function(){
  var devId = $(this).data('dev');
  var $title = $(this).closest('.detail-title');
  var oldName = $('#dev_name_text').text();
  var $input = $('<input type="text" class="rename-input">').val(oldName);
  $title.empty().append($input);
  $input[0].focus();
  $input[0].setSelectionRange(oldName.length, oldName.length);

  var submitted = false;
  function doRename() {
    if (submitted) return;
    submitted = true;
    var newName = $.trim($input.val());
    if (!newName || newName === oldName) { adminLoadTab('devices', {id: devId}); return; }
    $.post('api.php', {action:'rename_device', dev_id:devId, new_name:newName}, function(resp){
      if (resp.warning) alert(resp.warning);
      if (resp.error) alert(resp.error);
      adminLoadTab('devices', {id: devId});
    }, 'json');
  }
  $input.on('keydown', function(e){
    if (e.key === 'Enter') { e.preventDefault(); doRename(); }
    if (e.key === 'Escape') { adminLoadTab('devices', {id: devId}); }
  });
  $input.on('blur', function(){ doRename(); });
});

// Toggle pola w enabled_fields (checkbox w Statystyki)
$(document).on('change', '.admin-scope .field-toggle', function(){
  var $t = $(this);
  var devId = $t.data('dev');
  $.post('api.php', {
    action: 'toggle_field',
    dev_id: devId,
    field: $t.data('field'),
    enabled: $t.is(':checked') ? 1 : 0
  }, function(resp){ if(resp.reload) adminLoadTab('devices', {id: devId}); }, 'json');
});

// Klik nazwy pola w tabeli Statystyki — toggle wykresu
$(document).on('click', '.admin-scope .field-chart-toggle', function(){
  var devId = $(this).data('dev');
  var field = $(this).data('field');
  var $chart = $('#chart_' + devId + '_' + field);
  if ($chart.is(':visible')) { $chart.slideUp(150); return; }
  $chart.html('<div style="color:var(--text-dim);font-size:12px">Ladowanie wykresu...</div>').slideDown(150);
  $.get('cnt.php', {panel:'admin', tab:'devices', card:'chart', id:devId, field:field, hours:24}, function(html){
    $chart.html(html);
  });
});

// === STEROWANIE ===
var _devCmdTimer = null;
function devCmd(devId, payload) {
  var $status = $('#dev_state_label');
  function _setLabel(st) {
    var on = st==='ON';
    $status.text('Stan: '+st).css({'color':on?'var(--green)':'var(--red)','background':on?'rgba(76,175,80,0.15)':'rgba(244,67,54,0.15)','border-color':on?'var(--green)':'var(--red)'});
  }
  if (payload.state === 'ON') _setLabel('ON');
  else if (payload.state === 'OFF') _setLabel('OFF');
  else if (payload.state === 'TOGGLE') { _setLabel($status.text().indexOf('ON')>=0?'OFF':'ON'); }
  $.post('api.php', {action:'device_cmd', dev_id:devId, payload:JSON.stringify(payload)}, function(resp){
    if (resp.error) alert(resp.error);
    if (_devCmdTimer) clearTimeout(_devCmdTimer);
    _devCmdTimer = setTimeout(function(){ _devCmdTimer = null; adminLoadTab('devices', {id:devId}); }, 2000);
  }, 'json');
}

function showChLabelEdit() {
  $('#ch_label_edit').toggle();
  if ($('#ch_label_edit').is(':visible')) $('#ch_label_1').focus();
}

function saveChLabels(devId) {
  var labels = {};
  for (var i = 1; i <= 4; i++) { var el = $('#ch_label_' + i); if (el.length) labels['' + i] = el.val(); }
  $.post('api.php', {action:'save_channel_labels', dev_id:devId, labels:JSON.stringify(labels)}, function(resp){
    if (resp.ok) adminLoadTab('devices', {id:devId});
    else if (resp.error) alert(resp.error);
  }, 'json');
}

function uploadThumb(inp) {
  if (!inp.files[0]) return;
  var model = $(inp).data('model');
  var devId = $(inp).data('dev');
  var fd = new FormData();
  fd.append('action', 'upload_thumb');
  fd.append('model', model);
  fd.append('file', inp.files[0]);
  $.ajax({
    url: 'api.php', type: 'POST', data: fd,
    processData: false, contentType: false, dataType: 'json',
    success: function(r){ if (r.ok) adminLoadTab('devices', {id: devId}); else alert(r.error || 'Błąd'); }
  });
}
