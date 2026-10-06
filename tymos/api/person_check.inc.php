<?php // GET
$r = $db->query("SELECT UNIX_TIMESTAMP(ts) AS t FROM log WHERE (message LIKE '%[parking_kamera] person=True%' OR message LIKE '%[dzwonek] person=True%') AND ts > NOW() - INTERVAL 60 SECOND ORDER BY ts DESC LIMIT 1");
$row = $r ? $r->fetch_assoc() : null;
echo json_encode(['t' => $row ? (int)$row['t'] : 0]);
