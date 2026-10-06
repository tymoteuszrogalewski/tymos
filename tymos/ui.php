<?php
/**
 * ui.php — cale UI TymOS (menu, panele, karty, JS poza silnikiem audio).
 *
 * Ladowane w <iframe> przez index.php (shell). Podzial istnieje z jednego powodu:
 * iOS wymaga dotkniecia uzytkownika, zeby wystartowac AudioContext, i to PO KAZDYM zaladowaniu
 * dokumentu. Silnik audio (doorbell.inc.js) zostaje wiec w shellu, ktory nigdy nie nawiguje —
 * a ta ramka moze sie przeladowywac po kazdym deployu bez gaszenia dzwieku.
 *
 * Dziala tez samodzielnie (bez ramki) — wtedy `window.parent === window` i przekazywanie
 * gestow jest pomijane, a env(safe-area-inset-top) dziala natywnie.
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/inc/panels.inc.php';
$panels      = scanPanels();
$defaultPanel = array_key_first($panels);
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<?php /* Metatagi apki — w ramce iOS je ignoruje, ale dzieki nim wejscie WPROST na ui.php
         (wyjscie awaryjne, gdy shell cokolwiek zepsuje) dziala jak dawny index.php. */ ?>
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<title>TymOS</title>
<script src="js/jquery.min.js?x=<?= date('YmdHis') ?>"></script>
<style>
<?php include __DIR__ . '/index/style.inc.css'; ?>
<?php include __DIR__ . '/index/style_admin.inc.css'; ?>
</style>
</head>
<body>

<div id="divMenu">
<?php foreach ($panels as $name => $cfg):
    $cls = 'menu-item' . ($name === 'admin' ? ' menu-item-push' : '');
?>
  <div class="<?= $cls ?>" data-panel="<?= htmlspecialchars($name) ?>"
       data-color="<?= htmlspecialchars($cfg['icon_color'] ?? '') ?>"
       title="<?= htmlspecialchars($name) ?>"><?= $cfg['icon'] ?></div>
<?php endforeach; ?>
</div>

<div id="divSubMenu" class="hidden"></div>

<div id="divMain"></div>
<!-- szara szyba pod oknem rozwiniec (winShow w cards.inc.js); samo okno to element karty z klasa .win.
     Przy pierwszym pokazie JS przenosi ja do #divMain (ten sam kontekst warstw co okno — iOS). -->
<div id="winGlass"></div>

<script>
<?php
include __DIR__ . '/index/webrtc.inc.js';
include __DIR__ . '/index/panels.inc.js';
include __DIR__ . '/index/cards.inc.js';
include __DIR__ . '/index/menu.inc.js';
include __DIR__ . '/index/swipe.inc.js';
include __DIR__ . '/index/admin_js.inc.js';
include __DIR__ . '/index/admin_actions_js.inc.js';
include __DIR__ . '/index/admin_devices_js.inc.js';
?>

// --- most do silnika audio w shellu ---
// AudioContext zyje w index.php (rodzic). Ta ramka musi:
//   1. przekazywac gesty w gore — iOS odblokowuje audio tylko dotknieciem w dokumencie,
//      w ktorym kontekst powstal, a wszystkie dotkniecia trafiaja tutaj,
//   2. udostepnic _tymosUnlock/_tymosAudioState pod TYMI SAMYMI nazwami w swoim window,
//      zeby karty (np. card_camera_doorbell) dzialaly bez zmian.
// Uruchomione bez ramki (wejscie wprost na ui.php) — blok sie pomija.
(function() {
    if (window.parent === window) return;
    var P = window.parent;

    function fwd() { try { if (P._tymosUnlock) P._tymosUnlock(); } catch(e) {} }
    document.addEventListener('touchstart', fwd);
    document.addEventListener('click', fwd);

    // Filmy: obserwator w shellu nie widzi DOM tej ramki, wiec 'playing' lapiemy tutaj
    new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            m.addedNodes.forEach(function(n) {
                if (!n.querySelectorAll) return;
                var vids = n.tagName === 'VIDEO' ? [n] : n.querySelectorAll('video');
                for (var i = 0; i < vids.length; i++) vids[i].addEventListener('playing', fwd);
            });
        });
    }).observe(document.body, {childList: true, subtree: true});

    window._tymosUnlock     = fwd;
    window._tymosAudioState = function() {
        try { return P._tymosAudioState ? P._tymosAudioState() : 'none'; } catch(e) { return 'none'; }
    };
    try { window._tymosSounds = P._tymosSounds; } catch(e) {}   // przegladarka dzwonkow w adminie
})();

// --- start ---
(function() {
    var savedPanel, savedTab;
    try { savedPanel = localStorage.getItem('tymos_panel'); savedTab = localStorage.getItem('tymos_tab'); } catch(e) {}
    var startPanel = savedPanel || '<?= $defaultPanel ?>';
    loadPanel(startPanel, savedTab || null);
})();
</script>
</body>
</html>
