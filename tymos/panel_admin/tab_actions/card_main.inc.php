<?php
// TymOS admin → Akcje (lista + edit/new). Edit ma builder Kiedy/Jeżeli/Wykonaj.
// Wymaga JS z tymos/index/admin_actions_js.inc.js (renderTriggers/Conditions/Actions + save/delete/toggle/duplicate).

require_once __DIR__ . '/../../config.inc.php';
require_once __DIR__ . '/../../inc/helpers.inc.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
$db->set_charset('utf8mb4');

$actionId = $_GET['id'] ?? null;

// ============ EDYCJA / NOWA ============
if ($actionId !== null) {
    $id = (int)$actionId;
    $a = null;
    if ($id > 0) {
        $a = $db->query("SELECT * FROM actions WHERE id={$id}")->fetch_assoc();
    }
    $triggers = $a ? json_decode($a['trigger_config'], true) : [];
    if ($triggers && !isset($triggers[0])) $triggers = [$triggers];
    if (empty($triggers)) $triggers = [['type'=>'state','dev_id'=>'','field'=>'','op'=>'','value'=>'']];
    $conds = $a && $a['conditions'] ? json_decode($a['conditions'], true) : null;
    $acts = $a ? json_decode($a['actions'], true) : [];
    $actEnabled = (int)($a['enabled'] ?? 1);
    $minInt = $a['min_interval'] ?? '';

    // Lekka lista urzadzen do dropdownu (bez pol — te lazy z api.php?action=device_fields)
    $devRows = $db->query("SELECT id, device, model, channel_labels FROM devices WHERE deleted=0 ORDER BY device");
    $devList = [];
    while ($d = $devRows->fetch_assoc()) {
        $chLbl = $d['channel_labels'] ? json_decode($d['channel_labels'], true) : null;
        $devList[] = ['id' => $d['id'], 'device' => $d['device'], 'ch' => $chLbl, 'model' => $d['model'] ?? ''];
    }
    $helpersRows = $db->query("SELECT id, name, label, value, unit FROM helpers ORDER BY name");
    $helpersList = [];
    while ($hr = $helpersRows->fetch_assoc()) $helpersList[] = $hr;

    // Preload fields dla urzadzen uzywanych w tej akcji (trigger/cond/action)
    $usedDevIds = [];
    foreach ($triggers as $tr) if (!empty($tr['dev_id'])) $usedDevIds[] = $tr['dev_id'];
    foreach ($acts as $act) if (!empty($act['dev_id'])) $usedDevIds[] = $act['dev_id'];
    if ($conds && !empty($conds['items'])) {
        foreach ($conds['items'] as $ci) {
            if (!empty($ci['dev_id'])) $usedDevIds[] = $ci['dev_id'];
            if (!empty($ci['items'])) foreach ($ci['items'] as $si) if (!empty($si['dev_id'])) $usedDevIds[] = $si['dev_id'];
        }
    }
    $usedDevIds = array_unique($usedDevIds);

    $preloadedFields = [];
    foreach ($usedDevIds as $uid) {
        $uid = (int)$uid;
        if (!$uid) continue;
        $dRow = $db->query("SELECT fields, enabled_fields, channel_labels FROM devices WHERE id={$uid}")->fetch_assoc();
        if (!$dRow) continue;
        $dFields = json_decode($dRow['fields'] ?? '{}', true) ?: [];
        $dEf = json_decode($dRow['enabled_fields'] ?? '[]', true) ?: [];
        $dCh = json_decode($dRow['channel_labels'] ?? 'null', true) ?: [];
        $dTbl = "device{$uid}";
        $dLastVals = [];
        $dChk = $db->query("SHOW TABLES LIKE '{$dTbl}'");
        if ($dChk && $dChk->num_rows > 0) {
            $dCols = $db->query("SHOW COLUMNS FROM `{$dTbl}`");
            if ($dCols) {
                $dParts = [];
                while ($dc = $dCols->fetch_assoc()) {
                    if ($dc['Field'] !== 'ts') $dParts[] = "(SELECT `{$dc['Field']}` FROM `{$dTbl}` WHERE `{$dc['Field']}` IS NOT NULL ORDER BY ts DESC LIMIT 1) AS `{$dc['Field']}`";
                }
                if ($dParts) {
                    $dLr = $db->query("SELECT " . implode(", ", $dParts));
                    if ($dLr) $dLastVals = $dLr->fetch_assoc();
                }
            }
        }
        $dLsRow = $db->query("SELECT last_state FROM devices WHERE id={$uid}")->fetch_assoc();
        $dLastState = json_decode($dLsRow['last_state'] ?? '{}', true) ?: [];
        foreach ($dLastState as $dk => $dv) {
            if ((!isset($dLastVals[$dk]) || $dLastVals[$dk] === null || $dLastVals[$dk] === '') && $dv !== null) $dLastVals[$dk] = $dv;
        }
        $dMap = [];
        foreach ($dFields as $df => $dfi) {
            $dInfo = is_array($dfi) ? $dfi : ['sql' => $dfi];
            $dLabel = $dInfo['label'] ?? $df;
            if (($df === 'switch_1' || $df === 'state_l1') && isset($dCh['1'])) $dLabel = $dCh['1'];
            if (($df === 'switch_2' || $df === 'state_l2') && isset($dCh['2'])) $dLabel = $dCh['2'];
            $dDistinct = [];
            $dSql = $dInfo['sql'] ?? 'TEXT';
            $dIsText = (strpos(strtoupper($dSql), 'VARCHAR') !== false || strtoupper($dSql) === 'TEXT');
            if ($dIsText && in_array($df, $dEf) && $dChk && $dChk->num_rows > 0) {
                $dColChk = $db->query("SHOW COLUMNS FROM `{$dTbl}` LIKE '{$db->real_escape_string($df)}'");
                if ($dColChk && $dColChk->num_rows > 0) {
                    $dDr = $db->query("SELECT DISTINCT `{$db->real_escape_string($df)}` AS v FROM `{$dTbl}` WHERE `{$db->real_escape_string($df)}` IS NOT NULL ORDER BY v LIMIT 20");
                    if ($dDr) while ($dDrow = $dDr->fetch_assoc()) $dDistinct[] = $dDrow['v'];
                }
            }
            if (!$dDistinct) {
                if (!empty($dInfo['values']) && is_array($dInfo['values'])) { $dDistinct = $dInfo['values']; }
                elseif (($dInfo['z2m_type'] ?? '') === 'binary') {
                    $dDistinct = (strpos(strtoupper($dSql), 'TINYINT') !== false) ? ['0', '1'] : ['ON', 'OFF'];
                }
            }
            $dVal = $dLastVals[$df] ?? null;
            if ($dVal !== null && strpos(strtoupper($dSql), 'TINYINT(1)') !== false) {
                $dVal = $dVal ? '1' : '0';
            }
            $dMap[$df] = ['label' => $dLabel, 'on' => in_array($df, $dEf), 'val' => $dVal, 'unit' => $dInfo['unit'] ?? '', 'distinct' => $dDistinct];
        }
        $preloadedFields[$uid] = $dMap;
    }
?>
<div class="admin-scope">
  <div class="row-flex" style="gap:12px;margin-bottom:20px">
    <input type="text" id="act_name" value="<?= htmlspecialchars($a['name'] ?? '') ?>" placeholder="Nazwa akcji" autocomplete="off"
           class="input-std" style="flex:1;font-size:18px;padding:14px 16px">
    <label class="toggle"><input type="checkbox" id="act_enabled"<?= $actEnabled ? ' checked' : '' ?>><span></span></label>
  </div>

  <div class="action-section">
    <div class="section-label">Kiedy</div>
    <div id="triggers_area"></div>
    <button class="btn btn-accent btn-sm" onclick="addTrigger()" style="margin-top:6px">+ Dodaj trigger</button>
  </div>

  <div class="action-section">
    <div class="section-label">Jeżeli <span>(opcjonalne)</span></div>
    <div id="conditions_area"></div>
    <button class="btn btn-accent btn-sm" onclick="addCondition()">+ Warunek</button>
    <button class="btn btn-accent btn-sm" onclick="addConditionGroup()">+ Blok</button>
  </div>

  <div class="action-section">
    <div class="section-label">Wykonaj</div>
    <div id="actions_area"></div>
    <button class="btn btn-accent btn-sm" onclick="addAction()" style="margin-top:6px">+ Dodaj akcję</button>
  </div>

  <div class="row-flex" style="gap:12px;margin:16px 0 12px">
    <label style="font-size:13px;color:var(--text-dim);white-space:nowrap">Min. odstęp (s)</label>
    <input type="number" id="act_min_interval" value="<?= htmlspecialchars($minInt) ?>" placeholder="brak" min="0"
           class="input-std" style="width:80px;padding:8px 10px;font-size:14px">
    <span class="hint">Puste = domyślnie 5s. Np. 30 = max raz na 30s.</span>
  </div>
  <div class="row-flex" style="margin-top:8px">
    <button class="btn btn-accent" onclick="saveAction(<?= $id ?>)">Zapisz</button>
<?php if ($id > 0): ?>
    <button class="btn btn-ghost" onclick="duplicateAction()">Duplikuj</button>
    <button class="btn btn-danger" onclick="tymosConfirmDelete(this,function(){deleteAction(<?= $id ?>)})">Usuń</button>
<?php endif; ?>
  </div>
</div>

<script>
window.conditionsData = <?= json_encode($conds ?: ['logic'=>'AND','items'=>[]]) ?>;
window.triggersData = <?= json_encode($triggers) ?>;
window._devList = <?= json_encode($devList) ?>;
window._devFields = <?= json_encode($preloadedFields) ?>;
window._devFieldsLoading = {};
window._helpers = <?= json_encode($helpersList) ?>;
window.actionsData = <?= json_encode($acts ?: [['type'=>'mqtt_set','dev_id'=>'','payload'=>'{"state":"ON"}']]) ?>;
renderTriggers();
renderConditions();
renderActions();
</script>
<?php
    exit;
}

// ============ LISTA ============
$actFilter = $_GET['filter'] ?? '';
$where = '';
if ($actFilter) {
    $words = preg_split('/\s+/', trim($actFilter));
    $conditions = [];
    foreach ($words as $w) {
        $conditions[] = _fuzzyLike($db, $w, ['name', 'trigger_type']);
    }
    $where = 'WHERE ' . implode(' AND ', $conditions);
}
$rows = $db->query("SELECT id, name, enabled, trigger_type, last_triggered FROM actions {$where} ORDER BY name");
?>
<div class="admin-scope">
  <div style="margin-bottom:12px">
    <div class="search-wrap">
      <input type="text" id="act_filter" value="<?= htmlspecialchars($actFilter) ?>" placeholder="Szukaj..." class="input-std" style="padding:10px 14px">
      <span class="search-clear">×</span>
    </div>
  </div>
  <script>initFilter("act_filter", "actions");</script>
<?php if ($rows && $rows->num_rows > 0): ?>
  <table>
    <tr><th>Nazwa</th><th style="text-align:right">Ostatnie</th><th style="width:50px"></th></tr>
<?php while ($r = $rows->fetch_assoc()): ?>
    <tr style="cursor:pointer">
      <td onclick="adminLoadTab('actions',{id:<?= $r['id'] ?>})"><?= htmlspecialchars($r['name']) ?></td>
      <td style="font-size:12px;color:var(--text-dim);text-align:right"><?= htmlspecialchars($r['last_triggered'] ?? '-') ?></td>
      <td style="width:50px;text-align:right">
        <label class="toggle">
          <input type="checkbox" onchange="toggleAction(<?= $r['id'] ?>,this.checked?1:0)"<?= $r['enabled'] ? ' checked' : '' ?>>
          <span></span>
        </label>
      </td>
    </tr>
<?php endwhile; ?>
  </table>
<?php else: ?>
  <div style="color:var(--text-dim);font-size:13px;margin-bottom:16px">Brak akcji. Utwórz pierwszą.</div>
<?php endif; ?>
  <div style="margin-top:16px">
    <button class="btn btn-accent" onclick="adminLoadTab('actions',{id:0})">+ Nowa akcja</button>
  </div>
</div>
