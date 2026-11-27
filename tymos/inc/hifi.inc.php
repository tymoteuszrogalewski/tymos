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
 * CD, OPT, FM, DAB+ i zasilanie obsluguje GLOWNY procesor wiezy — przez panaext:set/alljoyn (ponizej).
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

/**
 * Polecenia GLOWNEGO procesora wiezy (zasilanie, wybor zrodla) — podsluchane z aplikacji Technics
 * Audio Center 2026-11-27 (aplikacja gada zwyklym HTTP na port 80):
 *   setData path=panaext:set/alljoyn roles=activate value={"apiname":"SetSelector","arg":"<base64>"}
 * Odpowiedz: {"result":0,"apiname":...,"arg":"<base64>"} (result 0 = OK).
 * $bytes — surowe bajty argumentu, np. "\x02\x12"; zwraca bajty odpowiedzi albo null przy bledzie.
 */
const HIFI_POWER_ON  = "\x01";
const HIFI_POWER_OFF = "\x00";
const HIFI_SRC = [            // SetSelector / GetSelector
    'cd'     => "\x02\x00",
    'fm'     => "\x02\x05",
    'dab'    => "\x02\x07",
    'opt'    => "\x02\x12",
    'radio'  => "\x02\x15",   // radio internetowe (airable)
    'stream' => "\x02\x1a",   // Spotify / siec
];

function hifi_alljoyn($apiname, $bytes = '') {
    $d = hifi_req('setData', [
        'path'  => 'panaext:set/alljoyn',
        'roles' => 'activate',
        'value' => json_encode(['apiname' => $apiname, 'arg' => $bytes === '' ? '' : base64_encode($bytes)]),
    ]);
    if (!is_array($d) || ($d['result'] ?? -1) !== 0) return null;
    return base64_decode($d['arg'] ?? '');
}

// Nazwa biezacego zrodla z HIFI_SRC albo null (nieznane / brak odpowiedzi).
function hifi_source() {
    $b = hifi_alljoyn('GetSelector');
    if ($b === null) return null;
    $k = array_search($b, HIFI_SRC, true);
    return $k === false ? null : $k;
}

/**
 * Zapamietanie ostatniego odtwarzania Spotify — przycisk SPOTIFY (api hifi_set src=spotify) wznawia je
 * bez telefonu. Wola to usluga tymos-tv-hifi co kilka sekund. Plik w tmpfs: po restarcie Pi wystarczy
 * raz puscic Spotify z telefonu.
 * mediaRoles to sesja z chwili przejecia z telefonu: w adresie (preset) jest na sztywno trackUri utworu
 * startowego i nie zmienia sie przy kolejnych utworach — wznowienie zaczynalo zawsze od niego (user
 * 2026-11-27: "codziennie od tego samego utworu"). Podmieniamy go na biezacy (metaData.trackId).
 */
const HIFI_SPOTIFY_FILE = '/tmp/tymos/hifi_spotify.json';

function hifi_spotify_remember() {
    $pd = hifi_get('player:player/data');
    if (!$pd || ($pd['state'] ?? '') !== 'playing' || empty($pd['mediaRoles'])) return;
    $meta = $pd['trackRoles']['mediaData']['metaData'] ?? [];
    if (($meta['serviceID'] ?? '') !== 'spotify') return;
    $mr  = $pd['mediaRoles'];
    $cur = $meta['trackId'] ?? '';
    if (preg_match('/^spotify:track:[A-Za-z0-9]+$/', $cur)) {
        foreach ($mr['mediaData']['resources'] ?? [] as $i => $res) {
            $mr['mediaData']['resources'][$i]['uri'] = preg_replace(
                '/(trackUri%22:%22)spotify:track:[A-Za-z0-9]+/', '${1}' . $cur, (string)($res['uri'] ?? ''));
        }
    }
    $json = json_encode($mr);
    if ($json !== @file_get_contents(HIFI_SPOTIFY_FILE)) {
        @mkdir(dirname(HIFI_SPOTIFY_FILE), 0777, true);
        @file_put_contents(HIFI_SPOTIFY_FILE, $json);
        @chmod(HIFI_SPOTIFY_FILE, 0666);
    }
}

// Sukces = wieza odpowiedziala i NIE zwrocila bledu. Zmiana wartosci zwraca `true`, a polecenia
// odtwarzacza (role=activate) `null` — 2026-10-08 karta pokazywala przez to „Nie udało się wznowić", a muzyka grala.
function hifi_set($path, $value, $role = 'value') {
    $d = hifi_req('setData', ['path' => $path, 'role' => $role, 'value' => json_encode($value)]);
    return $d !== null && !(is_array($d) && isset($d['error']));
}
