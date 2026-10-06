<?php
// TymOS admin → Helpery (lista + edit/new).
// Przelaczanie list ↔ edit przez query param `id` (?id=X dla edit, ?id=0 dla new).

require_once __DIR__ . '/../../config.inc.php';
require_once __DIR__ . '/../../inc/helpers.inc.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
$db->set_charset('utf8mb4');

$helperId = $_GET['id'] ?? null;

// --- EDIT / NEW ---
if ($helperId !== null) {
    $id = (int)$helperId;
    $h = null;
    if ($id > 0) {
        $h = $db->query("SELECT * FROM helpers WHERE id={$id}")->fetch_assoc();
    }
?>
<div class="admin-scope">
  <div style="margin-bottom:20px">
    <input type="text" id="hlp_name" value="<?= htmlspecialchars($h['name'] ?? '') ?>" placeholder="nazwa_klucza"
           class="input-heading">
  </div>
  <div class="detail-card">
    <div class="form-group"><label>Etykieta</label>
      <input type="text" id="hlp_label" value="<?= htmlspecialchars($h['label'] ?? '') ?>" placeholder="Opis helpera" class="w-full">
    </div>
    <div class="form-row">
      <div class="form-group"><label>Typ</label>
        <select id="hlp_type" class="w-full">
<?php foreach (['number' => 'Liczba', 'text' => 'Tekst', 'boolean' => 'Przełącznik'] as $tk => $tv):
    $sel = (($h['type'] ?? 'text') === $tk) ? ' selected' : ''; ?>
          <option value="<?= $tk ?>"<?= $sel ?>><?= $tv ?></option>
<?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Jednostka</label>
        <input type="text" id="hlp_unit" value="<?= htmlspecialchars($h['unit'] ?? '') ?>" placeholder="np. °C, PLN" class="w-full">
      </div>
    </div>
    <div class="form-row" id="hlp_num_row">
      <div class="form-group"><label>Min</label>
        <input type="number" id="hlp_min" value="<?= htmlspecialchars($h['min_val'] ?? '') ?>" step="any" class="w-full">
      </div>
      <div class="form-group"><label>Max</label>
        <input type="number" id="hlp_max" value="<?= htmlspecialchars($h['max_val'] ?? '') ?>" step="any" class="w-full">
      </div>
      <div class="form-group"><label>Krok</label>
        <input type="number" id="hlp_step" value="<?= htmlspecialchars($h['step_val'] ?? '') ?>" step="any" class="w-full">
      </div>
    </div>
<?php $boolChecked = ($h['value'] ?? '') === '1' || ($h['value'] ?? '') === 'true' ? ' checked' : ''; ?>
    <div class="form-group" id="hlp_val_text"><label>Wartość</label>
      <input type="text" id="hlp_value" value="<?= htmlspecialchars($h['value'] ?? '') ?>" class="w-full">
    </div>
    <div class="form-group" id="hlp_val_bool" style="display:none"><label>Wartość</label>
      <div class="row-flex" style="padding:12px 0;gap:10px">
        <label class="toggle"><input type="checkbox" id="hlp_bool"<?= $boolChecked ?>><span></span></label>
        <span id="hlp_bool_label"><?= $boolChecked ? 'ON' : 'OFF' ?></span>
      </div>
    </div>
  </div>
  <div class="row-flex" style="padding:20px 0">
    <button class="btn btn-accent" onclick="saveHelper(<?= $id ?>)">Zapisz</button>
<?php if ($id > 0): ?>
    <button class="btn btn-danger" onclick="tymosConfirmDelete(this,function(){deleteHelper(<?= $id ?>)})">Usuń</button>
<?php endif; ?>
  </div>
</div>
<script>
$("#hlp_type").on("change", function() {
  var t = this.value;
  $("#hlp_num_row").toggle(t === "number");
  $("#hlp_val_text").toggle(t !== "boolean");
  $("#hlp_val_bool").toggle(t === "boolean");
}).trigger("change");
$("#hlp_bool").on("change", function() {
  var on = this.checked;
  $("#hlp_value").val(on ? "1" : "0");
  $("#hlp_bool_label").text(on ? "ON" : "OFF");
});
function saveHelper(id) {
  var type = $("#hlp_type").val();
  var value = type === "boolean" ? ($("#hlp_bool").is(":checked") ? "1" : "0") : $("#hlp_value").val();
  $.post("api.php", {
    action: "save_helper",
    id: id,
    name: $("#hlp_name").val(),
    label: $("#hlp_label").val(),
    type: type,
    value: value,
    min_val: $("#hlp_min").val(),
    max_val: $("#hlp_max").val(),
    step_val: $("#hlp_step").val(),
    unit: $("#hlp_unit").val()
  }, function(r) {
    if (r.reload) adminLoadTab('helpers');
  }, "json");
}
function deleteHelper(id) {
  $.post("api.php", {action:"delete_helper", id:id}, function(r) {
    if (r.reload) adminLoadTab('helpers');
  }, "json");
}
</script>
<?php
    exit;
}

// --- LIST ---
$hlpFilter = $_GET['filter'] ?? '';
$where = '';
if ($hlpFilter) {
    $words = preg_split('/\s+/', trim($hlpFilter));
    $conditions = [];
    foreach ($words as $w) {
        $conditions[] = _fuzzyLike($db, $w, ['name', 'label']);
    }
    $where = 'WHERE ' . implode(' AND ', $conditions);
}
$rows = $db->query("SELECT * FROM helpers {$where} ORDER BY name");
?>
<div class="admin-scope">
  <div style="margin-bottom:12px">
    <div class="search-wrap">
      <input type="text" id="hlp_filter" value="<?= htmlspecialchars($hlpFilter) ?>" placeholder="Szukaj..." class="input-std" style="padding:10px 14px">
      <span class="search-clear">×</span>
    </div>
  </div>
  <script>initFilter("hlp_filter", "helpers");</script>
<?php if ($rows && $rows->num_rows > 0): ?>
  <table>
    <tr><th style="width:60%">Nazwa</th><th style="text-align:right;width:40%">Wartość</th></tr>
<?php while ($r = $rows->fetch_assoc()):
    $valDisplay = htmlspecialchars($r['value'] ?? '');
    if ($r['unit']) $valDisplay .= '<br><span style="color:var(--text-dim);font-size:11px">' . htmlspecialchars($r['unit']) . '</span>';
    if ($r['type'] === 'boolean') {
        $valDisplay = ($r['value'] === '1' || $r['value'] === 'true')
            ? '<span style="color:#4caf50">ON</span>'
            : '<span style="color:var(--text-dim)">OFF</span>';
    }
    $nameDisplay = htmlspecialchars($r['name']);
    $label = $r['label'] ?? '';
    if ($label) $nameDisplay .= '<br><span style="color:var(--text-dim);font-size:11px">' . htmlspecialchars($label) . '</span>';
?>
    <tr style="cursor:pointer" onclick="adminLoadTab('helpers',{id:<?= $r['id'] ?>})">
      <td style="font-weight:500"><?= $nameDisplay ?></td>
      <td style="text-align:right"><?= $valDisplay ?></td>
    </tr>
<?php endwhile; ?>
  </table>
<?php else: ?>
  <div style="color:var(--text-dim);font-size:13px;margin-bottom:16px">Brak helperów.</div>
<?php endif; ?>
  <div style="margin-top:16px">
    <button class="btn btn-accent" onclick="adminLoadTab('helpers',{id:0})">+ Nowy helper</button>
  </div>
</div>
