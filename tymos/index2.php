<?php
/**
 * index2.php — alias do index.php (shell).
 *
 * Istnieje TYLKO dla urzadzen, ktore maja w cache apki stary index.php z przekierowaniem
 * `location.replace('index2.php?ts=...')`. Taki iPad wejdzie tutaj i dostanie shell.
 * Swiezy load index.php jest juz shellem, wiec nie odbija sie nigdzie.
 *
 * Zero duplikacji kodu — jedna linia require. Sciezki w shellu sa relatywne (ui.php,
 * js/jquery.min.js, snd/...), a oba pliki leza w tym samym katalogu, wiec dzialaja stad tak samo.
 *
 * DO USUNIECIA po kilku miesiacach, gdy cache wyczysci sie na wszystkich urzadzeniach.
 */
require __DIR__ . '/index.php';
