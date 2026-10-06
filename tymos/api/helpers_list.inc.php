<?php
// GET — lista helperow dla lazy dropdownu w Actions/Conditions builder.
// Zwraca [{id, name, type}, ...] posortowane po nazwie.
$rows = $db->query("SELECT id, name, type FROM helpers ORDER BY name");
$out = [];
if ($rows) {
    while ($r = $rows->fetch_assoc()) {
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'type' => $r['type']];
    }
}
echo json_encode($out);
