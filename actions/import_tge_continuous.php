#!/usr/bin/env php
<?php
/**
 * import_tge_continuous.php — scrap continuous trading TGE (Last Trade) na D+1
 * Dziala 8:00-10:30 D-1 (przed Fixing I) — daje wczesniejsze szacunki cen na jutro.
 * Cron: co 30 min od 8:00 do 11:00 (akcja system_tge_continuous, cron "0,30 8-10 * * *")
 *
 * Strona TGE pokazuje dla kazdej godziny D+1 dwie kolumny Continuous:
 *   1. cena ostatniej transakcji (PLN/MWh)
 *   2. wolumen sesji
 * Brak transakcji = "-" (zostawiamy stara wartosc lub NULL).
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

function db_query(string $sql): bool {
    global $db;
    $ok = $db->query($sql);
    if (!$ok) tymos_log('ERROR', "SQL: {$db->error} | " . substr($sql, 0, 200));
    return (bool)$ok;
}

// Sesja TGE = dzisiaj, dostawa na D+1 (jutro)
$session = gmdate('d-m-Y');
echo "Fetch TGE Continuous (session $session)...\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => "https://tge.pl/energia-elektryczna-rdn?dateShow={$session}&type=1",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT      => 'TymOS/1.0',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$html = curl_exec($ch);
curl_close($ch);

if (!$html) { tymos_log('WARN', "TGE fetch failed session {$session}"); exit(1); }
if (!preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $m)) { echo "TGE: brak tbody\n"; exit(1); }

$tbody = $m[1];
preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $tbody, $rows);
$count = 0;

foreach ($rows[1] as $row) {
    if (!preg_match('/(\d{4}-\d{2}-\d{2})_H(\d{2})/', $row, $lm)) continue;
    $delivery_date = $lm[1];
    $hour = (int)$lm[2];
    $ts_hour = sprintf('%02d', $hour - 1);
    $ts = "{$delivery_date} {$ts_hour}:00:00";

    if (!preg_match('/Continuous start -->(.*?)<!-- Continuous end/s', $row, $cm)) continue;

    // Dwie komorki w sekcji Continuous: [0]=cena PLN/MWh, [1]=wolumen MWh
    if (!preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $cm[1], $tds)) continue;
    if (!isset($tds[1][0])) continue;

    $price_raw = strip_tags($tds[1][0]);
    // TGE separator tysiecy = spacja ("-1 577,08") — PHP (float) urywa parsing na spacji
    $price_raw = preg_replace('/\s+/', '', trim($price_raw));
    $price_raw = str_replace(',', '.', $price_raw);

    if ($price_raw === '' || $price_raw === '-' || !preg_match('/-?\d/', $price_raw)) {
        // brak transakcji w continuous dla tej godziny — zostaw stara wartosc
        continue;
    }

    $price_mwh = (float)$price_raw;
    $price_kwh = round($price_mwh / 1000, 6);
    db_query("INSERT INTO energa (ts, continuous_tge) VALUES ('{$ts}',{$price_kwh}) ON DUPLICATE KEY UPDATE continuous_tge={$price_kwh}");
    $count++;
}

echo date('Y-m-d H:i:s') . " | TGE continuous: {$count} godzin (session {$session})\n";
$db->close();
