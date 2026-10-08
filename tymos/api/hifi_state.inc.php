<?php // GET — stan wiezy Technics dla karty hifi: glosnosc, wyciszenie, zrodlo, co gra.
require_once __DIR__ . '/../inc/hifi.inc.php';

$vol = hifi_get('player:volume');
if ($vol === null) { echo json_encode(['ok' => false, 'offline' => true]); exit; }

$mute = hifi_get('settings:/mediaPlayer/mute');
$pd   = hifi_get('player:player/data') ?? [];
$meta = $pd['trackRoles']['mediaData']['metaData'] ?? ($pd['mediaRoles']['mediaData']['metaData'] ?? []);
$svc  = $meta['serviceID'] ?? '';

// Ostatnie odtwarzanie Spotify zapamietujemy — przycisk SPOTIFY wznawia je po przelaczeniu na inne
// zrodlo (bez telefonu). Plik w tmpfs, po restarcie Pi wystarczy raz puscic Spotify z telefonu.
if ($svc === 'spotify' && !empty($pd['mediaRoles'])) {
    @file_put_contents('/tmp/tymos/hifi_spotify.json', json_encode($pd['mediaRoles']));
}
// Ostatni grany utwor — tez w tmpfs, zeby karta pokazywala go po zatrzymaniu, po wylaczeniu wiezy
// i po przeladowaniu kiosku (do restartu Pi). Gdy gra CD / OPT, modul sieciowy i tak nie wie, co gra.
$lastFile = '/tmp/tymos/hifi_last.json';
if (($pd['state'] ?? '') === 'playing' && !empty($pd['trackRoles']['title'])) {
    @file_put_contents($lastFile, json_encode([
        'title' => $pd['trackRoles']['title'], 'artist' => $meta['artist'] ?? '', 'album' => $meta['album'] ?? '',
        'cover' => $pd['trackRoles']['icon'] ?? '', 'src' => $svc,
    ]));
}
$last = json_decode((string)@file_get_contents($lastFile), true) ?: null;

$names = ['spotify' => 'Spotify', 'AUX' => 'AUX', 'airplay' => 'AirPlay', 'googlecast' => 'Chromecast',
          'bluetooth' => 'Bluetooth', 'tidal' => 'TIDAL', 'qobuz' => 'Qobuz', 'airable' => 'Radio'];

echo json_encode([
    'ok'     => true,
    'volume' => (int)($vol['i32_'] ?? 0),
    'mute'   => (bool)($mute['bool_'] ?? false),
    'state'  => $pd['state'] ?? '',                  // playing / paused / stopped
    'src'    => $svc,                                // surowy serviceID (spotify, AUX, ...)
    'srcName'=> $names[$svc] ?? ($svc !== '' ? $svc : ''),
    'title'  => $pd['trackRoles']['title'] ?? '',
    'artist' => $meta['artist'] ?? '',
    'album'  => $meta['album'] ?? '',
    'cover'  => $pd['trackRoles']['icon'] ?? '',   // URL okladki (Spotify: i.scdn.co)
    'last'   => $last,                                // ostatni grany utwor (gdy teraz nic nie gra)
]);
