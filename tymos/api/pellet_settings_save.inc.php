<?php // POST
// Ustawienia pelletu — trzy niezalezne wartosci:
//   pellet_waga       - kg w worku (domyslna w formularzu)
//   pellet_cena       - cena ZAKUPU tych workow; trafia do tabeli `pellet` jako historia faktyczna
//   pellet_cena_rynek - biezaca cena SKLEPOWA; na niej opieraja sie WSZYSTKIE szacunki na wykresach
// pellet_prog_grzalki NIE jest tu zapisywany — ustala go actions/heating_cost.php co 30 min wg temperatury.
$db->query("CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) PRIMARY KEY, v VARCHAR(255)) ENGINE=InnoDB");

$waga      = (float)($_POST['waga']       ?? 0);
$cena      = (float)($_POST['cena']       ?? 0);
$cenaRynek = (float)($_POST['cena_rynek'] ?? 0);

if ($waga > 0) {
    $db->query("INSERT INTO settings (k,v) VALUES ('pellet_waga','" . $db->real_escape_string($waga) . "') ON DUPLICATE KEY UPDATE v=VALUES(v)");
}
if ($cena > 0) {
    $db->query("INSERT INTO settings (k,v) VALUES ('pellet_cena','" . $db->real_escape_string($cena) . "') ON DUPLICATE KEY UPDATE v=VALUES(v)");
}
if ($cenaRynek > 0) {
    $db->query("INSERT INTO settings (k,v) VALUES ('pellet_cena_rynek','" . $db->real_escape_string($cenaRynek) . "') ON DUPLICATE KEY UPDATE v=VALUES(v)");
}

echo '{"ok":true}';
