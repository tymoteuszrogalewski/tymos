<?php // POST — szybka zmiana tylko wartosci helpera (bez reloadu daemonow)
$id = (int)($_POST['id'] ?? 0);
$value = $db->real_escape_string($_POST['value'] ?? '');
$db->query("UPDATE helpers SET value='{$value}' WHERE id={$id}");
echo json_encode(['ok' => true]);
