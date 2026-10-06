<?php
require_once __DIR__ . '/../../config.inc.php';
require_once __DIR__ . '/../../inc/db.inc.php';
$db->select_db('tymos');
$db->query("CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) PRIMARY KEY, v VARCHAR(255)) ENGINE=InnoDB");
$sRes = $db->query("SELECT k,v FROM settings WHERE k IN ('pellet_waga','pellet_cena','pellet_cena_rynek','pellet_prog_grzalki')");
$sMap = [];
while ($sRow = $sRes->fetch_assoc()) $sMap[$sRow['k']] = $sRow['v'];
$init_waga  = isset($sMap['pellet_waga'])       ? (float)$sMap['pellet_waga']       : 15;
$init_cena  = isset($sMap['pellet_cena'])       ? (float)$sMap['pellet_cena']       : 1710;
$init_rynek = isset($sMap['pellet_cena_rynek']) ? (float)$sMap['pellet_cena_rynek'] : 2450;
// Prog ustala automat (actions/heating_cost.php) co 30 min wg najnizszej dobowej temperatury — tu tylko podglad.
$prog_grzalki = isset($sMap['pellet_prog_grzalki']) ? (float)$sMap['pellet_prog_grzalki'] : null;
?>
<style>
.pb-wrap{display:flex;flex-direction:column;gap:10px;margin-bottom:14px}
.pb-field{display:flex;align-items:center;gap:8px}
.pb-lbl{font-size:11px;color:var(--text-dim);width:78px;flex-shrink:0;letter-spacing:.5px}
.pb-btn{background:#2a2a2a;border:none;border-radius:6px;color:#7799cc;cursor:pointer;width:38px;height:38px;font-size:22px;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent;user-select:none;flex-shrink:0;touch-action:manipulation}
.pb-btn:active{background:#333;transform:scale(0.93)}
.pb-val{font-size:20px;color:#e1e1e1;min-width:64px;text-align:center}
.pb-unit{font-size:11px;color:#555}
.pb-sec{font-size:11px;color:#7799cc;letter-spacing:.5px;margin:18px 0 8px;padding-top:12px;border-top:1px solid #2a2a2a}
.pb-hint{font-size:11px;color:#666;line-height:1.5;margin-top:6px}
</style>

<div class="card-title">Pellet</div>

<div class="pb-sec" style="margin-top:0;padding-top:0;border-top:none;">ZAKUP · historia faktyczna</div>
<div class="pb-wrap">
  <div class="pb-field">
    <div class="pb-lbl">Worki</div>
    <button class="pb-btn" id="pb-bags-m">−</button>
    <div class="pb-val" id="pb-bags-v">0</div>
    <button class="pb-btn" id="pb-bags-p">+</button>
    <div class="pb-unit">szt</div>
  </div>
  <div class="pb-field">
    <div class="pb-lbl">kg/worek</div>
    <button class="pb-btn" id="pb-waga-m">−</button>
    <div class="pb-val" id="pb-waga-v"><?= $init_waga ?></div>
    <button class="pb-btn" id="pb-waga-p">+</button>
    <div class="pb-unit">kg</div>
  </div>
  <div class="pb-field">
    <div class="pb-lbl">cena zakupu</div>
    <button class="pb-btn" id="pb-cena-m">−</button>
    <div class="pb-val" id="pb-cena-v"><?= $init_cena ?></div>
    <button class="pb-btn" id="pb-cena-p">+</button>
    <div class="pb-unit">PLN/t</div>
  </div>
</div>
<div style="display:flex;align-items:center;gap:12px;">
  <div id="pb_summary" style="flex:1;font-size:13px;color:var(--text-dim);">0,0 kg · 0,00 PLN</div>
  <button id="pb_btn"
          style="background:var(--accent-bg);border:1px solid var(--accent);border-radius:6px;color:var(--accent);padding:7px 18px;font-size:13px;cursor:pointer;-webkit-tap-highlight-color:transparent;">
    DODAJ
  </button>
</div>
<div class="pb-hint">Ile realnie wydałeś na worki. Ta cena nie wpływa na żaden wykres szacunkowy.</div>

<div class="pb-sec">SZACUNKI · cena sklepowa dziś</div>
<div class="pb-wrap" style="margin-bottom:0;">
  <div class="pb-field">
    <div class="pb-lbl">cena rynek</div>
    <button class="pb-btn" id="pb-rynek-m">−</button>
    <div class="pb-val" id="pb-rynek-v"><?= $init_rynek ?></div>
    <button class="pb-btn" id="pb-rynek-p">+</button>
    <div class="pb-unit">PLN/t</div>
  </div>
</div>
<div class="pb-hint">
  Na tej cenie stoją <b>wszystkie wykresy szacunkowe</b> („czy wracać do pelletu").
  Świadomie nie zależy od zapasu — powrót ma sens na dłużej niż miesiąc, a worki się kończą.
</div>

<div class="pb-sec">PRÓG GRZAŁKI</div>
<div style="display:flex;align-items:center;gap:6px;color:var(--text-dim);font-size:12px;">
  <span style="font-size:15px;">🔌</span>
  <span>poniżej</span>
  <span style="color:#e1e1e1;font-size:14px;font-weight:bold;"><?= $prog_grzalki !== null ? number_format($prog_grzalki, 3, ',', '') : '—' ?></span>
  <span>PLN/kWh opłaca się grzałka</span>
</div>
<div class="pb-hint">Ustala automat co 30 min wg najniższej dobowej temperatury — pole tylko do odczytu.</div>

<script>
(function() {
    var btn = document.getElementById('pb_btn');
    if (!btn || btn.dataset.init) return;
    btn.dataset.init = '1';
    var bags = 0,
        waga  = <?= json_encode($init_waga) ?>,
        cena  = <?= json_encode($init_cena) ?>,
        rynek = <?= json_encode($init_rynek) ?>;

    function calc() {
        var kg   = bags * waga;
        var cost = kg / 1000 * cena;
        document.getElementById('pb_summary').textContent =
            kg.toFixed(1).replace('.',',') + ' kg · ' + cost.toFixed(2).replace('.',',') + ' PLN';
    }

    function mkBtn(minusId, plusId, getVal, setVal, step, min) {
        document.getElementById(minusId).addEventListener('click', function() {
            var v = getVal() - step;
            if (v < min) return;
            setVal(Math.round(v * 100) / 100);
            calc();
        });
        document.getElementById(plusId).addEventListener('click', function() {
            setVal(Math.round((getVal() + step) * 100) / 100);
            calc();
        });
    }

    mkBtn('pb-bags-m', 'pb-bags-p',
        function() { return bags; },
        function(v) { bags = v; document.getElementById('pb-bags-v').textContent = v; },
        1, 0);

    mkBtn('pb-waga-m', 'pb-waga-p',
        function() { return waga; },
        function(v) { waga = v; document.getElementById('pb-waga-v').textContent = v; $.post('api.php', {action:'pellet_settings_save', waga:v}); },
        1, 1);

    mkBtn('pb-cena-m', 'pb-cena-p',
        function() { return cena; },
        function(v) {
            cena = v;
            document.getElementById('pb-cena-v').textContent = v;
            $.post('api.php', {action:'pellet_settings_save', cena:v});
        },
        10, 10);

    // Cena rynkowa przelicza wykresy szacunkowe — po zapisie odswiez obie karty.
    mkBtn('pb-rynek-m', 'pb-rynek-p',
        function() { return rynek; },
        function(v) {
            rynek = v;
            document.getElementById('pb-rynek-v').textContent = v;
            $.post('api.php', {action:'pellet_settings_save', cena_rynek:v}, function() {
                loadCard(currentPanel, currentTab, 'heating_compare');
                loadCard(currentPanel, currentTab, 'energia_total');
            });
        },
        10, 10);

    calc();

    document.getElementById('pb_btn').addEventListener('click', function() {
        if (bags <= 0 || waga <= 0 || cena <= 0) return;
        var btn = document.getElementById('pb_btn');
        btn.disabled = true; btn.textContent = '…';
        $.post('api.php', {action:'pellet_save', bags:bags, waga_worka:waga, cena_tona:cena}, function(data) {
            if (data.ok) {
                setTimeout(function() {
                    loadCard(currentPanel, currentTab, 'pellet_add', 0);
                    loadCard(currentPanel, currentTab, 'pellet_month');
                    loadCard(currentPanel, currentTab, 'pellet_year');
                }, 1000);
            } else {
                btn.textContent = 'BŁĄD';
                setTimeout(function() { btn.disabled = false; btn.textContent = 'DODAJ'; }, 2000);
            }
        }, 'json').fail(function() {
            btn.textContent = 'BŁĄD';
            setTimeout(function() { btn.disabled = false; btn.textContent = 'DODAJ'; }, 2000);
        });
    });
})();
</script>
