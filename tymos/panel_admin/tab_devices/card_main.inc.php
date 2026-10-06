<?php
// TymOS admin → Urządzenia (list + detail).
// Query params: ?id=X → detail, brak id → lista; ?sort=name|model, ?filter=fuzzy

require_once __DIR__ . '/../../config.inc.php';
require_once __DIR__ . '/../../inc/helpers.inc.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
$db->set_charset('utf8mb4');

$now = time();
$devId = $_GET['id'] ?? null;
$imgBase = __DIR__ . '/../../img';   // fs path
$imgUrl  = '/img';                    // HTTP path

// ============ SZCZEGOLY URZADZENIA ============
if ($devId) {
    $id = (int)$devId;
    $row = $db->query("SELECT * FROM devices WHERE id={$id}")->fetch_assoc();
    if (!$row) { echo '<div class="admin-scope" style="padding:20px"><p>Nie znaleziono</p></div>'; exit; }

    $fields = json_decode($row['fields'], true) ?: [];
    $ef = $row['enabled_fields'] ? json_decode($row['enabled_fields'], true) : [];
    $enabled = count($ef) > 0;

    $lastVals = [];
    if (!empty($row['last_state'])) {
        $lastVals = json_decode($row['last_state'], true) ?: [];
    }
    if (!empty($row['last_seen'])) {
        $lastVals['ts'] = $row['last_seen'];
    }

    $detailModel = $row['model'] ?? '';
    $detailIeee = $row['ieee'] ?? '';
    $detailImg = "{$imgBase}/{$detailModel}.png";
    $detailImgUrl = "{$imgUrl}/{$detailModel}.png";
    if (!($detailModel && file_exists($detailImg))) {
        $altImg = "{$imgBase}/{$detailIeee}.png";
        if (file_exists($altImg)) { $detailImg = $altImg; $detailImgUrl = "{$imgUrl}/{$detailIeee}.png"; }
    }
    $hasImg = file_exists($detailImg);
?>
<div class="admin-scope">
  <div class="detail-card">
    <div style="display:flex;align-items:flex-start;justify-content:space-between">
      <div style="display:flex;align-items:center;gap:16px">
        <div style="position:relative;cursor:pointer" onclick="document.getElementById('thumb_file').click()" title="Zmień miniaturkę (PNG/JPG/GIF/WebP)">
<?php if ($hasImg): ?>
          <img src="<?= $detailImgUrl ?>?t=<?= time() ?>" style="height:92px">
          <div style="position:absolute;bottom:0;right:0;font-size:14px;line-height:1">✏️</div>
<?php else: ?>
          <div style="width:92px;height:92px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.04);border-radius:12px;border:1px dashed rgba(255,255,255,0.15)">
            <span style="font-size:32px;opacity:0.3">🖼</span>
          </div>
<?php endif; ?>
        </div>
        <input type="file" id="thumb_file" data-model="<?= htmlspecialchars($detailModel) ?>" data-dev="<?= $id ?>" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none" onchange="uploadThumb(this)">
<?php
        $ieee = $row['ieee'] ?? '';
        $canRename = preg_match('/^0x|^cam_/', $ieee);
        $renameBtn = $canRename ? ' <span class="rename-btn" data-dev="' . $id . '" title="Zmień nazwę">✏️</span>' : '';
        $devIp = $row['ip'] ?? '';
        $ipPart = $devIp ? ' | IP: ' . htmlspecialchars($devIp) : '';
        $lsSub = $row['last_seen'] ?? '';
        $lsSubColor = 'var(--text-dim)';
        $isBattery = isset($fields['battery']);
        if ($lsSub) {
            $lsAge = $now - strtotime($lsSub . ' UTC');
            if ($isBattery) {
                if ($lsAge > 21600) $lsSubColor = 'var(--red)';
                elseif ($lsAge > 10800) $lsSubColor = '#f9a825';
            } else {
                if ($lsAge > 21600) $lsSubColor = 'var(--red)';
                elseif ($lsAge > 3600) $lsSubColor = '#f9a825';
            }
        }
        $lsPart = $lsSub ? '<br><span style="color:' . $lsSubColor . '">Last: ' . htmlspecialchars($lsSub) . '</span>' : '';
        $devAvail = $row['available'] ?? null;
        if ($devAvail !== null) {
            $avColor = $devAvail ? 'var(--green)' : 'var(--red)';
            $avText = $devAvail ? 'online' : 'offline';
            $avDot = '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . $avColor . ';margin-right:4px"></span>';
            $z2mPart = ' | ' . $avDot . '<span style="color:' . $avColor . '">' . $avText . '</span>';
        } else {
            $z2mPart = '';
        }
?>
        <div>
          <div class="detail-title"><span id="dev_name_text"><?= htmlspecialchars($row['device']) ?></span><?= $renameBtn ?></div>
          <div class="detail-sub">IEEE: <?= htmlspecialchars($row['ieee'] ?? '?') ?> | ID: <?= $id ?> | Model: <?= htmlspecialchars($detailModel) ?><?= $ipPart ?><?= $lsPart ?><?= $z2mPart ?></div>
        </div>
      </div>
      <span class="link-accent" data-admin-nav="devices" data-id="<?= $id ?>">↻ Odśwież</span>
    </div>
  </div>

<?php
    // --- ONVIF ---
    if (strpos($ieee, 'cam_') === 0):
        $onvifIp = htmlspecialchars($row['ip'] ?? '');
        $onvifPort = (int)($row['onvif_port'] ?? 0);
        $onvifUser = htmlspecialchars($row['onvif_user'] ?? '');
        $onvifPass = htmlspecialchars($row['onvif_pass'] ?? '');
?>
  <div class="detail-card">
    <div class="section-sh">ONVIF</div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <div class="form-group" style="flex:2;min-width:120px"><label>IP</label><input type="text" id="onvif_ip" value="<?= $onvifIp ?>" class="w-full"></div>
      <div class="form-group flex-1" style="min-width:70px"><label>Port</label><input type="number" id="onvif_port" value="<?= $onvifPort ?: '' ?>" class="w-full"></div>
      <div class="form-group flex-1" style="min-width:100px"><label>User</label><input type="text" id="onvif_user" value="<?= $onvifUser ?>" class="w-full"></div>
      <div class="form-group flex-1" style="min-width:100px"><label>Hasło</label><input type="text" id="onvif_pass" value="<?= $onvifPass ?>" class="w-full"></div>
    </div>
    <button class="btn btn-sm btn-accent" onclick="$.post('api.php',{action:'save_onvif',dev_id:<?= $id ?>,ip:$('#onvif_ip').val(),onvif_port:$('#onvif_port').val(),onvif_user:$('#onvif_user').val(),onvif_pass:$('#onvif_pass').val()},function(r){if(r.ok)adminLoadTab('devices',{id:<?= $id ?>});},'json')">Zapisz</button>
  </div>
<?php endif; ?>

<?php
    // --- POWER ON BEHAVIOR ---
    $pobLabels = ['on' => 'Wlacz (ON)', 'off' => 'Wylacz (OFF)', 'toggle' => 'Przelacz (TOGGLE)', 'previous' => 'Przywroc poprzedni stan'];
    $pobFields = [];
    if (isset($fields['power_on_behavior'])) {
        $pobFields[0] = 'power_on_behavior';
    } else {
        for ($ch = 1; $ch <= 4; $ch++) {
            if (isset($fields["power_on_behavior_l{$ch}"])) $pobFields[$ch] = "power_on_behavior_l{$ch}";
        }
    }
    if ($pobFields):
        $chLabels = json_decode($row['channel_labels'] ?? 'null', true) ?: [];
?>
  <div class="detail-card" style="padding:12px 16px">
<?php $isMulti = count($pobFields) > 1 || !isset($pobFields[0]);
      if ($isMulti): ?>
    <div style="font-size:13px;color:var(--text-dim);margin-bottom:4px">Po przerwie zasilania</div>
<?php endif;
      foreach ($pobFields as $ch => $field):
        $pob = $lastVals[$field] ?? null;
        $pobLabel = $pob ? ($pobLabels[strtolower($pob)] ?? $pob) : 'nieznane';
        $pobColor = $pob ? 'var(--text)' : 'var(--text-dim)';
        if ($ch > 0):
            $chName = $chLabels[(string)$ch] ?? "Kanal {$ch}"; ?>
    <div style="font-size:13px;color:<?= $pobColor ?>;margin-top:2px"><span style="color:var(--text-dim)"><?= htmlspecialchars($chName) ?>:</span> <?= htmlspecialchars($pobLabel) ?></div>
<?php else: ?>
    <div style="font-size:13px;color:<?= $pobColor ?>"><span style="color:var(--text-dim)">Po przerwie zasilania:</span> <?= htmlspecialchars($pobLabel) ?></div>
<?php endif; endforeach; ?>
  </div>
<?php endif; ?>

<?php
    // --- STEROWANIE ---
    $canControl = false;
    $hasState = isset($fields['state']) || isset($fields['switch_1']) || isset($fields['state_l1']);
    $isSensor = isset($fields['battery']) && !isset($fields['switch_1']) && !isset($fields['state_l1']) && !isset($fields['power']);
    if ($hasState && !$isSensor && strpos($ieee, '0x') === 0) { $canControl = true; }
    if ($canControl):
        $channels = [];
        for ($ch = 1; $ch <= 4; $ch++) {
            if (isset($fields["state_l{$ch}"])) $channels[$ch] = "state_l{$ch}";
            elseif (isset($fields["switch_{$ch}"])) $channels[$ch] = "switch_{$ch}";
        }
        $isMultiGang = count($channels) >= 2;
        $chLabels = json_decode($row['channel_labels'] ?? 'null', true) ?: [];
?>
  <div class="detail-card">
    <div class="section-sh">Sterowanie</div>
<?php if ($isMultiGang): ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
<?php foreach ($channels as $ch => $key):
        $sw = ($lastVals[$key] ?? 'OFF');
        $lbl = $chLabels[(string)$ch] ?? "Kanal {$ch}"; ?>
      <div style="flex:1;min-width:140px">
        <div style="font-size:13px;color:var(--text-dim);margin-bottom:6px"><?= htmlspecialchars($lbl) ?> <span style="font-weight:600;color:<?= $sw === 'ON' ? 'var(--green)' : 'var(--red)' ?>"><?= $sw ?></span></div>
        <div class="row-flex" style="gap:6px">
          <button class="btn btn-sm btn-green" onclick="devCmd(<?= $id ?>,{<?= $key ?>:'ON'})">ON</button>
          <button class="btn btn-sm btn-danger" onclick="devCmd(<?= $id ?>,{<?= $key ?>:'OFF'})">OFF</button>
        </div>
      </div>
<?php endforeach; ?>
    </div>
    <div style="font-size:12px;color:var(--text-dim);margin-top:4px"><span class="rename-btn" onclick="showChLabelEdit()" title="Zmień nazwy kanałów">✏️ nazwy kanałów</span></div>
    <div id="ch_label_edit" style="display:none;margin-top:8px">
      <div style="display:flex;gap:10px;flex-wrap:wrap">
<?php foreach ($channels as $ch => $key):
            $lbl = $chLabels[(string)$ch] ?? "Kanal {$ch}"; ?>
        <div class="form-group flex-1"><label>Kanal <?= $ch ?></label><input type="text" id="ch_label_<?= $ch ?>" value="<?= htmlspecialchars($lbl) ?>" class="w-full"></div>
<?php endforeach; ?>
        <button class="btn btn-sm btn-accent" style="align-self:flex-end;margin-bottom:4px" onclick="saveChLabels(<?= $id ?>)">Zapisz</button>
      </div>
    </div>
<?php else:
        $curState = ($lastVals['state'] ?? '');
        $stateVals = (is_array($fields['state'] ?? null) && isset($fields['state']['values'])) ? $fields['state']['values'] : [];
        $isCover = in_array('OPEN', $stateVals) && in_array('CLOSE', $stateVals);
        if ($isCover):
            $stateColor = $curState === 'OPEN' ? 'var(--green)' : ($curState === 'CLOSE' ? 'var(--red)' : 'var(--text-dim)');
            $stateBg = $curState === 'OPEN' ? 'rgba(76,175,80,0.15)' : ($curState === 'CLOSE' ? 'rgba(244,67,54,0.15)' : 'rgba(128,128,128,0.15)'); ?>
    <div id="dev_state_label" style="display:inline-block;padding:4px 14px;border-radius:6px;font-size:14px;font-weight:600;color:<?= $stateColor ?>;background:<?= $stateBg ?>;border:1px solid <?= $stateColor ?>;margin-bottom:10px">Stan: <?= htmlspecialchars($curState ?: '?') ?></div>
<?php
            $pos = $lastVals['position'] ?? '';
            if ($pos !== ''): ?>
    <div style="font-size:13px;color:var(--text-dim);margin-bottom:10px">Pozycja: <?= htmlspecialchars($pos) ?>%</div>
<?php endif; ?>
    <div class="row-flex">
      <button class="btn btn-sm btn-green" onclick="devCmd(<?= $id ?>,{state:'OPEN'})">OPEN</button>
      <button class="btn btn-sm btn-danger" onclick="devCmd(<?= $id ?>,{state:'CLOSE'})">CLOSE</button>
      <button class="btn btn-sm btn-accent" onclick="devCmd(<?= $id ?>,{state:'STOP'})">STOP</button>
    </div>
<?php else:
            $stateColor = $curState === 'ON' ? 'var(--green)' : 'var(--red)';
            $stateBg = $curState === 'ON' ? 'rgba(76,175,80,0.15)' : 'rgba(244,67,54,0.15)'; ?>
    <div id="dev_state_label" style="display:inline-block;padding:4px 14px;border-radius:6px;font-size:14px;font-weight:600;color:<?= $stateColor ?>;background:<?= $stateBg ?>;border:1px solid <?= $stateColor ?>;margin-bottom:10px">Stan: <?= htmlspecialchars($curState ?: '?') ?></div>
    <div class="row-flex">
      <button class="btn btn-sm btn-green" onclick="devCmd(<?= $id ?>,{state:'ON'})">ON</button>
      <button class="btn btn-sm btn-danger" onclick="devCmd(<?= $id ?>,{state:'OFF'})">OFF</button>
      <button class="btn btn-sm btn-accent" onclick="devCmd(<?= $id ?>,{state:'TOGGLE'})">TOGGLE</button>
    </div>
<?php endif; endif; ?>
  </div>
<?php endif; ?>

  <div class="detail-card">
    <div class="section-sh">Statystyki</div>
    <table style="width:100%;border-collapse:collapse;border:none">
<?php ksort($fields);
    $chLabels = json_decode($row['channel_labels'] ?? 'null', true) ?: [];
    foreach ($fields as $fname => $finfo):
        $isOn = in_array($fname, $ef);
        $val = $lastVals[$fname] ?? '-';
        if (is_bool($val)) $val = $val ? 'true' : 'false';
        if (is_array($finfo)) {
            $ftype = $finfo['sql'] ?? 'TEXT';
            $unit = $finfo['unit'] ?? '';
            $label = $finfo['label'] ?? $fname;
        } else {
            $ftype = $finfo;
            $unit = '';
            $label = $fname;
        }
        $displayName = htmlspecialchars($fname);
        if (($fname === 'switch_1' || $fname === 'state_l1') && isset($chLabels['1'])) $displayName = htmlspecialchars($chLabels['1']);
        elseif (($fname === 'switch_2' || $fname === 'state_l2') && isset($chLabels['2'])) $displayName = htmlspecialchars($chLabels['2']);
        elseif ($label !== $fname) $displayName .= '<br><span style="font-size:11px;color:var(--text-dim)">' . htmlspecialchars($label) . '</span>';
        if ($unit) $val = ($val === '-' ? '' : $val) . ' ' . $unit;
        $clickable = $isOn && $enabled && (!in_array($ftype, ['VARCHAR(64)', 'TEXT']) || $fname === 'state'); ?>
      <tr>
        <td style="padding:6px 8px 6px 0;border:none;width:36px;vertical-align:middle"><label class="toggle"><input type="checkbox" class="field-toggle" data-dev="<?= $id ?>" data-field="<?= htmlspecialchars($fname) ?>"<?= $isOn ? ' checked' : '' ?>><span></span></label></td>
<?php if ($clickable): ?>
        <td style="font-size:13px;padding:6px 4px;border:none;vertical-align:middle;cursor:pointer" class="field-chart-toggle" data-dev="<?= $id ?>" data-field="<?= htmlspecialchars($fname) ?>"><?= $displayName ?></td>
<?php else: ?>
        <td style="font-size:13px;padding:6px 4px;border:none;vertical-align:middle"><?= $displayName ?></td>
<?php endif; ?>
        <td style="font-size:13px;color:var(--accent);padding:6px 4px;border:none;text-align:right;white-space:nowrap;vertical-align:middle"><?= htmlspecialchars($val) ?></td>
        <td style="font-size:11px;color:var(--text-dim);padding:6px 0 6px 4px;border:none;white-space:nowrap;vertical-align:middle"><?= htmlspecialchars($ftype) ?></td>
      </tr>
<?php if ($clickable): ?>
      <tr><td colspan="4" style="border:none;padding:0"><div class="field-chart" id="chart_<?= $id ?>_<?= htmlspecialchars($fname) ?>" style="display:none;padding:0 0 8px 46px"></div></td></tr>
<?php endif; endforeach; ?>
    </table>
  </div>

<?php
    // --- Pełne dane (last_state) ---
    $fullState = !empty($row['last_state']) ? json_decode($row['last_state'], true) : null;
    $metaTs = is_array($fullState) ? ($fullState['__ts'] ?? []) : [];
    $displayState = [];
    if (is_array($fullState)) {
        foreach ($fullState as $k => $v) {
            if ($k === '__ts' || $k === '__val_ts' || $k === 'device_id') continue;
            $displayState[$k] = $v;
        }
    }
?>
  <div class="detail-card">
    <div style="font-size:13px;color:var(--text-dim);margin-bottom:10px">
      Pełne dane (last_state)<?php if (!empty($row['last_seen'])): ?> — ostatni push: <?= htmlspecialchars($row['last_seen']) ?><?php endif; ?>
    </div>
<?php if (count($displayState) > 0): ?>
    <table style="width:100%;border-collapse:collapse;border:none">
<?php foreach ($displayState as $k => $v):
        $ts = is_array($metaTs) && isset($metaTs[$k]) ? (float)$metaTs[$k] : null;
        $tsDisplay = $ts ? date('Y-m-d H:i:s', (int)$ts) : '';
        $val = is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE));
?>
      <tr>
        <td style="font-size:13px;padding:4px 8px 4px 0;border:none;color:var(--text);vertical-align:top"><?= htmlspecialchars($k) ?></td>
        <td style="font-size:13px;padding:4px;border:none;color:var(--accent);text-align:right;vertical-align:top;word-break:break-all"><?= htmlspecialchars($val) ?></td>
        <td style="font-size:11px;padding:4px 0 4px 8px;border:none;color:var(--text-dim);white-space:nowrap;text-align:right;vertical-align:top;font-family:ui-monospace,Menlo,Consolas,monospace"><?= $tsDisplay ?></td>
      </tr>
<?php endforeach; ?>
    </table>
<?php else: ?>
    <div style="color:var(--text-dim);font-style:italic;font-size:12px">Brak danych</div>
<?php endif; ?>
  </div>

<?php
    // --- Ostatnie dane ---
    $tbl = "device{$id}";
    $check = $db->query("SHOW TABLES LIKE '{$tbl}'");
    if ($check && $check->num_rows > 0):
        $cntRes = $db->query("SELECT COUNT(*) AS c FROM `{$tbl}`");
        $rowCount = ($cntRes && $cr = $cntRes->fetch_assoc()) ? (int)$cr['c'] : 0; ?>
  <div class="detail-card">
    <div class="section-sh">Ostatnie dane</div>
<?php if ($rowCount > 0):
            $histRows = $db->query("SELECT * FROM `{$tbl}` ORDER BY ts DESC LIMIT 20");
            $cols = array_keys($histRows->fetch_assoc());
            $histRows->data_seek(0);
            $chLabels2 = json_decode($row['channel_labels'] ?? 'null', true) ?: []; ?>
    <div style="overflow-x:auto"><table><tr>
<?php foreach ($cols as $c):
                $colName = $c;
                if (($c === 'switch_1' || $c === 'state_l1') && isset($chLabels2['1'])) $colName = $chLabels2['1'];
                elseif (($c === 'switch_2' || $c === 'state_l2') && isset($chLabels2['2'])) $colName = $chLabels2['2']; ?>
      <th><?= htmlspecialchars($colName) ?></th>
<?php endforeach; ?>
    </tr>
<?php while ($r = $histRows->fetch_assoc()): ?>
    <tr>
<?php foreach ($cols as $c): ?>
      <td><?= htmlspecialchars($r[$c] ?? '') ?></td>
<?php endforeach; ?>
    </tr>
<?php endwhile; ?>
    </table></div>
<?php else: ?>
    <div style="color:var(--text-dim);font-style:italic;font-size:12px">Tabela pusta</div>
<?php endif; ?>
  </div>
<?php endif; ?>

<?php
    // --- Debug payloady ---
    $dbgCheck = $db->query("SHOW TABLES LIKE 'debug_payload'");
    if ($dbgCheck && $dbgCheck->num_rows > 0):
        $stmtDbg = $db->prepare("SELECT topic, payload, ts FROM debug_payload WHERE device_id=? ORDER BY ts DESC LIMIT 20");
        $stmtDbg->bind_param('i', $id);
        $stmtDbg->execute();
        $dbgRows = $stmtDbg->get_result();
        if ($dbgRows && $dbgRows->num_rows > 0): ?>
  <div class="detail-card">
    <div class="section-sh">Debug payloady (<?= $dbgRows->num_rows ?>)</div>
<?php while ($dr = $dbgRows->fetch_assoc()):
            $pretty = $dr['payload'];
            $decoded = json_decode($dr['payload']);
            if ($decoded !== null) $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?>
    <div style="border-bottom:1px solid var(--border);padding:8px 0">
      <div style="font-size:12px;color:var(--text-dim);margin-bottom:4px"><?= htmlspecialchars($dr['ts']) ?> &nbsp; <span style="color:var(--accent)"><?= htmlspecialchars($dr['topic']) ?></span></div>
      <pre style="margin:0;white-space:pre-wrap;font-size:11px;color:var(--text);line-height:1.4"><?= htmlspecialchars($pretty) ?></pre>
    </div>
<?php endwhile; ?>
  </div>
<?php endif; $stmtDbg->close(); endif; ?>

<?php $isDeleted = (int)($row['deleted'] ?? 0); ?>
  <div class="row-end" style="margin-top:16px;gap:8px">
<?php if ($isDeleted): ?>
    <button class="btn btn-accent btn-sm" onclick="$.post('api.php',{action:'restore_device',dev_id:<?= $id ?>},function(){adminLoadTab('devices');},'json');">Przywróć</button>
    <button class="btn btn-danger btn-sm" onclick="tymosConfirmDelete(this,function(){$.post('api.php',{action:'purge_device',dev_id:<?= $id ?>},function(){adminLoadTab('devices');},'json');})">Usuń trwale</button>
<?php else: ?>
    <button class="btn btn-danger btn-sm" onclick="tymosConfirmDelete(this,function(){$.post('api.php',{action:'cleanup_columns',dev_id:<?= $id ?>},function(r){adminLoadTab('devices',{id:<?= $id ?>});},'json')})">Usuń zbędne kolumny</button>
    <button class="btn btn-danger btn-sm" onclick="tymosConfirmDelete(this,function(){$.post('api.php',{action:'delete_device',dev_id:<?= $id ?>},function(r){if(r.warning)alert(r.warning);adminLoadTab('devices');},'json')})">Usuń urządzenie</button>
<?php endif; ?>
  </div>
</div>
<?php
    exit;
}

// ============ LISTA URZADZEN ============
$devSort = $_GET['sort'] ?? 'name';
$devFilter = $_GET['filter'] ?? '';
$orderBy = ($devSort === 'model') ? 'model, device' : 'device';
$where = '';
if ($devFilter) {
    $words = preg_split('/\s+/', trim($devFilter));
    $conditions = [];
    foreach ($words as $w) {
        $wl = strtolower($w);
        if (strpos('onvif', $wl) === 0 && strlen($wl) >= 2) {
            $conditions[] = "ieee LIKE 'cam_%'";
        } elseif (strpos('tapo', $wl) === 0 && strlen($wl) >= 2) {
            $conditions[] = "ieee LIKE 'cam_%'";
        } elseif (strpos('blebox', $wl) === 0 && strlen($wl) >= 2) {
            $conditions[] = "ieee LIKE 'blebox_%'";
        } elseif (strpos('zigbee', $wl) === 0 && strlen($wl) >= 2) {
            $conditions[] = "ieee LIKE '0x%'";
        } elseif ($wl === 'z2m') {
            $conditions[] = "ieee LIKE '0x%'";
        } else {
            $conditions[] = _fuzzyLike($db, $w, ['device', 'model', 'ieee']);
        }
    }
    $where = 'WHERE ' . implode(' AND ', $conditions);
}
$activeWhere = $where ? $where . ' AND deleted=0' : 'WHERE deleted=0';
$rows = $db->query("SELECT id, device, ieee, model, fields, enabled_fields, last_seen, available, channel_labels, last_state FROM devices {$activeWhere} ORDER BY {$orderBy}");
$deletedRows = $db->query("SELECT id, device, ieee, model FROM devices " . ($where ? $where . ' AND deleted=1' : 'WHERE deleted=1') . " ORDER BY device");

$intIcons = [
    'Zigbee'  => '<img src="' . $imgUrl . '/int_zigbee.png" style="height:14px;vertical-align:middle;margin-right:4px">',
    'ONVIF'   => '<img src="' . $imgUrl . '/int_onvif.png" style="height:14px;vertical-align:middle;margin-right:4px">',
    'BleBox'  => '<img src="' . $imgUrl . '/int_blebox.png" style="height:14px;vertical-align:middle;margin-right:4px">',
    'ESPHome' => '<img src="' . $imgUrl . '/int_esphome.svg" style="height:14px;vertical-align:middle;margin-right:4px">',
];

// Pobierz stan kontrolowalnych urzadzen z devices.last_state JSON (source of truth z daemona).
// NIE siegamy do device<ID> bo pola mogly byc disabled w enabled_fields.
$allRows = [];
$controlDevs = [];
$devStates = [];
while ($r = $rows->fetch_assoc()) {
    $allRows[] = $r;
    $flds = json_decode($r['fields'] ?? '{}', true) ?: [];
    $ieee = $r['ieee'] ?? '';
    $hasState = isset($flds['state']) || isset($flds['switch_1']) || isset($flds['state_l1']);
    $isSensor = isset($flds['battery']) && !isset($flds['switch_1']) && !isset($flds['state_l1']) && !isset($flds['power']);
    $canCtrl = false;
    if ($hasState && !$isSensor && strpos($ieee, '0x') === 0) $canCtrl = true;
    if ($canCtrl) {
        $channels = [];
        for ($ch = 1; $ch <= 4; $ch++) {
            if (isset($flds["state_l{$ch}"])) $channels[$ch] = "state_l{$ch}";
            elseif (isset($flds["switch_{$ch}"])) $channels[$ch] = "switch_{$ch}";
        }
        if (empty($channels)) $channels[0] = 'state';
        $controlDevs[(int)$r['id']] = ['channels' => $channels];
        // Stan z last_state JSON
        $ls = json_decode($r['last_state'] ?? '{}', true) ?: [];
        $mapped = [];
        foreach ($channels as $c) $mapped[$c] = $ls[$c] ?? null;
        $devStates[(int)$r['id']] = $mapped;
    }
}
?>
<div class="admin-scope">
  <div style="margin-bottom:12px">
    <div class="search-wrap">
      <input type="text" id="dev_filter" value="<?= htmlspecialchars($devFilter) ?>" placeholder="Szukaj..." class="input-std" style="padding:10px 14px">
      <span class="search-clear">×</span>
    </div>
  </div>
  <script>initFilter("dev_filter", "devices", {sort:"<?= $devSort ?>"});</script>
  <table>
<?php
    $nameStyle = ($devSort === 'name') ? 'color:var(--accent)' : 'cursor:pointer';
    $modelStyle = ($devSort === 'model') ? 'color:var(--accent)' : 'cursor:pointer'; ?>
    <tr>
      <th style="width:52px;min-width:52px"></th>
      <th style="<?= $nameStyle ?>" onclick="adminLoadTab('devices',{sort:'name',filter:'<?= htmlspecialchars($devFilter) ?>'})">Nazwa</th>
      <th style="text-align:right;<?= $modelStyle ?>" onclick="adminLoadTab('devices',{sort:'model',filter:'<?= htmlspecialchars($devFilter) ?>'})">Model</th>
    </tr>
<?php foreach ($allRows as $r):
        $did = (int)$r['id'];
        $model = $r['model'] ?? '';
        $ieee = $r['ieee'] ?? '';
        $imgFile = "{$imgBase}/{$model}.png";
        $imgFileUrl = "{$imgUrl}/{$model}.png";
        if (!($model && file_exists($imgFile))) {
            $altFile = "{$imgBase}/{$ieee}.png";
            if (file_exists($altFile)) { $imgFile = $altFile; $imgFileUrl = "{$imgUrl}/{$ieee}.png"; }
        }
        $imgTag = file_exists($imgFile) ? '<img src="' . $imgFileUrl . '" style="height:36px;max-width:48px;object-fit:contain">' : '<span style="font-size:20px;opacity:0.2">🖼</span>';
        if (strpos($ieee, '0x') === 0) $integration = 'Zigbee';
        elseif (strpos($ieee, 'cam_') === 0) $integration = 'ONVIF';
        elseif (strpos($ieee, 'blebox_') === 0) $integration = 'BleBox';
        elseif (strpos($ieee, 'esphome_') === 0) $integration = 'ESPHome';
        else $integration = '';
        $intIcon = $intIcons[$integration] ?? '';
        $avDotList = '';
        if ($r['available'] !== null) {
            $avDotColor = $r['available'] ? 'var(--green)' : 'var(--red)';
            $avDotList = '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:' . $avDotColor . ';margin-right:6px;vertical-align:middle"></span>';
        }
        $listBtns = '';
        if (isset($controlDevs[$did])) {
            $ctrl = $controlDevs[$did];
            $st = $devStates[$did] ?? [];
            $chans = $ctrl['channels'];
            if (count($chans) >= 2) {
                $chLbl = json_decode($r['channel_labels'] ?? 'null', true) ?: [];
                // Kanaly w KOLUMNIE (stack) — 4 sekcje nie mieszcza sie w rzedzie na waskim ekranie (iPhone).
                $listBtns = '<span style="display:flex;flex-direction:column;gap:3px;align-items:flex-start;margin-top:4px">';
                foreach ($chans as $ch => $key) {
                    $s = $st[$key] ?? 'OFF';
                    $c = $s === 'ON' ? 'var(--green)' : 'var(--red)';
                    $bg = $s === 'ON' ? 'rgba(76,175,80,0.15)' : 'rgba(244,67,54,0.15)';
                    $l = $chLbl[(string)$ch] ?? (string)$ch;
                    $listBtns .= '<span class="list-sw-led" style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;color:' . $c . ';background:' . $bg . ';border:1px solid ' . $c . '" title="' . htmlspecialchars($l) . '">' . htmlspecialchars($l) . ': ' . $s . '</span>';
                }
                $listBtns .= '</span>';
            } else {
                $key = reset($chans);
                $s = $st[$key] ?? 'OFF';
                $isGreen = ($s === 'ON' || $s === 'OPEN');
                $c = $isGreen ? 'var(--green)' : 'var(--red)';
                $bg = $isGreen ? 'rgba(76,175,80,0.15)' : 'rgba(244,67,54,0.15)';
                $listBtns = '<span class="list-sw-led" style="margin-left:8px;display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;color:' . $c . ';background:' . $bg . ';border:1px solid ' . $c . '">' . $s . '</span>';
            }
        }
?>
    <tr class="dev-row" data-id="<?= $did ?>" style="cursor:pointer">
      <td style="width:52px;min-width:52px;text-align:center"><?= $imgTag ?></td>
      <td><?= $avDotList ?><?= htmlspecialchars($r['device']) ?><?= $listBtns ?></td>
      <td style="font-size:11px;color:var(--text-dim);text-align:right"><nobr><?= htmlspecialchars($model) ?>&nbsp;&nbsp;<?= $intIcon ?></nobr></td>
    </tr>
<?php endforeach; ?>
  </table>

<?php if ($deletedRows && $deletedRows->num_rows > 0): ?>
  <div style="margin-top:24px;padding-top:16px;border-top:1px solid var(--border)">
    <div style="font-size:13px;color:var(--text-dim);margin-bottom:8px">Usunięte</div>
    <table>
<?php while ($dr = $deletedRows->fetch_assoc()):
        $dModel = $dr['model'] ?? '';
        $dIeee = $dr['ieee'] ?? '';
        $dImgFile = "{$imgBase}/{$dModel}.png";
        $dImgFileUrl = "{$imgUrl}/{$dModel}.png";
        if (!($dModel && file_exists($dImgFile))) {
            $dAltFile = "{$imgBase}/{$dIeee}.png";
            if (file_exists($dAltFile)) { $dImgFile = $dAltFile; $dImgFileUrl = "{$imgUrl}/{$dIeee}.png"; }
        }
        $dImgTag = file_exists($dImgFile) ? '<img src="' . $dImgFileUrl . '" style="height:36px;max-width:48px;object-fit:contain">' : ''; ?>
      <tr class="dev-row" data-id="<?= $dr['id'] ?>" style="cursor:pointer;opacity:0.5">
        <td style="width:52px;min-width:52px;text-align:center"><?= $dImgTag ?></td>
        <td><?= htmlspecialchars($dr['device']) ?></td>
        <td style="font-size:11px;color:var(--text-dim);text-align:right"><?= htmlspecialchars($dModel) ?></td>
      </tr>
<?php endwhile; ?>
    </table>
  </div>
<?php endif; ?>
</div>
