<?php // GET
$src = $_GET['src'] ?? '';
if (!in_array($src, $go2rtc_valid, true)) { http_response_code(400); exit; }
$data = @file_get_contents($go2rtc_base . '/api/stream.m3u8?src=' . $src);
if ($data === false) { http_response_code(502); exit; }
$data = preg_replace('#^(hls/playlist\.m3u8\?id=)(.+)$#m', 'api.php?action=camera_hls_sub&id=$2', $data);
header('Content-Type: application/vnd.apple.mpegurl');
echo $data;
