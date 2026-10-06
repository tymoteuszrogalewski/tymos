<?php
/**
 * Raw MQTT publish przez fsockopen (bez mosquitto_pub)
 * Zwraca true/false
 */
function mqtt_publish($topic, $payload) {
    $host = MQTT_HOST;
    $port = 1883;
    $sock = @fsockopen($host, $port, $errno, $errstr, 2);
    if (!$sock) return false;

    $clientId = "tymos_" . getmypid();
    $user = MQTT_USER;
    $pass = MQTT_PASS;
    $cp = chr(0x00) . chr(0x04) . "MQTT" . chr(0x04) . chr(0xC2) . chr(0x00) . chr(0x3C);
    $cp .= chr(0x00) . chr(strlen($clientId)) . $clientId;
    $cp .= chr(0x00) . chr(strlen($user)) . $user;
    $cp .= chr(0x00) . chr(strlen($pass)) . $pass;
    fwrite($sock, chr(0x10) . chr(strlen($cp)) . $cp);
    $resp = fread($sock, 4);
    if (strlen($resp) < 4 || ord($resp[3]) !== 0) {
        fclose($sock);
        return false;
    }

    $tl = strlen($topic);
    $pub = chr(($tl >> 8) & 0xFF) . chr($tl & 0xFF) . $topic . $payload;
    $pl = strlen($pub);
    $rl = '';
    do {
        $byte = $pl % 128;
        $pl = (int)($pl / 128);
        if ($pl > 0) $byte |= 0x80;
        $rl .= chr($byte);
    } while ($pl > 0);
    fwrite($sock, chr(0x30) . $rl . $pub);
    fwrite($sock, chr(0xE0) . chr(0x00));
    fclose($sock);
    return true;
}
