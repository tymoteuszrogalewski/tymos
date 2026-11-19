<?php // GET
// Czy smieci na najblizszy wywoz sa wystawione — ikonka kosza z haczykiem w pasku ostrzezen kamery.
// Stan liczy actions/smieci_check.php stan (akcja cron co 10 min) i zapisuje do /tmp/tymos/smieci_stan.json.
// Stary plik (> 30 min, np. akcja stoi) albo po 23:59 dnia PO wywozie = brak ikonki. Znikniecie po odbiorze
// (smieciarka zabrala) liczy smieci_check.php.
$st = @json_decode(@file_get_contents('/tmp/tymos/smieci_stan.json'), true);
$on = is_array($st) && !empty($st['stoi']) && !empty($st['dt'])
   && time() - (int)($st['ts'] ?? 0) < 1800
   && time() < strtotime($st['dt'] . ' +2 days');
echo json_encode(['ok' => true, 'on' => $on, 'typy' => $on ? array_values($st['typy']) : [], 'dt' => $on ? $st['dt'] : null]);
