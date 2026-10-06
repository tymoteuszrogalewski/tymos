<?php
// TymOS admin → Logi (widok + filtr + clear)
// Renderowany przez cnt.php?panel=admin&tab=logs&card=main
// Filtr level przekazywany jako query param (?level=ERROR|WARN|INFO|'')

require_once __DIR__ . '/../../config.inc.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
$db->set_charset('utf8mb4');

$limit = 100;
$levelFilter = $_GET['level'] ?? '';
$where = '';
if ($levelFilter && in_array($levelFilter, ['INFO','WARN','ERROR'], true)) {
    $where = "WHERE level = '" . $db->real_escape_string($levelFilter) . "'";
}
$rows = $db->query("SELECT ts, level, source, message FROM log {$where} ORDER BY id DESC LIMIT {$limit}");
?>
<div class="admin-scope">
  <div class="row-flex" style="margin-bottom:12px;gap:4px">
<?php
$levels = ['' => 'All', 'INFO' => '🟢', 'WARN' => '🟡', 'ERROR' => '🔴'];
foreach ($levels as $lk => $lv):
    $isActive = ($levelFilter === $lk);
    $bgStyle = $isActive ? 'background:var(--accent-bg);color:var(--accent)' : 'background:rgba(255,255,255,0.04);color:var(--text)';
?>
    <button class="btn btn-sm" style="<?= $bgStyle ?>" data-admin-nav="logs" data-level="<?= $lk ?>"><?= $lv ?></button>
<?php endforeach; ?>
    <div style="flex:1"></div>
    <span class="link-accent" style="margin-right:12px" data-admin-nav="logs" data-level="<?= htmlspecialchars($levelFilter) ?>">↻ Odśwież</span>
    <button class="btn btn-sm btn-danger" onclick="tymosConfirmDelete(this,function(){$.post('api.php',{action:'clear_logs'},function(r){if(r.ok)loadCard('admin','logs','main',0,null,null,{level:'<?= htmlspecialchars($levelFilter) ?>'})},'json')},'Potwierdź?')">🗑</button>
  </div>
<?php if ($rows && $rows->num_rows > 0): ?>
  <table>
    <tr><th>Czas</th><th>Wiadomość</th></tr>
<?php while ($r = $rows->fetch_assoc()):
    $levelColor = 'var(--text-dim)';
    if ($r['level'] === 'ERROR') $levelColor = 'var(--red)';
    elseif ($r['level'] === 'WARN') $levelColor = '#f9a825';
    elseif ($r['level'] === 'INFO') $levelColor = '#4caf50';
?>
    <tr>
      <td style="font-size:11px;white-space:nowrap;vertical-align:top;width:140px"><?= htmlspecialchars($r['ts']) ?><br><span style="color:<?= $levelColor ?>;font-weight:500"><?= htmlspecialchars($r['level']) ?></span></td>
      <td style="font-size:12px;word-break:break-word"><span style="color:var(--text-dim)"><?= htmlspecialchars($r['source'] ?? '') ?></span><br><?= htmlspecialchars($r['message']) ?></td>
    </tr>
<?php endwhile; ?>
  </table>
<?php else: ?>
  <div style="color:var(--text-dim);font-size:13px;padding:20px;text-align:center">Brak logów.</div>
<?php endif; ?>
</div>
