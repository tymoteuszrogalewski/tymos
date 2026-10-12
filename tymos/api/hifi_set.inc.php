<?php // POST — sterowanie wieza Technics: op=vol (delta), op=mute (on 0/1), op=src (spotify | cd | opt), op=ctl (previous | next | pause | play).
require_once __DIR__ . '/../inc/hifi.inc.php';

$op = $_POST['op'] ?? '';

if ($op === 'mute') {
    // Pauza z panelu = WYCISZENIE (2026-10-12): pause w Spotify Connect zrywa sesje i play zaczyna od nowa.
    // Muzyka gra dalej po cichu, glosnosc zostaje nietknieta, odciszenie wraca do tego samego poziomu.
    $on = !empty($_POST['on']);
    $ok = hifi_set('settings:/mediaPlayer/mute', ['type' => 'bool_', 'bool_' => $on]);
    echo json_encode(['ok' => $ok, 'mute' => $on]);
    exit;
}

if ($op === 'vol') {
    $delta = (int)($_POST['delta'] ?? 0);
    if ($delta < -20 || $delta > 20 || $delta === 0) { echo '{"ok":false}'; exit; }
    $cur = hifi_get('player:volume');
    if ($cur === null) { echo json_encode(['ok' => false, 'offline' => true]); exit; }
    $v = max(0, min(100, (int)$cur['i32_'] + $delta));
    $ok = hifi_set('player:volume', ['type' => 'i32_', 'i32_' => $v]);
    echo json_encode(['ok' => $ok, 'volume' => $v]);
    exit;
}

if ($op === 'ctl') {
    // Przyciski odtwarzacza. play = wznowienie po pauzie (to samo, co przycisk na pilocie).
    $ctl = $_POST['ctl'] ?? '';
    if (!in_array($ctl, ['previous', 'next', 'pause', 'play'], true)) { echo '{"ok":false}'; exit; }
    $ok = hifi_set('player:player/control', ['control' => $ctl], 'activate');
    echo json_encode(['ok' => $ok]);
    exit;
}

if ($op === 'src') {
    $src = $_POST['src'] ?? '';
    if ($src === 'spotify') {
        // Wznowienie ostatniego odtwarzania Spotify (zapamietane przez hifi_state). Bez zapamietanego
        // trzeba raz puscic Spotify z telefonu albo Maca.
        $mr = json_decode((string)@file_get_contents('/tmp/tymos/hifi_spotify.json'), true);
        if (!$mr) { echo json_encode(['ok' => false, 'error' => 'Puść raz Spotify z telefonu']); exit; }
        $ok = hifi_set('player:player/control', ['control' => 'play', 'mediaRoles' => $mr], 'activate');
        echo json_encode(['ok' => $ok]);
        exit;
    }
    // CD i OPT — glowny procesor wiezy, w API sieciowym ich nie ma (do rozpracowania).
    echo json_encode(['ok' => false, 'error' => 'CD/OPT jeszcze nieobsługiwane']);
    exit;
}

echo '{"ok":false}';
