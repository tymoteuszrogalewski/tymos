<?php
/**
 * dongle_reboot.php — restart koordynatora Zigbee SMLIGHT SLZB-06Mg26U przez HTTP API (od 2026-09-09).
 *
 * Uzycie:
 *   php dongle_reboot.php           # restart samego chipa Zigbee (EFR32MG26) — default
 *   php dongle_reboot.php zb        # jw. (alias historyczny: mg24)
 *   php dongle_reboot.php esp       # tylko ESP32-S3 (mostek LAN/USB), Zigbee chodzi dalej (alias: esp32)
 *   php dongle_reboot.php all       # chip Zigbee + ESP32 — pelny restart dongla, ~40 s przestoju
 *
 * API SMLIGHT (core v3.x): GET http://DONGLE_IP/api2?action=4&cmd=<N>&idx=0, Basic auth (DONGLE_USER/PASS
 * z config.inc.php), odpowiedz "ok". cmd 1 = CMD_ZB_RST (reset chipa Zigbee), cmd 3 = CMD_ESP_RES (reboot ESP).
 * Zrodlo numerow: pysmlight/const.py (biblioteka SMLIGHT dla Home Assistant). Web UI dziala TAKZE gdy Z2M
 * trzyma port 6638 (o ile w Security nie jest wlaczone "Disable web server when socket is connected").
 *
 * Poprzednik (Sonoff ZBDongle Max, WebSocket ws://IP/api/ws + digest sha256) padl 2026-09-05.
 * Wolane z zigbee_recover.php (watchdog) z targetem mg24|all — aliasy zachowane, wynik "OK target=<arg>".
 */

require_once __DIR__ . '/lib/_log.inc.php';

$arg    = $argv[1] ?? 'zb';
$alias  = ['mg24' => 'zb', 'esp32' => 'esp'];
$target = $alias[$arg] ?? $arg;
if (!in_array($target, ['zb', 'esp', 'all'], true)) {
    tymos_log('ERROR', "Niewlasciwy target: $arg (oczekiwane: zb|esp|all)");
    exit(1);
}

function slzb_cmd(int $cmd): string {
    $ch = curl_init('http://' . DONGLE_IP . '/api2?action=4&cmd=' . $cmd . '&idx=0');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_USERPWD        => DONGLE_USER . ':' . DONGLE_PASS,
        CURLOPT_ENCODING       => '',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new Exception("cmd $cmd: $err");
    if ($code !== 200)   throw new Exception("cmd $cmd: HTTP $code" . ($code === 401 ? ' (zle haslo?)' : ''));
    if (trim($body) !== 'ok') throw new Exception("cmd $cmd: odpowiedz '" . substr($body, 0, 80) . "'");
    return $body;
}

try {
    if ($target === 'zb' || $target === 'all') slzb_cmd(1);
    if ($target === 'all') sleep(2);
    if ($target === 'esp' || $target === 'all') slzb_cmd(3);
    tymos_log('INFO', "Dongle reboot: target=$target — OK");
    echo "OK target=$arg\n";
} catch (Exception $e) {
    tymos_log('ERROR', "Dongle reboot fail (target=$target): " . $e->getMessage());
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
}
