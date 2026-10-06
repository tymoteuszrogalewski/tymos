<?php
/**
 * outdoor_temps.inc.php — jeden niezmiennik dla temperatur zewnetrznych w calym TymOS:
 * SLONCE (dev1043 „Taras Temperatura") NIE MOZE byc nizsze niz CIEN (dev10 „Patio Temperatura").
 *
 * Czujnik w sloncu fizycznie nie moze byc chlodniejszy od powietrza w cieniu. Odwrotka pojawia
 * sie z dwoch powodow i OBA sa bledem pomiaru, nie stanem pogody:
 *   - CIEN siedzi w rurce fi32 na drzewie — duza bezwladnosc, w fazie wychladzania zostaje wysoko.
 *   - SLONCE to gola metalowa koncowka na kijku 1,5 m pod otwartym niebem — noca wypromieniowuje
 *     w niebo i schodzi PONIZEJ temperatury powietrza.
 * Zmierzone 2026-08-25 22:20: cien 13,3 / slonce 11,3 / reku outdoor 12,6 — odwrotka 2,0 C.
 *
 * Korygujemy SLONCE W GORE do cienia, NIGDY nie obnizamy cienia. Cien jest zrodlem dla
 * free-coolu (ai_wymienniki), ochrony przeciwmrozowej (ai_mroz), podlewania (irrigation)
 * i minimum w ai_rekuperator — zafalszowanie go w dol sterowaloby domem na zlych danych.
 * Efekt dla usera: przy odwrotce oba kafelki pokazuja IDENTYCZNA wartosc.
 *
 * UWAGA — NIE uzywac w roleta_sun_detect.php. Tam progi 3,0/2,0 sa nastrojone na SUROWA
 * roznice slonce-reku (pomiar 18-25.08.2026); podniesienie slonca w fazie wychladzania dalo by
 * falszywe „slonce" i zamykanie rolety bez powodu.
 */

// Zwraca skorygowane SLONCE. Brak ktoregokolwiek odczytu = zwroc slonce bez zmian
// (nie zgadujemy na niepelnych danych — patrz zasada fail-safe przy braku odczytu).
function outdoor_sun_fix($slonce, $cien) {
    if (!is_numeric($slonce) || !is_numeric($cien)) return $slonce;
    return ((float)$cien > (float)$slonce) ? (float)$cien : (float)$slonce;
}

// To samo dla serii wykresowych — indeks w indeks, dziury (null) przechodza bez zmian.
function outdoor_sun_fix_series(array $slonce, array $cien) {
    foreach ($slonce as $i => $v) {
        $slonce[$i] = outdoor_sun_fix($v, $cien[$i] ?? null);
    }
    return $slonce;
}
