<?php // GET
$r = $db->query("SELECT id, sound, UNIX_TIMESTAMP(ts) AS t FROM tymos_sounds WHERE ts > NOW() - INTERVAL 30 SECOND ORDER BY ts DESC LIMIT 1");
$row = $r ? $r->fetch_assoc() : null;

// `ver` = znacznik wersji UI z pliku tymos/VERSION, bumpowany na KONCU deployu (`date +%s > VERSION`).
// Klient (doorbell.inc.js) zapamietuje go przy pierwszym pollu i przy zmianie robi pelne przeladowanie.
// Piggyback na tym pollingu (co 3 s) — zero dodatkowych zapytan i zero nowego endpointu.
// Bump RECZNY (nie z mtime plikow), zeby przeladowanie nie poszlo w polowie scp, gdy czesc plikow
// jest nowa a czesc stara. VERSION nie jest w repo (.gitignore) — zyje tylko na Pi.
$ver = trim((string)@file_get_contents(__DIR__ . '/../VERSION'));
if ($ver === '') $ver = '0';

$out = $row ? ['id' => (int)$row['id'], 'sound' => $row['sound'], 't' => (int)$row['t']] : ['id' => 0];
$out['ver'] = $ver;
echo json_encode($out);
