<?php // POST — zapisz etykiety kanalow (dla dual/quad gang gniazdek)
$id = (int)($_POST['dev_id'] ?? 0);
$labels = $_POST['labels'] ?? '{}';
$db->query("UPDATE devices SET channel_labels='" . $db->real_escape_string($labels) . "' WHERE id={$id}");
echo json_encode(['ok' => true]);
