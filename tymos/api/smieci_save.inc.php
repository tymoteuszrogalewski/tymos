<?php // POST
$dt   = $_POST['dt']   ?? '';
$type = $_POST['type'] ?? '';
$op   = $_POST['op']   ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) { echo '{"ok":false}'; exit; }
$valid = ['plastik','papier','szklo','zmieszane','bio','wielkogabaryty','tekstylia','opony','popiol','wodomierz'];

if ($type === '') {
    // clear all types for this date
    $db->query("DELETE FROM waste_calendar WHERE dt='$dt'");
} elseif (in_array($type, $valid, true)) {
    if ($op === 'remove') {
        $db->query("DELETE FROM waste_calendar WHERE dt='$dt' AND type='$type'");
    } else {
        $db->query("INSERT IGNORE INTO waste_calendar (dt,type) VALUES ('$dt','$type')");
    }
} else {
    echo '{"ok":false}'; exit;
}
echo '{"ok":true}';
