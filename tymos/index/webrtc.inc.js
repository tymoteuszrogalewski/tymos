// WebRTC — awaryjne zamykanie i restart kamer.
//
// WLASCICIELEM reakcji na `visibilitychange` jest closure kamery w inc/camera.inc.php: on wie,
// ktory strumien jest wybrany, podmienia poster i overlay "Loading...", i sam zdejmuje swoj
// listener przy sprzataniu karty. Bylo tu drugie, niezalezne `visibilitychange` -> restartWebRTC(),
// wiec na kazde obudzenie ekranu `start()` leciał DWA RAZY. Guard `pc !== localPc` zwykle to lapil,
// ale zostawalo okno: gdy pierwszy `start()` zdazyl wyslac POST do WHEP, a drugi w tym czasie
// zamknal jego PeerConnection, odpowiedz byla odrzucana i go2rtc zostawal z konsumentem, ktory
// nigdy nie zestawil ICE i wisial do timeoutu. Usuniete 2026-09-02.

// Awaryjne zamkniecie wszystkich kamer — wolane z loadPanel() przed re-renderem kart, jako drugi
// bezpiecznik obok cardRunCleanup(). Wola `_stop()`, a nie `close()` na `cam_*_pc`, bo `stop()`
// zamyka takze `mic_pc` (rozmowa z dzwonkiem) i `listen_pc` (dzwiek z kamery) oraz kasuje watchdoga.
function closeWebRTC() {
    for (var k in window) {
        if (k.match(/^cam_.*_stop$/) && typeof window[k] === 'function') {
            try { window[k](); } catch(e) {}
        }
    }
}

function restartWebRTC() {
    for (var k in window) {
        if (k.match(/^cam_.*_restart$/) && typeof window[k] === 'function') {
            window[k]();
        }
    }
}

// Powrot strony z bfcache: `visibilitychange` moze sie wtedy NIE odpalic, wiec strumienie
// trzeba podniesc tutaj. To jedyny powod, dla ktorego ten plik nadal sluchá czegokolwiek.
window.addEventListener('pageshow', function(e) {
    if (e.persisted) restartWebRTC();
});
