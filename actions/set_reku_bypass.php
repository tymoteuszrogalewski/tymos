#!/usr/bin/env php
<?php
/**
 * set_reku_bypass.php — bypass rekuperatora przez ESPHome REST API
 * Uzycie: set_reku_bypass.php <on1h|on12h|auto>
 */

require_once __DIR__ . '/lib/_log.inc.php';

$ESP_IP = REKU_IP;
$endpoints = [
    'on1h'  => '/button/bypass_on__1h_/press',
    'on12h' => '/button/bypass_on__12h_/press',
    'auto'  => '/button/bypass_auto/press',
];

$action = $argv[1] ?? 'auto';
if (!isset($endpoints[$action])) {
    tymos_log('ERROR', "set_reku_bypass: nieznana akcja '{$action}' (dozwolone: on1h|on12h|auto)");
    exit(1);
}

$ctx = stream_context_create(['http' => ['method' => 'POST', 'content' => '', 'timeout' => 10, 'header' => "Content-Length: 0\r\n"]]);
$ok = @file_get_contents("http://{$ESP_IP}{$endpoints[$action]}", false, $ctx) !== false;
if (!$ok) tymos_log('ERROR', "set_reku_bypass: ESP {$ESP_IP} nie odpowiada ({$action})");
exit($ok ? 0 : 1);
