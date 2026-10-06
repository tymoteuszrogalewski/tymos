#!/usr/bin/env php
<?php
/**
 * z2m_sync_devices.php — systemowy sync metadata urzadzen Z2M do `devices`.
 *
 * Pobiera retained wiadomosc `zigbee2mqtt/bridge/devices` (Z2M publikuje ja przy
 * restarcie i zmianie urzadzen) i synchronizuje do DB:
 *   - devices.model
 *   - devices.last_state (merge z Z2M options: motion_timeout, no_occupancy_since, itp.)
 *   - devices.fields (auto-dodaj writable pola, merge metadata access/z2m_type/values/...)
 *
 * Wolane co 15 minut przez akcje GUI "system_z2m_sync_devices".
 * Daemon tymos.py trzyma w pamieci mapy (name_to_ieee, device_exposes) i cache fields,
 * ale NIE zapisuje juz tej metadata do DB — robimy to tutaj.
 */

require_once __DIR__ . '/lib/_log.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

// === 1. Pobierz retained `zigbee2mqtt/bridge/devices` ===
$cmd = "mosquitto_sub -h localhost -t 'zigbee2mqtt/bridge/devices' -C 1 -W 5 2>/dev/null";
$raw = shell_exec($cmd);
if (!$raw) {
    tymos_log('WARN', 'z2m_sync: brak retained bridge/devices (Z2M offline?)');
    exit(1);
}

$devices = json_decode($raw, true);
if (!is_array($devices)) {
    tymos_log('ERROR', 'z2m_sync: niepoprawny JSON bridge/devices');
    file_put_contents('/tmp/tymos/z2m_bridge_devices_raw.json', $raw);
    exit(1);
}

// === 2. Helpery do ekstrakcji exposes ===

/**
 * Rekurencyjnie wylusk z Z2M `exposes` wszystkie pola z `property` i ich metadata.
 * Zwraca: [property => {unit, label, access, z2m_type, values, value_min, value_max, category}]
 */
function extract_exposes(array $exposes): array {
    $result = [];
    foreach ($exposes as $exp) {
        if (isset($exp['features']) && is_array($exp['features'])) {
            $result = array_merge($result, extract_exposes($exp['features']));
            continue;
        }
        if (!isset($exp['property'])) continue;
        $info = [];
        if (isset($exp['unit']))        $info['unit']       = $exp['unit'];
        if (isset($exp['label']))       $info['label']      = $exp['label'];
        if (isset($exp['access']))      $info['access']     = $exp['access'];
        if (isset($exp['type']))        $info['z2m_type']   = $exp['type'];
        if (isset($exp['values']))      $info['values']     = $exp['values'];
        if (isset($exp['value_min']))   $info['value_min']  = $exp['value_min'];
        if (isset($exp['value_max']))   $info['value_max']  = $exp['value_max'];
        if (isset($exp['category']))    $info['category']   = $exp['category'];
        $result[$exp['property']] = $info;
    }
    return $result;
}

/**
 * Typ SQL kolumny dla pola z exposes — ta sama regula co build_field_info() w tymos.py.
 * Bez tego writable `state` (ON/OFF) dostawal BIGINT i kazdy INSERT konczyl sie bledem 1366
 * (dev1184 Sypialnia Swiatlo, 2026-10-02).
 */
function z2m_sql_type(array $exp): string {
    $t = $exp['z2m_type'] ?? '';
    if ($t === 'enum' || $t === 'binary' || isset($exp['values'])) return 'VARCHAR(64)';
    if ($t === 'numeric') {
        $unit = strtolower($exp['unit'] ?? '');
        if ($unit !== '' && !in_array($unit, ['lqi', 'seconds', 's'])) return 'DOUBLE';
    }
    return 'BIGINT';
}

// === 3. Iteracja po urzadzeniach Z2M i sync do DB ===

$inserted = 0;
$renamed = 0;
$updated_model = 0;
$updated_state = 0;
$updated_fields = 0;

foreach ($devices as $dev) {
    $ieee = $dev['ieee_address'] ?? null;
    $name = $dev['friendly_name'] ?? null;
    if (!$ieee || !$name || $name === 'Coordinator') continue;

    $stmt = $db->prepare("SELECT id, device, model, fields, last_state FROM devices WHERE ieee=?");
    $stmt->bind_param('s', $ieee);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Urzadzenie, ktore NIC nie publikuje (router TI nie ma zadnego expose), nigdy nie trafi
    // do bazy przez push MQTT w tymos.py — rejestrujemy je tutaj, z retained `bridge/devices`.
    // Dzieki temu widac je w adminie, a `ai_zigbee_monitor` zglasza je jako nowe na Telegram.
    // Soft-skasowane maja swoj wiersz (ieee jest UNIQUE), wiec SELECT wyzej je znajduje —
    // ten INSERT ich nie wskrzesi. Exposes dociagnie nastepny przebieg (za 15 min).
    if (!$row) {
        $type = $dev['type'] ?? '';
        $mdl  = $definition_model = $dev['definition']['model'] ?? ($type !== '' ? $type : '');
        $stmt = $db->prepare("INSERT INTO devices (device, ieee, model, fields) VALUES (?, ?, ?, '{}')");
        $stmt->bind_param('sss', $name, $ieee, $mdl);
        $stmt->execute();
        $stmt->close();
        $inserted++;   // o nowym urzadzeniu powiadamia `ai_zigbee_monitor` na Telegram,
        continue;      // wiec tutaj nie logujemy (ten skrypt pisze tylko ERROR/WARN)
    }

    // --- Nazwa ---
    // Rename zrobiony w Z2M nie ma jak dojsc do urzadzenia, ktore nic nie publikuje: `tymos.py`
    // poprawia `devices.device` dopiero przy pushu. Bierzemy nazwe stad. Z2M jest zrodlem prawdy,
    // bo rename z admina TymOS tez leci do Z2M (api/rename_device.inc.php).
    if ($row['device'] !== $name) {
        $stmt = $db->prepare("UPDATE devices SET device=? WHERE id=?");
        $stmt->bind_param('si', $name, $row['id']);
        $stmt->execute();
        $stmt->close();
        $renamed++;
    }

    $definition = $dev['definition'] ?? [];
    $exposes    = $definition['exposes'] ?? [];
    $exp_map    = is_array($exposes) ? extract_exposes($exposes) : [];
    $model      = $definition['model'] ?? '';
    $dev_opts   = $dev['options'] ?? [];

    // --- Model ---
    if ($model && $row['model'] !== $model) {
        $stmt = $db->prepare("UPDATE devices SET model=? WHERE id=?");
        $stmt->bind_param('si', $model, $row['id']);
        $stmt->execute();
        $stmt->close();
        $updated_model++;
    }

    // --- last_state merge z Z2M options (motion_timeout, no_occupancy_since, itp.) ---
    if (!empty($dev_opts)) {
        $last_state = json_decode($row['last_state'] ?? '{}', true) ?: [];
        $changed = false;
        foreach ($dev_opts as $ok => $ov) {
            // Z2M czasem zwraca [10] zamiast 10 — tymos trzyma single value
            if (is_array($ov) && count($ov) === 1) $ov = $ov[0];
            if (($last_state[$ok] ?? null) !== $ov) {
                $last_state[$ok] = $ov;
                $changed = true;
            }
        }
        if ($changed) {
            $ls_json = json_encode($last_state, JSON_UNESCAPED_UNICODE);
            $stmt = $db->prepare("UPDATE devices SET last_state=? WHERE id=?");
            $stmt->bind_param('si', $ls_json, $row['id']);
            $stmt->execute();
            $stmt->close();
            $updated_state++;
        }
    }

    // --- fields merge metadata + auto-dodaj writable + no_occupancy_since ---
    $fields = json_decode($row['fields'] ?? '{}', true) ?: [];
    $fields_changed = false;

    // 3a. Merge metadata z exposes do istniejacych fields (tylko dict, nie string)
    foreach ($fields as $fname => $finfo) {
        if (!is_array($finfo)) continue;
        $exp = $exp_map[$fname] ?? [];
        foreach (['access', 'z2m_type', 'values', 'value_min', 'value_max', 'category'] as $mk) {
            if (isset($exp[$mk]) && ($finfo[$mk] ?? null) !== $exp[$mk]) {
                $fields[$fname][$mk] = $exp[$mk];
                $fields_changed = true;
            }
        }
    }

    // 3b. Dodaj writable pola z exposes (access bit 2 = writable) ktorych brak w fields
    foreach ($exp_map as $fname => $exp) {
        if (isset($fields[$fname])) continue;
        if (!isset($exp['access']) || !($exp['access'] & 2)) continue;
        $info = ['sql' => z2m_sql_type($exp)];
        foreach (['unit', 'label', 'access', 'z2m_type', 'values', 'value_min', 'value_max', 'category'] as $mk) {
            if (isset($exp[$mk])) $info[$mk] = $exp[$mk];
        }
        $fields[$fname] = $info;
        $fields_changed = true;
    }

    // 3c. Auto-dodaj no_occupancy_since dla urzadzen z occupancy (czujki ruchu Sonoff)
    if (isset($fields['occupancy']) && !isset($fields['no_occupancy_since'])) {
        $fields['no_occupancy_since'] = [
            'sql' => 'BIGINT', 'label' => 'No occupancy since',
            'access' => 7, 'z2m_type' => 'numeric',
            'value_min' => 0, 'value_max' => 3600, 'unit' => 's',
        ];
        $fields_changed = true;
    }

    if ($fields_changed) {
        $fields_json = json_encode($fields, JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare("UPDATE devices SET fields=? WHERE id=?");
        $stmt->bind_param('si', $fields_json, $row['id']);
        $stmt->execute();
        $stmt->close();
        $updated_fields++;
    }
}

// Nowy wiersz albo zmiana nazwy = demon musi odswiezyc swoj cache urzadzen.
if ($inserted || $renamed) {
    $db->query("UPDATE settings SET v=UNIX_TIMESTAMP() WHERE k='tymos_reload'");
}

// INFO o sync stats nie logowane — admin/Logi tylko dla ERROR/WARN.
