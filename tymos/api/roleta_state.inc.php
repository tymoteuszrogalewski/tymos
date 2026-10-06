<?php // GET
// Stan rolety (id=21) — z devices.last_state (tabela device21 nie trzyma kolumn position/state/motor_run_status)
$res = $db->query("SELECT last_state FROM devices WHERE id=21");
$ls  = ($res && $row = $res->fetch_assoc()) ? (json_decode($row['last_state'] ?? '{}', true) ?: []) : [];
$pos   = isset($ls['position']) ? (int)$ls['position'] : -1;
$state = $ls['state'] ?? 'unknown';
$motor = $ls['motor_run_status'] ?? 'Stop';
if ($pos <= 0) $state = 'closed';
elseif ($pos >= 100) $state = 'open';
elseif ($motor === 'Forward') $state = 'opening';
elseif ($motor === 'Reverse') $state = 'closing';
else $state = 'closed';
$labels = ['open'=>'Odsłonięta','closed'=>'Zasłonięta','opening'=>'Odsłanianie…','closing'=>'Zasłanianie…'];
$label  = ($labels[$state] ?? $state) . ($pos >= 0 ? ' · ' . $pos . '%' : '');
echo json_encode(['ok' => true, 'state' => $state, 'pos' => $pos, 'label' => $label]);
