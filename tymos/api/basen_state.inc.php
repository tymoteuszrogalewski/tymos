<?php // GET — stan basenu dla widgetu: pompa, force, okno, wynikowy want.
$POOL_ID = 1036;

// Okna auto w minutach od polnocy [start, koniec). SYNC z actions/ai_basen.php.
$windows = [[8 * 60, 10 * 60 + 30], [12 * 60, 15 * 60]];
$nowMin  = (int)date('G') * 60 + (int)date('i');
$inWindow = false;
foreach ($windows as $w) { if ($nowMin >= $w[0] && $nowMin < $w[1]) { $inWindow = true; break; } }

$r = $db->query("SELECT value FROM helpers WHERE name='force_basen'");
$force = ($r && $row = $r->fetch_assoc()) ? $row['value'] : 'auto';

$r  = $db->query("SELECT last_state FROM devices WHERE id={$POOL_ID}");
$ls = ($r && $row = $r->fetch_assoc()) ? (json_decode($row['last_state'] ?? '{}', true) ?: []) : [];
$state = strtoupper($ls['state'] ?? 'unknown');

$want = ($force === 'on') ? true : (($force === 'off') ? false : $inWindow);

echo json_encode([
    'ok'       => true,
    'state'    => $state,
    'force'    => $force,
    'inWindow' => $inWindow,
    'want'     => $want,
]);
