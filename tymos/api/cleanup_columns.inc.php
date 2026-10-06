<?php // POST — DROP kolumny spoza enabled_fields + DELETE rekordy puste (tylko ts)
$id = (int)($_POST['dev_id'] ?? 0);
$row = $db->query("SELECT enabled_fields FROM devices WHERE id={$id}")->fetch_assoc();
$ef = json_decode($row['enabled_fields'] ?? '[]', true) ?: [];
$tbl = "device{$id}";
$chk = $db->query("SHOW TABLES LIKE '{$tbl}'");
if (!$chk || $chk->num_rows === 0) { echo json_encode(['msg' => 'Brak tabeli']); exit; }

// 1. DROP kolumn spoza enabled_fields
$cols = $db->query("SHOW COLUMNS FROM `{$tbl}`");
$dropped = [];
while ($c = $cols->fetch_assoc()) {
    $f = $c['Field'];
    if ($f === 'ts') continue;
    if (!in_array($f, $ef)) {
        $db->query("ALTER TABLE `{$tbl}` DROP COLUMN `{$f}`");
        $dropped[] = $f;
    }
}

// 2. Po DROP sprawdz co zostalo i usun "puste" rekordy (same ts bez zadnej wartosci)
$cols2 = $db->query("SHOW COLUMNS FROM `{$tbl}`");
$dataCols = [];
while ($c = $cols2->fetch_assoc()) {
    if ($c['Field'] !== 'ts') $dataCols[] = $c['Field'];
}
$rowsDeleted = 0;
if (empty($dataCols)) {
    $cntRes = $db->query("SELECT COUNT(*) AS c FROM `{$tbl}`");
    $rowsDeleted = ($cntRes && $r = $cntRes->fetch_assoc()) ? (int)$r['c'] : 0;
    $db->query("TRUNCATE TABLE `{$tbl}`");
} else {
    $whereParts = [];
    foreach ($dataCols as $c) $whereParts[] = "`{$c}` IS NULL";
    $where = implode(' AND ', $whereParts);
    $db->query("DELETE FROM `{$tbl}` WHERE {$where}");
    $rowsDeleted = $db->affected_rows;
}

$msg = [];
if ($dropped) $msg[] = 'Kolumny: ' . implode(', ', $dropped);
if ($rowsDeleted > 0) $msg[] = "Puste rekordy: {$rowsDeleted}";
echo json_encode(['msg' => $msg ? implode(' | ', $msg) : 'Brak czego usuwac']);
