<?php // POST
$src = $_POST['src'] ?? '';
if (!in_array($src, $go2rtc_valid, true)) { http_response_code(400); echo '{"ok":false}'; exit; }
// Restart producera w go2rtc — rozlacz RTSP i polacz ponownie (nie DELETE — to kasuje caly stream)
$ch = curl_init("http://localhost:1984/api/streams?src=" . urlencode($src));
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
// Jesli POST nie zadziala, fallback: restart calego go2rtc
if ($code >= 400) {
    exec('systemctl restart go2rtc 2>&1', $out, $ret);
    echo json_encode(['ok' => $ret === 0, 'method' => 'restart']);
} else {
    echo json_encode(['ok' => true, 'method' => 'stream_reset']);
}
