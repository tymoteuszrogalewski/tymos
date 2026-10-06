#!/usr/bin/env php
<?php
/**
 * availability_check.php — systemowy watchdog dostepnosci urzadzen
 *
 * 1. Pinguje (MQTT publish `get <field>`) Zigbee urzadzenia sieciowe (non-battery) —
 *    wyzwala push ktory daemon tymos.py zaktualizuje last_seen.
 *    Wybieramy pierwsze pole z access bit 4 (gettable). Niektore urzadzenia
 *    (np. Moes MINI-ZBRBS) maja `state` tylko z access 3 (pub+set, bez get) —
 *    wtedy `get state` nic nie robi. Dla rolet/valve to zwykle `position`.
 *    Bateryjne pomijamy (spia, ping ich nie obudzi).
 * 2. Dla wszystkich urzadzen sprawdza `last_seen` vs NOW:
 *      - sieciowe: timeout 60 min (3600 s) — niektore przelaczniki milcza dlugo
 *      - bateryjne: timeout 48h (172800 s) — SNZB-* raportuja rzadko
 *    Ustawia `devices.available=1` lub `=0` zgodnie z tym.
 *
 * Wolane co 5 minut przez akcje GUI "system_availability_check".
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

// === 1. Ping non-battery Zigbee (MQTT publish) ===
$r = $db->query("SELECT ieee, device, fields FROM devices
                 WHERE deleted=0 AND ieee LIKE '0x%'");
$priority = ['state', 'position', 'state_l1', 'state_l2'];
while ($row = $r->fetch_assoc()) {
    $fields = json_decode($row['fields'], true) ?: [];
    if (isset($fields['battery'])) continue;  // bateryjne spia — ping nie obudzi
    $getField = null;
    foreach ($priority as $p) {
        if (isset($fields[$p]['access']) && ($fields[$p]['access'] & 4)) { $getField = $p; break; }
    }
    if (!$getField) {
        foreach ($fields as $fname => $finfo) {
            if (!is_array($finfo)) continue;
            $cat = $finfo['category'] ?? '';
            if ($cat === 'diagnostic' || $cat === 'config') continue;
            if (isset($finfo['access']) && ($finfo['access'] & 4)) { $getField = $fname; break; }
        }
    }
    if (!$getField) continue;
    $topic   = "zigbee2mqtt/{$row['device']}/get";
    $payload = '{"' . $getField . '":""}';
    exec("mosquitto_pub -h localhost -t " . escapeshellarg($topic) . " -m " . escapeshellarg($payload) . " -q 0 2>/dev/null");
}

// === 2. Sprawdz last_seen vs timeout, ustaw available ===
$AVAIL_TIMEOUT_NET = 3600;        // 60 min dla urzadzen sieciowych (niektore milcza dlugo)
$AVAIL_TIMEOUT_BAT = 172800;      // 48h dla urzadzen bateryjnych (SNZB-* raportuja rzadko)

$r = $db->query("SELECT ieee, fields, available,
                        TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS age_sec
                 FROM devices
                 WHERE deleted=0 AND last_seen IS NOT NULL");
$updated = 0;
while ($row = $r->fetch_assoc()) {
    $fields = json_decode($row['fields'], true) ?: [];
    $is_battery = isset($fields['battery']);
    $timeout = $is_battery ? $AVAIL_TIMEOUT_BAT : $AVAIL_TIMEOUT_NET;
    $avail = ($row['age_sec'] > $timeout) ? 0 : 1;
    if ((int)$row['available'] !== $avail) {
        $stmt = $db->prepare("UPDATE devices SET available=? WHERE ieee=?");
        $stmt->bind_param('is', $avail, $row['ieee']);
        $stmt->execute();
        $stmt->close();
        $updated++;
    }
}

// INFO o liczbie zmian nie logowane — admin/Logi tylko dla ERROR/WARN.
