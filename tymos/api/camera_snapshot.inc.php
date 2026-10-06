<?php // GET
$src = $_GET['src'] ?? '';
if (!in_array($src, $go2rtc_valid, true)) { http_response_code(400); exit; }
$data = @file_get_contents($go2rtc_base . '/api/frame.jpeg?src=' . $src);
if ($data === false) { http_response_code(502); exit; }
header('Content-Type: image/jpeg');
echo $data;
