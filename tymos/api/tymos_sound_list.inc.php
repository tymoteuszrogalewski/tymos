<?php // GET
// Lista lektorow mp3 = zawartosc katalogu tymos/snd/. Nazwa dzwieku (tymos_sounds.sound) to
// nazwa pliku bez rozszerzenia — dzieki temu NOWY dzwiek wymaga tylko wgrania pliku, bez zmiany
// doorbell.inc.js (zmiana shella = pelne przeladowanie kiosku i tapniecie w ekran na iOS).
// `m` = mtime pliku, wedruje do URL jako cache-buster: podmienione nagranie kiosk dociaga sam.
$out = [];
foreach (glob(__DIR__ . '/../snd/*.mp3') as $f) {
    $out[] = ['n' => basename($f, '.mp3'), 'm' => (int)@filemtime($f)];
}
echo json_encode($out);
