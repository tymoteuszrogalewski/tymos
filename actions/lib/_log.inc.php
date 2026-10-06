<?php
/**
 * _log.inc.php — TymOS logger do tabeli `log` w DB
 * Uzycie (na poczatku skryptu akcji):
 *   require_once __DIR__ . '/lib/_log.inc.php';
 * Wywolanie:
 *   tymos_log('ERROR', 'Opis bledu');   // widac w /admin/ -> Logi
 *   tymos_log('WARN',  'Uwaga');
 *   tymos_log('INFO',  'Info');          // oszczednie — spam
 *
 * Shutdown handler lapie fatal errors automatycznie (PHP parse/compile/E_ERROR).
 */

require_once '/opt/tymos/tymos/config.inc.php';

/**
 * Cisza nocna dla komunikatow AGD (pralka/suszarka): 23:00-06:00 lektor NIE gra (2026-09-07, user).
 * Dotyczy TYLKO dzwiekow AGD — alarmy (osoba na parkingu/basenie, zalanie, garaz) graja cala dobe
 * i tej funkcji nie wolaja. Stan cyklu (helpers) zmienia sie normalnie, gubimy tylko dzwiek.
 * Od 2026-09-10 uzywa jej tez telegram_send.php: w tych godzinach kanal Wazne tylko dla "alarm":true.
 * Od 2026-10-01 w sobote i niedziele rano cisza do 09:00 (user: w weekend do 9 pelny spokoj).
 */
function tymos_cisza_nocna() {
    $h = (int)date('G');
    $koniec = ((int)date('N') >= 6) ? 9 : 6;
    return $h >= 23 || $h < $koniec;
}

function tymos_log($level, $message) {
    static $db = null;
    if ($db === false) return;
    if ($db === null) {
        $db = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
        if ($db->connect_error) { $db = false; return; }
    }
    $src = basename($_SERVER['SCRIPT_FILENAME'] ?? 'unknown', '.php');
    $msg = substr((string)$message, 0, 500);
    $stmt = $db->prepare("INSERT INTO log (level, source, message) VALUES (?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('sss', $level, $src, $msg);
        $stmt->execute();
        $stmt->close();
    }
}

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR])) {
        tymos_log('ERROR', "FATAL: {$err['message']} in " . basename($err['file']) . ":{$err['line']}");
    }
});
