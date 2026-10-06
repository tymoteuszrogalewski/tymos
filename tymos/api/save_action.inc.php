<?php // POST — utworz/zaktualizuj akcje. Waliduje unikalnosc nazwy + reload daemona.
require_once __DIR__ . '/../inc/helpers.inc.php';

$id = (int)($_POST['id'] ?? 0);
$name = $_POST['name'] ?? '';
$trigger_type = $_POST['trigger_type'] ?? '';
$triggers_json = $_POST['triggers_json'] ?? '[]';
$conditions = $_POST['conditions'] ?? '';
if ($conditions === '') $conditions = null;
$actions_json = $_POST['actions_json'] ?? '[]';
$enabled = (int)($_POST['enabled'] ?? 1);
$min_interval = $_POST['min_interval'] ?? '';
$min_interval = $min_interval !== '' ? (int)$min_interval : null;

$triggers = json_decode($triggers_json, true) ?: [];

// Determine trigger_type. 'cron' wygrywa — daemon ma osobny scheduler ktory
// uruchamia akcje cron co minute (sprawdza expression). Pozostale typy (state/event/sun)
// daemon odpala dopiero przy device push pasujacym do trigger conditions.
// Bug-fix 2026-04-25: poprzednia logika "$triggers[0]['type'] ?? 'event'" cichaczem
// zmieniala trigger_type na 'event' gdy pierwszy trigger nie byl 'cron' — co
// wylaczalo akcje agregujace na ~5 dni.
$trigger_type = 'event';
$cron_count = 0;
foreach ($triggers as $tr) {
    if (($tr['type'] ?? '') === 'cron') { $cron_count++; }
}
if ($cron_count > 0) {
    $trigger_type = 'cron';
} elseif (!empty($triggers[0]['type'])) {
    $trigger_type = $triggers[0]['type'];
}

// Walidacja: cron triggers MUSZA miec wyrazenie cron
foreach ($triggers as $i => $tr) {
    if (($tr['type'] ?? '') === 'cron' && empty($tr['cron'])) {
        echo json_encode(['error' => "Trigger #" . ($i+1) . ": typ 'cron' wymaga wyrazenia cron"]);
        exit;
    }
}

$nameCheck = $db->prepare("SELECT id FROM actions WHERE name=? AND id!=?");
$nameCheck->bind_param('si', $name, $id);
$nameCheck->execute();
if ($nameCheck->get_result()->num_rows > 0) {
    echo json_encode(['error' => 'Akcja o nazwie "' . $name . '" juz istnieje']);
    exit;
}

if ($id > 0) {
    $stmt = $db->prepare("UPDATE actions SET name=?, enabled=?, trigger_type=?, trigger_config=?, conditions=?, actions=?, min_interval=? WHERE id=?");
    $stmt->bind_param('sissssii', $name, $enabled, $trigger_type, $triggers_json, $conditions, $actions_json, $min_interval, $id);
    $stmt->execute();
} else {
    $stmt = $db->prepare("INSERT INTO actions (name, enabled, trigger_type, trigger_config, conditions, actions, min_interval) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('sissssi', $name, $enabled, $trigger_type, $triggers_json, $conditions, $actions_json, $min_interval);
    $stmt->execute();
    $id = $db->insert_id;
}
tymos_notify_reload($db);
echo json_encode(['reload' => true, 'action_id' => $id]);
