#!/usr/bin/env php
<?php
/**
 * backup_pull_check.php — czy laptop pobiera backupy TymOS.
 * Mac (~/Library/Scripts/tymos_pull.sh) po udanym rsync zapisuje na Pi `/opt/tymos/backup/.last_pull`
 * z nazwa najnowszego pobranego archiwum (tymos-RRRR-MM-DD.tar.gz).
 * Najnowszy pobrany backup starszy niz MAX_DNI = Telegram Wazne. Brak pliku = tak samo (np. po reinstalu).
 * Pi trzyma backupy 14 dni — 7 dni zostawia tydzien zapasu na wlaczenie laptopa.
 * Harmonogram: akcja GUI „system_backup_pull_check”, cron 12:00 — alarm raz dziennie do skutku.
 */

require_once __DIR__ . '/lib/_log.inc.php';

const MAX_DNI = 7;
$plik = '/opt/tymos/backup/.last_pull';

$nazwa = is_file($plik) ? trim((string)file_get_contents($plik)) : '';
$ts = preg_match('/tymos-(\d{4}-\d{2}-\d{2})\.tar\.gz/', $nazwa, $m) ? strtotime($m[1]) : false;

if ($ts !== false && time() - $ts <= MAX_DNI * 86400) exit(0);

$msg = $ts === false
    ? "⚠️ Backup TymOS: brak znacznika pobrania na laptopa (.last_pull)."
    : "⚠️ Backup TymOS: laptop nie pobral backupu od " . floor((time() - $ts) / 86400) . " dni (ostatni {$m[1]}).";
tymos_log('WARN', $msg);

$proc = popen('php /opt/tymos/actions/telegram_send.php > /dev/null 2>&1', 'w');
if ($proc) { fwrite($proc, json_encode(['type' => 'text', 'msg' => $msg, 'chat' => 'alert'])); pclose($proc); }
