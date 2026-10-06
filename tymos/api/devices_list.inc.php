<?php
// GET — lista urzadzen dla lazy dropdownu w Actions builder.
// Zwraca [{id, name, model, ch:{1:..,2:..}}] posortowane po nazwie.
$rows = $db->query("SELECT id, device, model, channel_labels FROM devices WHERE deleted=0 ORDER BY device");
$out = [];
while ($r = $rows->fetch_assoc()) {
    $out[] = [
        'id'    => (int)$r['id'],
        'name'  => $r['device'],
        'model' => $r['model'] ?? '',
        'ch'    => json_decode($r['channel_labels'] ?? 'null', true) ?: null,
    ];
}
echo json_encode($out);
