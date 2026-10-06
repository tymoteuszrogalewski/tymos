#!/usr/bin/env php
<?php
/**
 * snapshot_refresh.php — pobiera JPG snapshoty z go2rtc dla kamer aktywnych w tymosu
 * i zapisuje do /tmp/tymos/ (tmpfs, RAM — zero zapisow SD).
 *
 * Apache serwuje potem jako static `/snap/<stream>.jpg` przez Alias — instant load.
 * TymOS uzywa tego pliku jako poster <video> (load i reconnect kamer).
 *
 * Wolane co 10 sekund przez akcje GUI "system_snapshot_refresh".
 * Zapis atomic (tmp + rename) — czytelnik nigdy nie dostanie polowicznego pliku.
 */

require_once __DIR__ . '/lib/_log.inc.php';

// Streamy dla ktorych robimy snapshot. Musza byc w autostart go2rtc (czyli zawsze zywe)
// zeby snapshot nie wymagal cold start RTSP.
// Basen leci z HD: kamerka jest slaba, w SD miniaturka i poster sa nieczytelne. `basen_hd`
// trzyma keepalive (`utils/go2rtc_keepalive.sh`), wiec jest cieply — snapshot bez cold startu.
$STREAMS = ['doorbell_sd', 'parking_sd', 'basen_hd'];

$DEST_DIR = '/tmp/tymos';
$GO2RTC   = 'http://127.0.0.1:1984/api/frame.jpeg';

// Cron granulacja = 1 min, a chcemy snapshot co 10s — w jednym uruchomieniu
// robimy 5 iteracji co 10s (50s total). Cron odpala skrypt co minute = plynne pokrycie.
$ITERATIONS  = 5;
$INTERVAL    = 10;
$total_fail  = 0;
$fail_per_stream = [];
foreach ($STREAMS as $s) $fail_per_stream[$s] = 0;

for ($i = 0; $i < $ITERATIONS; $i++) {
    foreach ($STREAMS as $stream) {
        $url = $GO2RTC . '?src=' . urlencode($stream);
        $jpg = @file_get_contents($url);
        if ($jpg === false || strlen($jpg) < 100) {
            $total_fail++; $fail_per_stream[$stream]++;
            continue;
        }
        // Atomic write: tmp + rename (tmpfs -> rename jest instant, czytelnik nigdy nie dostaje polowicznego pliku)
        $final = "{$DEST_DIR}/snap_{$stream}.jpg";
        $tmp   = "{$final}.tmp";
        if (@file_put_contents($tmp, $jpg) === false) { $total_fail++; $fail_per_stream[$stream]++; continue; }
        if (!@rename($tmp, $final)) { @unlink($tmp); $total_fail++; $fail_per_stream[$stream]++; continue; }
    }
    if ($i < $ITERATIONS - 1) sleep($INTERVAL);
}

// Kamera caly czas pada (wszystkie iteracje) = realny problem — loguj z nazwami.
$dead = [];
foreach ($fail_per_stream as $s => $f) if ($f === $ITERATIONS) $dead[] = $s;
$total = $ITERATIONS * count($STREAMS);
$thr_warn = (int) ceil($total / 2);  // >= polowa prob padnieta
if (!empty($dead)) {
    tymos_log('ERROR', 'snapshot_refresh: kamery NIEDOSTEPNE: ' . implode(', ', $dead));
} elseif ($total_fail >= $thr_warn) {
    tymos_log('WARN', "snapshot_refresh: {$total_fail}/{$total} pobrania nieudane");
}
// Mniej niz polowa padnieta = szum sieciowy, nie loguj.
