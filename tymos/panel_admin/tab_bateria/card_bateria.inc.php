<?php
/**
 * Auto-discovery baterii — wszystkie urządzenia z polem battery lub at_low_battery.
 */
require_once __DIR__ . '/../../config.inc.php';
require_once __DIR__ . '/../../inc/db.inc.php';
$db->select_db('tymos');

// Jedno zapytanie — bierzemy wszystko potrzebne razem z last_state JSON.
// Aktualny stan baterii czytamy z devices.last_state (cache aktualizowany przy kazdym push),
// a nie z `device<ID>` — dzieki temu dziala nawet gdy uzytkownik wylaczyl `battery` z enabled_fields.
$rows = $db->query("SELECT id, device, ieee, fields, enabled_fields, last_state FROM devices WHERE deleted=0 ORDER BY device");
$items = [];
while ($r = $rows->fetch_assoc()) {
    $fields = json_decode($r['fields'] ?? '{}', true) ?: [];
    $hasBattery = isset($fields['battery']);
    $hasAtLow = isset($fields['at_low_battery']);
    if (!$hasBattery && !$hasAtLow) continue;

    $ieee = $r['ieee'] ?? '';
    $integ = (strpos($ieee, '0x') === 0) ? 'Z2M' : 'Inne';

    $hasTemp = isset($fields['temperature']);
    $hasOccupancy = isset($fields['occupancy']);
    $hasWaterLeak = isset($fields['water_leak']);
    if ($hasWaterLeak) $icon = '💧';
    elseif ($hasOccupancy) $icon = '👁️';
    elseif ($hasTemp) $icon = '🌡️';
    else $icon = '🔋';

    $type = $hasBattery ? 'pct' : 'bool';
    $field = $hasBattery ? 'battery' : 'at_low_battery';

    $lastState = json_decode($r['last_state'] ?? '{}', true) ?: [];
    $val = $lastState[$field] ?? null;

    $items[] = ['name' => $r['device'], 'integ' => $integ, 'icon' => $icon, 'val' => $val, 'type' => $type];
}

// Sortuj: Z2M najpierw, potem reszta; w ramach grupy po nazwie
usort($items, function($a, $b) {
    $order = ['Z2M' => 0, 'Inne' => 1];
    $oa = $order[$a['integ']] ?? 9;
    $ob = $order[$b['integ']] ?? 9;
    if ($oa !== $ob) return $oa - $ob;
    return strcasecmp($a['name'], $b['name']);
});
?>
<style>
.bat-tbl{width:100%;border-collapse:collapse}
.bat-tbl th{text-align:left;padding:4px 6px;font-size:10px;color:var(--text-dim);font-weight:normal;border-bottom:1px solid var(--border)}
.bat-tbl th.num{text-align:right}
.bat-tbl tr{border-bottom:1px solid var(--border)}
.bat-tbl td{padding:5px 6px;font-size:13px}
.bat-tbl td.int{font-size:11px;color:#888}
.bat-tbl td.icon{font-size:14px;padding:5px 4px}
.bat-tbl td.val{text-align:right;font-weight:600}
</style>
<div class="card-title">Baterie</div>
<table class="bat-tbl">
<thead>
<tr><th>Int.</th><th></th><th>Sensor</th><th class="num">Bateria</th></tr>
</thead>
<tbody>
<?php foreach ($items as $it):
    if ($it['type'] === 'bool') {
        $low   = ($it['val'] === '1' || $it['val'] === 'true' || $it['val'] === true || $it['val'] === 1);
        $color = ($it['val'] === null) ? '#666' : ($low ? '#e74c3c' : '#4caf50');
        $txt   = ($it['val'] === null) ? '---' : ($low ? 'NISKA' : 'OK');
    } else {
        $pct   = ($it['val'] !== null) ? (float)$it['val'] : null;
        $color = ($pct === null) ? '#666' : ($pct > 70 ? '#4caf50' : ($pct > 30 ? '#ffc107' : '#e74c3c'));
        $txt   = ($pct !== null) ? ((int)$pct . '%') : '---';
    }
?>
<tr>
  <td class="int"><?= htmlspecialchars($it['integ']) ?></td>
  <td class="icon"><?= $it['icon'] ?></td>
  <td><?= htmlspecialchars($it['name']) ?></td>
  <td class="val" style="color:<?= $color ?>"><?= $txt ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
