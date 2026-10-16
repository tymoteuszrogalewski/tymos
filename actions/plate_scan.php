<?php
/**
 * plate_scan.php <stream> — akcja "Parking Samochód Goście": odpala w tle utils/plate_scan.py.
 *
 * Demon TymOS uruchamia skrypty akcji przez `php` albo `bash`, a skan tablic jest w Pythonie
 * (venv z OpenCV) — stad ten cienki posrednik. Nie czekamy na wynik: skan trwa ~1 min
 * (30 s zbierania klatek + odczyt), sam pilnuje flock-a i sam wysyla Telegram + glosowke.
 */
$stream = $argv[1] ?? 'parking_hd';
exec('nohup /opt/tymos/venv-cv/bin/python3 /opt/tymos/utils/plate_scan.py '
    . escapeshellarg($stream) . ' > /dev/null 2>&1 &');
