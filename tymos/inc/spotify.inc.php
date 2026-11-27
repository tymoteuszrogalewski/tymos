<?php
/**
 * spotify.inc.php — Spotify Web API dla wiezy (wznawianie od miejsca, w ktorym skonczylismy).
 *
 * Po co (2026-11-27): wznawianie przez StreamSDK (player:player/control z zapamietanymi mediaRoles)
 * zawsze startowalo od utworu, od ktorego sesja przyszla z telefonu — wieza ignoruje trackUri.
 * Web API pozwala wlaczyc na wiezy konkretna playliste + utwor + sekunde.
 *
 * Klucze: /opt/tymos/secrets/spotify.json (client_id, client_secret, refresh_token; root:www-data 640),
 * aplikacja "TymOS" na developer.spotify.com, logowanie jednorazowe (OAuth, scope playback-state).
 * Access token (1 h) w cache /tmp/tymos/spotify_token.json.
 * Stan do wznowienia: /tmp/tymos/spotify_state.json — zapisuje usluga tymos-tv-hifi (spotify_remember).
 */

const SPOTIFY_SECRETS = '/opt/tymos/secrets/spotify.json';
const SPOTIFY_TOKEN   = '/tmp/tymos/spotify_token.json';
const SPOTIFY_STATE   = '/tmp/tymos/spotify_state.json';
const SPOTIFY_DEVICE  = 'HiFi Tymki';   // nazwa wiezy w Spotify Connect

function spotify_http($method, $url, $headers, $body = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 6,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $raw === false ? null : json_decode($raw, true)];
}

function spotify_token() {
    $c = json_decode((string)@file_get_contents(SPOTIFY_TOKEN), true);
    if ($c && ($c['exp'] ?? 0) > time() + 60) return $c['at'];
    $s = json_decode((string)@file_get_contents(SPOTIFY_SECRETS), true);
    if (!$s) return null;
    [$code, $d] = spotify_http('POST', 'https://accounts.spotify.com/api/token', [
        'Authorization: Basic ' . base64_encode($s['client_id'] . ':' . $s['client_secret']),
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query(['grant_type' => 'refresh_token', 'refresh_token' => $s['refresh_token']]));
    if ($code !== 200 || empty($d['access_token'])) return null;
    @mkdir(dirname(SPOTIFY_TOKEN), 0777, true);
    @file_put_contents(SPOTIFY_TOKEN, json_encode(['at' => $d['access_token'], 'exp' => time() + (int)($d['expires_in'] ?? 3600)]));
    @chmod(SPOTIFY_TOKEN, 0666);   // pisza go i root (usluga), i www-data (kiosk)
    return $d['access_token'];
}

// [kod HTTP, odpowiedz] albo [0, null] bez tokenu.
function spotify_api($method, $path, $body = null) {
    $at = spotify_token();
    if (!$at) return [0, null];
    return spotify_http($method, 'https://api.spotify.com/v1' . $path,
        ['Authorization: Bearer ' . $at, 'Content-Type: application/json'],
        $body === null ? null : json_encode($body));
}

/**
 * Zapamietanie miejsca: playlista/album (context), utwor, sekunda — tylko gdy gra NA WIEZY
 * (sluchanie na telefonie poza domem nie nadpisuje stanu wiezy).
 */
function spotify_remember() {
    [$code, $d] = spotify_api('GET', '/me/player');
    if ($code !== 200 || !is_array($d) || empty($d['is_playing'])) return;
    if (($d['device']['name'] ?? '') !== SPOTIFY_DEVICE) return;
    $uri = $d['item']['uri'] ?? '';
    if ($uri === '') return;
    $st = ['context' => $d['context']['uri'] ?? '', 'track' => $uri, 'pos' => (int)($d['progress_ms'] ?? 0),
           'title' => $d['item']['name'] ?? '', 'ts' => time()];
    @mkdir(dirname(SPOTIFY_STATE), 0777, true);
    @file_put_contents(SPOTIFY_STATE, json_encode($st));
    @chmod(SPOTIFY_STATE, 0666);
}

/**
 * Wznowienie na wiezy od zapamietanego miejsca. true = Spotify przyjal polecenie.
 * Wieza musi byc wlaczona (modul sieciowy widoczny w Spotify Connect).
 */
function spotify_resume_on_hifi() {
    $st = json_decode((string)@file_get_contents(SPOTIFY_STATE), true);
    if (!$st || empty($st['track'])) return false;
    $dev = null;
    for ($i = 0; $i < 6 && !$dev; $i++) {          // po wlaczeniu wiezy pojawia sie w Spotify po chwili
        if ($i) sleep(1);
        [$code, $d] = spotify_api('GET', '/me/player/devices');
        foreach (($code === 200 ? ($d['devices'] ?? []) : []) as $x) {
            if (($x['name'] ?? '') === SPOTIFY_DEVICE) { $dev = $x['id']; break; }
        }
    }
    if (!$dev) return false;
    $body = ['position_ms' => max(0, (int)$st['pos'] - 2000)];   // 2 s wstecz, zeby nie uciac zdania
    if (!empty($st['context'])) { $body['context_uri'] = $st['context']; $body['offset'] = ['uri' => $st['track']]; }
    else                         { $body['uris'] = [$st['track']]; }
    [$code] = spotify_api('PUT', '/me/player/play?device_id=' . urlencode($dev), $body);
    return $code === 204 || $code === 202 || $code === 200;
}
