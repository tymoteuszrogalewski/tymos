<?php // GET
$data = [];
$allCols = [
    'sensor.reku_fan_level' => 'fan_level',
    'sensor.reku_supply_fan_flow' => 'supply_fan_flow',
    'sensor.reku_exhaust_fan_flow' => 'exhaust_fan_flow',
    'sensor.reku_bypass_state' => 'bypass_state',
    'sensor.reku_bypass_activation_mode' => 'bypass_activation_mode',
    'sensor.reku_operating_mode' => 'operating_mode',
    'sensor.reku_temperature_profile' => 'temperature_profile',
    'sensor.reku_profile_target_temperature' => 'profile_target_temperature',
    'sensor.reku_filter_replacement_remaining_days' => 'filter_replacement_remaining_days',
    'sensor.reku_power' => 'power',
    'sensor.reku_running_mean_outdoor_temperature' => 'running_mean_outdoor_temperature',
];
$espTempMap = [
    'extract_air_temperature' => 'reku_dom2', 'extract_air_humidity' => 'reku_dom2_wilg',
    'supply_air_temperature' => 'reku_dom3', 'supply_air_humidity' => 'reku_dom3_wilg',
    'outdoor_air_temperature' => 'reku_zew1', 'outdoor_air_humidity' => 'reku_zew1_wilg',
    'exhaust_air_temperature' => 'reku_zew2', 'exhaust_air_humidity' => 'reku_zew2_wilg',
];
$parts = [];
foreach ($allCols as $col) {
    $parts[] = "(SELECT `{$col}` FROM device527 WHERE `{$col}` IS NOT NULL ORDER BY ts DESC LIMIT 1) AS `{$col}`";
}
foreach ($espTempMap as $col => $key) {
    $parts[] = "(SELECT `{$col}` FROM device527 WHERE `{$col}` IS NOT NULL ORDER BY ts DESC LIMIT 1) AS `{$col}`";
}
$res = $db->query("SELECT " . implode(", ", $parts));
if ($res && $row = $res->fetch_assoc()) {
    foreach ($allCols as $haKey => $col) {
        if (isset($row[$col]) && $row[$col] !== null) {
            $v = $row[$col];
            $data[$haKey] = is_numeric($v) ? round((float)$v, 1) : $v;
        }
    }
    foreach ($espTempMap as $col => $key) {
        if ($row[$col] !== null) $data[$key] = round((float)$row[$col], 1);
    }
    $nagrzRes = $db->query("SELECT temperature, humidity FROM device851 ORDER BY ts DESC LIMIT 1");
    if ($nagrzRes && $nagrzRow = $nagrzRes->fetch_assoc()) {
        if ($nagrzRow['temperature'] !== null) $data['reku_dom1'] = round((float)$nagrzRow['temperature'], 1);
        if ($nagrzRow['humidity'] !== null) $data['reku_dom1_wilg'] = round((float)$nagrzRow['humidity'], 1);
    }
}
echo json_encode($data);
