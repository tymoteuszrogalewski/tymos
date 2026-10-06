<?php // POST
$bags      = (int)($_POST['bags']         ?? 0);
$waga      = (float)($_POST['waga_worka'] ?? 0);
$cena_tona = (float)($_POST['cena_tona']  ?? 0);
if ($bags <= 0 || $waga <= 0 || $cena_tona <= 0) { echo '{"ok":false}'; exit; }
$kg   = round($bags * $waga, 2);
$cost = round($bags * $waga / 1000 * $cena_tona, 2);
$db->query("INSERT INTO pellet (bags, kg, cost) VALUES ($bags, $kg, $cost)");
echo json_encode(['ok' => true, 'kg' => $kg, 'cost' => $cost]);
