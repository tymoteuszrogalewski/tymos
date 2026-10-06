<?php // POST
$src = $_GET['src'] ?? '';
if (!in_array($src, $go2rtc_valid, true)) { http_response_code(400); exit; }
$body = file_get_contents('php://input');
$ctx  = stream_context_create(['http' => [
    'method'  => 'POST',
    'header'  => 'Content-Type: application/json',
    'content' => $body,
]]);
$answer = @file_get_contents($go2rtc_base . '/api/webrtc?src=' . $src, false, $ctx);
if ($answer === false) { http_response_code(502); exit; }
header('Content-Type: application/json');
echo $answer;
