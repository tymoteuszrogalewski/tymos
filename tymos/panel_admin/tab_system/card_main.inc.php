<?php
// TymOS admin → System (daemony, Z2M permit join, debug payload, SSL, moduly)

require_once __DIR__ . '/../../config.inc.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
$db->set_charset('utf8mb4');

$daemons = [
    ['key' => 'tymos',  'service' => 'tymos-mqtt-listener',  'name' => 'TymOS Listener',  'desc' => 'MQTT listener, akcje, zapis danych'],
    ['key' => 'onvif',  'service' => 'tymos-onvif-bridge',   'name' => 'ONVIF Bridge',    'desc' => 'Kamery ONVIF (eventy, detekcja)'],
    ['key' => 'blebox', 'service' => 'tymos-blebox-bridge',  'name' => 'BleBox Bridge',   'desc' => 'BleBox Pstryk'],
    ['key' => 'esphome','service' => 'tymos-esphome-bridge', 'name' => 'ESPHome Bridge',  'desc' => 'ESP32 (rekuperator, itp.)'],
];

$dbgRow = $db->query("SELECT v FROM settings WHERE k='debug_payload' LIMIT 1");
$dbgVal = ($dbgRow && $dbgRow->num_rows > 0) ? $dbgRow->fetch_assoc()['v'] : '0';
$dbgOn = ($dbgVal === '1');

$modules = [
    ['name' => 'Zigbee2MQTT',    'url' => 'http://' . TYMOS_IP . ':8080',  'desc' => 'Zarządzanie urządzeniami Zigbee'],
    ['name' => 'SLZB-06 Zigbee', 'url' => 'http://' . DONGLE_IP,           'desc' => 'Koordynator Zigbee SMLIGHT (web UI, login DONGLE_USER)'],
    ['name' => 'go2rtc',         'url' => 'http://' . TYMOS_IP . ':1984',  'desc' => 'Streaming kamer'],
    ['name' => 'ESPHome',        'url' => 'http://' . TYMOS_IP . ':6052',  'desc' => 'Zarządzanie ESP'],
    ['name' => 'phpMyAdmin',     'url' => '/phpmyadmin/',                   'desc' => 'Zarządzanie bazą danych'],
];
?>
<div class="admin-scope">
  <div class="section-h" style="margin:0 0 12px">Daemony</div>
  <table>
    <tr><th>Daemon</th><th style="text-align:center">Status</th><th style="text-align:right"></th></tr>
<?php foreach ($daemons as $d):
    $svc = $d['service'];
    $stOut = [];
    exec("systemctl is-active {$svc} 2>/dev/null", $stOut, $stRc);
    $alive = ($stRc === 0);
    $status = $alive ? 'active' : 'dead';
    $statusColor = $alive ? 'var(--green)' : 'var(--red)';
    $uptime = '';
    if ($alive) {
        $propOut = [];
        exec("systemctl show {$svc} --property=ActiveEnterTimestamp 2>/dev/null", $propOut);
        $ts = str_replace('ActiveEnterTimestamp=', '', $propOut[0] ?? '');
        if ($ts) {
            $secs = time() - strtotime($ts);
            if ($secs < 60) $uptime = $secs . 's';
            elseif ($secs < 3600) $uptime = floor($secs / 60) . 'm';
            elseif ($secs < 86400) $uptime = floor($secs / 3600) . 'h ' . floor(($secs % 3600) / 60) . 'm';
            else $uptime = floor($secs / 86400) . 'd ' . floor(($secs % 86400) / 3600) . 'h';
        }
    }
?>
    <tr>
      <td><?= htmlspecialchars($d['name']) ?><br><span class="hint"><?= htmlspecialchars($d['desc']) ?></span></td>
      <td style="text-align:center;color:<?= $statusColor ?>"><?= $status ?><?= $uptime ? '<br><span style="font-size:11px;color:var(--text-dim)">' . $uptime . '</span>' : '' ?></td>
      <td style="text-align:right"><button class="btn btn-sm btn-danger" onclick="tymosRestartDaemon('<?= $d['key'] ?>',this)">Restart</button></td>
    </tr>
<?php endforeach; ?>
  </table>

  <div class="section-h">Debug</div>
  <div class="row-flex" style="gap:12px">
    <span style="font-size:13px">Debug payload (zapis RAW JSON do debug_payload)</span>
    <button class="btn btn-sm <?= $dbgOn ? 'btn-green' : 'btn-danger' ?>" id="debug-payload-btn" style="min-width:60px" onclick="tymosToggleDebugPayload(this)"><?= $dbgOn ? 'ON' : 'OFF' ?></button>
  </div>
  <div class="hint" style="margin-top:4px">Listener sprawdza co ~10s. Payloady widoczne w szczegolach urzadzenia.</div>

  <div class="section-h">Certyfikat SSL (iPad/iPhone)</div>
  <div style="font-size:13px;color:var(--text-dim);line-height:1.6">
    1. Otworz w Safari: <a href="http://<?= TYMOS_IP_LAN ?>/ssl/tymos.crt" style="color:var(--accent)">http://<?= TYMOS_IP_LAN ?>/ssl/tymos.crt</a><br>
    2. Settings &rarr; General &rarr; VPN &amp; Device Management &rarr; zainstaluj certyfikat<br>
    3. Settings &rarr; General &rarr; About &rarr; Certificate Trust Settings &rarr; wlacz toggle przy tymos.local
  </div>
  <div class="section-h" style="margin:16px 0 8px">macOS (Safari/Chrome)</div>
  <div style="font-size:13px;color:var(--text-dim);line-height:1.6">
    <code class="code-block" style="margin-bottom:4px">curl -sk https://<?= TYMOS_IP_LAN ?>/ssl/tymos.crt -o /tmp/tymos.crt</code>
    <code class="code-block">sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain /tmp/tymos.crt</code>
  </div>

  <div class="section-h">Moduły</div>
  <table>
    <tr><th>Moduł</th><th style="text-align:right"></th></tr>
<?php foreach ($modules as $m): ?>
    <tr>
      <td><?= htmlspecialchars($m['name']) ?><br><span class="hint"><?= htmlspecialchars($m['desc']) ?></span></td>
      <td style="text-align:right"><a href="<?= htmlspecialchars($m['url']) ?>" target="_blank" class="btn btn-accent btn-sm">Otwórz</a></td>
    </tr>
<?php endforeach; ?>
  </table>
</div>

<script>
function tymosRestartDaemon(key, btn) {
  btn.disabled = true;
  btn.textContent = "...";
  $.post("api.php", {action:"restart_daemon", daemon:key}, function(r){
    btn.textContent = r.ok ? "OK" : "Błąd";
    // Przez cardTimers, nie golym setTimeout: loadPanel() kasuje wszystkie cardTimers przy
    // zmianie panelu, a loadCard() kasuje wpis tej karty na wejsciu. Goly setTimeout odpalal sie
    // po wyjsciu z admina i szedl w prozne (loadCard wyrzucal odpowiedz, bo karty juz nie bylo).
    cardTimers["main"] = setTimeout(function(){ loadCard("admin","system","main"); }, 5000);
  }, "json").fail(function(){ btn.textContent = "Błąd"; btn.disabled = false; });
}
function tymosToggleDebugPayload(btn) {
  btn.disabled = true;
  btn.textContent = "...";
  $.post("api.php", {action:"toggle_debug_payload"}, function(r){
    if (r.ok) {
      btn.textContent = r.value;
      btn.style.background = r.value === "ON" ? "var(--green)" : "var(--red)";
    }
    btn.disabled = false;
  }, "json").fail(function(){ btn.textContent = "Błąd"; btn.disabled = false; });
}
</script>
