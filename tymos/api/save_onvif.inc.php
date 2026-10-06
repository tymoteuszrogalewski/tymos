<?php // POST — zapisz dane ONVIF kamery (ip, port, user, pass)
$id = (int)($_POST['dev_id'] ?? 0);
$ip = trim($_POST['ip'] ?? '');
$port = (int)($_POST['onvif_port'] ?? 0);
$user = trim($_POST['onvif_user'] ?? '');
$pass = trim($_POST['onvif_pass'] ?? '');
$stmt = $db->prepare("UPDATE devices SET ip=?, onvif_port=?, onvif_user=?, onvif_pass=? WHERE id=?");
$nullPort = $port ?: null;
$nullUser = $user ?: null;
$nullPass = $pass ?: null;
$nullIp = $ip ?: null;
$stmt->bind_param('sissi', $nullIp, $nullPort, $nullUser, $nullPass, $id);
$stmt->execute();
$stmt->close();
echo json_encode(['ok' => true]);
