<?php // POST — przelacz flage debug_payload w settings i truncate debug_payload przy OFF
$row = $db->query("SELECT v FROM settings WHERE k='debug_payload' LIMIT 1");
$cur = ($row && $row->num_rows > 0) ? $row->fetch_assoc()['v'] : '0';
$new = ($cur === '1') ? '0' : '1';
$db->query("REPLACE INTO settings (k, v) VALUES ('debug_payload', '{$new}')");
if ($new === '0') {
    $db->query("TRUNCATE TABLE debug_payload");
}
echo json_encode(['ok' => true, 'value' => ($new === '1' ? 'ON' : 'OFF')]);
