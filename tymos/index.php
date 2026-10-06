<?php
/**
 * index.php — SHELL TymOS. Punkt wejscia (https://tymos/).
 *
 * Po co shell zamiast calego UI w jednym pliku: iOS wymaga dotkniecia uzytkownika, zeby
 * wystartowac AudioContext, i to PO KAZDYM zaladowaniu dokumentu. Dopoki cale UI siedzialo tutaj,
 * kazdy deploy JS = przeladowanie strony = martwe audio do momentu tapniecia czerwonego paska.
 *
 * Ten plik jest cienka skorupa, ktora NIGDY nie nawiguje:
 *   - trzyma silnik audio (doorbell.inc.js) i AudioContext,
 *   - laduje cale UI w <iframe src="ui.php">,
 *   - po zmianie pliku tymos/VERSION przeladowuje TYLKO ramke -> dzwiek przezywa, zero dotkniec.
 *
 * Tapiesz raz, przy pierwszym uruchomieniu apki. Potem juz nigdy — chyba ze iPad wyrzuci strone
 * z pamieci, zrestartujesz apke, albo zmieni sie sam silnik audio (wtedy shell tez musi sie
 * przeladowac, bo AudioContext zyje w TYM dokumencie).
 *
 * WYJSCIE AWARYJNE: https://tymos/ui.php — pelne UI bez ramki i bez shella. ui.php jest
 * napisany tak, ze dziala samodzielnie (blok mostu do rodzica sam sie pomija).
 *
 * index2.php = alias do tego pliku, na czas az wyczysci sie cache apki na urzadzeniach,
 * ktore maja zapamietany stary index.php z przekierowaniem. Do usuniecia po kilku miesiacach.
 *
 * safe-area: env(safe-area-inset-top) w ramce zwraca 0, bo to wlasciwosc viewportu, nie ramki.
 * Shell mierzy je u siebie sondą #satProbe i wstrzykuje do ramki jako --sat. style.inc.css
 * uzywa var(--sat, env(safe-area-inset-top)), wiec ui.php dziala tez samodzielnie.
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<title>TymOS</title>
<script src="js/jquery.min.js?x=<?= date('YmdHis') ?>"></script>
<style>
  html, body { margin: 0; padding: 0; height: 100%; overflow: hidden; background: #1a1a1a; }
  #uiFrame   { position: fixed; inset: 0; width: 100%; height: 100%; border: 0; display: block; }
  /* sonda do odczytu safe-area — poza ekranem, nieklikalna */
  #satProbe  { position: fixed; top: 0; left: 0; width: 0;
               height: env(safe-area-inset-top); pointer-events: none; visibility: hidden; }
</style>
</head>
<body>

<div id="satProbe"></div>
<iframe id="uiFrame" src="ui.php" title="TymOS"></iframe>

<script>
// Wstrzykniecie safe-area do ramki. Wolane po kazdym jej zaladowaniu i przy obrocie ekranu,
// bo po reloadzie ramki jej dokument jest nowy i traci ustawiona zmienna.
function _satPush() {
    var f = document.getElementById('uiFrame');
    if (!f || !f.contentDocument || !f.contentDocument.documentElement) return;
    var h = getComputedStyle(document.getElementById('satProbe')).height;
    if (!h || h === 'auto') h = '0px';
    f.contentDocument.documentElement.style.setProperty('--sat', h);
}
document.getElementById('uiFrame').addEventListener('load', _satPush);
window.addEventListener('orientationchange', function() { setTimeout(_satPush, 300); });
window.addEventListener('resize', function() { setTimeout(_satPush, 300); });

<?php include __DIR__ . '/index/doorbell.inc.js'; ?>
</script>
</body>
</html>
