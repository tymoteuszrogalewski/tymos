<?php // GET
$id = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['id'] ?? '');
if (!$id) { http_response_code(400); exit; }
$data = @file_get_contents($go2rtc_base . '/api/hls/playlist.m3u8?id=' . $id);
if ($data === false) { http_response_code(502); exit; }
$data = preg_replace('#^(segment\.ts\?id=)(.+)$#m', 'api.php?action=camera_hls_seg&id=$2', $data);
header('Content-Type: application/vnd.apple.mpegurl');
echo $data;
