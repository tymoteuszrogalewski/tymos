<?php // GET — JPG poster z tmpfs (snap_<src>.jpg) z no-cache headerami zeby Safari nie buforowal
$src = $_GET['src'] ?? '';
if (!in_array($src, $go2rtc_valid, true)) { http_response_code(400); exit; }
// Snapshoty robi snapshot_refresh tylko dla JEDNEJ jakosci na kamere (parking_sd, basen_hd...).
// Gdy pytanie idzie o ta druga, oddajemy kadr z tej samej kamery — ten sam widok, inna rozdzielczosc.
$file = "/tmp/tymos/snap_{$src}.jpg";
if (!is_file($file)) {
    $alt  = (substr($src, -3) === '_hd') ? substr($src, 0, -3) . '_sd' : substr($src, 0, -3) . '_hd';
    $file = "/tmp/tymos/snap_{$alt}.jpg";
}
if (!is_file($file)) { http_response_code(404); exit; }
header_remove('Cache-Control');
header_remove('Pragma');
header_remove('Expires');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Content-Type: image/jpeg');
readfile($file);
