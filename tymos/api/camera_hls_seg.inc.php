<?php // GET
$qs = $_SERVER['QUERY_STRING'] ?? '';
preg_match('/id=([a-zA-Z0-9]+)/', $qs, $m_id);
preg_match('/n=(\d+)/', $qs, $m_n);
if (empty($m_id[1]) || !isset($m_n[1])) { http_response_code(400); exit; }
$url = $go2rtc_base . '/api/hls/segment.ts?id=' . $m_id[1] . '&n=' . $m_n[1];
$data = @file_get_contents($url);
if ($data === false) { http_response_code(502); exit; }
header('Content-Type: video/mp2t');
echo $data;
