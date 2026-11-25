<?php // POST
// Podwojny dotyk pulsujacego kosza na kamerze = „wystawione” recznie (2026-11-25, user). Dla sytuacji, ktorych
// kamera nie rozpozna: stary pusty kosz schowany i od razu wystawiony nowy, wakacje (nic do wystawienia).
// Flaga smieci_reczne_<dt> — actions/smieci_check.php stan jej nie odwoluje. Dalej (odbior) jak zwykle.
$f  = '/tmp/tymos/smieci_stan.json';
$st = @json_decode(@file_get_contents($f), true);
if (!is_array($st) || empty($st['dt']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $st['dt'])
    || !empty($st['zabrane']) || !empty($st['stoi'])) {
    echo json_encode(['ok' => false]);
    return;
}
@touch("/tmp/tymos/smieci_ok_{$st['dt']}");
@touch("/tmp/tymos/smieci_reczne_{$st['dt']}");
$st['stoi'] = true; $st['trafien'] = 2; $st['reczne'] = true;
@file_put_contents("$f.tmp", json_encode($st));
@rename("$f.tmp", $f);
echo json_encode(['ok' => true]);
