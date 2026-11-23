<?php // GET
// Najblizszy wywoz — ikonka kosza w pasku ostrzezen kamery: sam kosz = trzeba wystawic (od 0:00 dnia przed),
// kosz z haczykiem (ok=true) = wystawione (2026-11-23, user).
// Stan liczy actions/smieci_check.php stan (akcja cron co 10 min) i zapisuje do /tmp/tymos/smieci_stan.json.
// Stary plik (> 30 min, np. akcja stoi) albo po 23:59 dnia PO wywozie = brak ikonki. Znikniecie po odbiorze
// (smieciarka zabrala) liczy smieci_check.php.
$st = @json_decode(@file_get_contents('/tmp/tymos/smieci_stan.json'), true);
$on = is_array($st) && !empty($st['dt']) && !empty($st['typy']) && empty($st['zabrane'])
   && time() - (int)($st['ts'] ?? 0) < 1800
   && time() < strtotime($st['dt'] . ' +2 days');
echo json_encode(['ok' => true, 'on' => $on, 'wystawione' => $on && !empty($st['stoi']),
                  'typy' => $on ? array_values($st['typy']) : [], 'dt' => $on ? $st['dt'] : null]);
