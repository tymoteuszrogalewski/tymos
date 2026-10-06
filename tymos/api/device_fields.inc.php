<?php
// GET ?dev_id=X — lista pol urzadzenia dla lazy dropdownu (triggers/conditions).
// Zwraca [{f, label, on, val, unit, distinct:[...]}] — distinct ladowane z historii
// lub z Z2M enum values, lub ['ON','OFF'] dla binary.
$devId = (int)($_GET['dev_id'] ?? 0);
if (!$devId) { echo '[]'; exit; }

$row = $db->query("SELECT fields, enabled_fields, channel_labels FROM devices WHERE id={$devId}")->fetch_assoc();
if (!$row) { echo '[]'; exit; }

$fields = json_decode($row['fields'] ?? '{}', true) ?: [];
$ef = json_decode($row['enabled_fields'] ?? '[]', true) ?: [];
$ch = json_decode($row['channel_labels'] ?? 'null', true) ?: [];

// Ostatnie wartosci per kolumna
$tbl = "device{$devId}";
$lastVals = [];
$chk = $db->query("SHOW TABLES LIKE '{$tbl}'");
if ($chk && $chk->num_rows > 0) {
    $cols = $db->query("SHOW COLUMNS FROM `{$tbl}`");
    if ($cols) {
        $parts = [];
        while ($c = $cols->fetch_assoc()) {
            if ($c['Field'] !== 'ts') $parts[] = "(SELECT `{$c['Field']}` FROM `{$tbl}` WHERE `{$c['Field']}` IS NOT NULL AND `{$c['Field']}` != '' ORDER BY ts DESC LIMIT 1) AS `{$c['Field']}`";
        }
        if ($parts) {
            $lr = $db->query("SELECT " . implode(", ", $parts));
            if ($lr) $lastVals = $lr->fetch_assoc();
        }
    }
}
// Fallback: last_state z devices
$lsRow = $db->query("SELECT last_state FROM devices WHERE id={$devId}")->fetch_assoc();
$lastState = json_decode($lsRow['last_state'] ?? '{}', true) ?: [];
foreach ($lastState as $k => $v) {
    if ((!isset($lastVals[$k]) || $lastVals[$k] === null || $lastVals[$k] === '') && $v !== null) {
        $lastVals[$k] = $v;
    }
}

$out = [];
foreach ($fields as $fname => $finfo) {
    $info = is_array($finfo) ? $finfo : ['sql' => $finfo];
    $isOn = in_array($fname, $ef);
    $label = $info['label'] ?? $fname;
    if (($fname === 'switch_1' || $fname === 'state_l1') && isset($ch['1'])) $label = $ch['1'];
    if (($fname === 'switch_2' || $fname === 'state_l2') && isset($ch['2'])) $label = $ch['2'];
    $unit = $info['unit'] ?? '';
    $val = $lastVals[$fname] ?? null;

    // Distinct values — z tabeli lub z definicji pola
    $distinct = [];
    $sqlType = $info['sql'] ?? 'TEXT';
    $isText = (strpos(strtoupper($sqlType), 'VARCHAR') !== false || strtoupper($sqlType) === 'TEXT');
    if ($isText && $isOn && $chk && $chk->num_rows > 0) {
        $colChk = $db->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$db->real_escape_string($fname)}'");
        if ($colChk && $colChk->num_rows > 0) {
            $dr = $db->query("SELECT DISTINCT `{$db->real_escape_string($fname)}` AS v FROM `{$tbl}` WHERE `{$db->real_escape_string($fname)}` IS NOT NULL ORDER BY v LIMIT 20");
            if ($dr) while ($drow = $dr->fetch_assoc()) $distinct[] = $drow['v'];
        }
    }
    if (!$distinct) {
        if (!empty($info['values']) && is_array($info['values'])) {
            $distinct = $info['values'];
        } elseif (($info['z2m_type'] ?? '') === 'binary' && strpos(strtoupper($sqlType), 'TINYINT') === false) {
            $distinct = ['ON', 'OFF'];
        }
    }

    $out[] = [
        'f'     => $fname,
        'label' => $label,
        'on'    => $isOn,
        'val'   => $val,
        'unit'  => $unit,
        'distinct' => $distinct,
    ];
}
echo json_encode($out);
