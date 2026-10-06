<?php // GET
$res  = $db->query("SELECT DATE_FORMAT(dt,'%Y-%m-%d') AS dt, type FROM waste_calendar ORDER BY dt, type");
$data = [];
while ($row = $res->fetch_assoc()) $data[$row['dt']][] = $row['type'];
echo json_encode((object)$data);
