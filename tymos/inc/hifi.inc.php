<?php
/**
 * hifi.inc.php — lokalne API wiezy Technics SC-C70MK2 (HIFI_IP w configu).
 *
 * Wieza ma modul sieciowy StreamUnlimited (StreamSDK) z API HTTP bez hasla — oficjalna aplikacja
 * Technicsa go nie pokazuje, ale z sieci lokalnej mozna nim sterowac:
 *   GET /api/getData?path=<sciezka>&roles=value             -> [ {"type":"i32_","i32_":16} ]
 *   GET /api/setData?path=<sciezka>&role=value&value=<json>  -> true
 * Sciezki: player:volume (0-100, krok 1), settings:/mediaPlayer/mute, player:player/data (co gra),
 * player:player/control (role=activate, polecenia play/pause/...).
 * CD i wejscia cyfrowe (OPT) obsluguje GLOWNY procesor wiezy, nie modul sieciowy — w tym API ich nie
 * ma (stan 2026-10-08, do rozpracowania).
 *
 * Krotkie timeouty: wieza bywa w czuwaniu sieciowym, a karta nie moze przez nia wisiec.
 */

function hifi_req($endpoint, $query) {
    $ch = curl_init('http://' . HIFI_IP . '/api/' . $endpoint . '?' . http_build_query($query));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 3,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) return null;          // brak polaczenia z wieza
    // Polecenia odtwarzacza odpowiadaja doslownym `null` (HTTP 200) — to tez sukces, nie brak polaczenia
    return json_decode($raw, true) ?? true;
}

// Wartosc z getData (roles=value) albo null, gdy wieza nie odpowiada / sciezki nie ma.
function hifi_get($path) {
    $d = hifi_req('getData', ['path' => $path, 'roles' => 'value']);
    return (is_array($d) && isset($d[0]) && is_array($d[0])) ? $d[0] : null;
}

// Sukces = wieza odpowiedziala i NIE zwrocila bledu. Zmiana wartosci zwraca `true`, a polecenia
// odtwarzacza (role=activate) `null` — 2026-10-08 karta pokazywala przez to „Nie udało się wznowić", a muzyka grala.
function hifi_set($path, $value, $role = 'value') {
    $d = hifi_req('setData', ['path' => $path, 'role' => $role, 'value' => json_encode($value)]);
    return $d !== null && !(is_array($d) && isset($d['error']));
}
