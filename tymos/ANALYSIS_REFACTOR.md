# TymOS GUI — analiza do refaktoru

## Skala problemu

- **366 wystąpień `style=`** w 26 plikach
- **158 plików** PHP/JS/CSS w `/tymos`
- **2 pliki CSS** (271 linii razem) vs **~1500 linii CSS inline** w PHP

## Diagnoza — 5 głównych problemów

### 1. Podwojone style scope: `.admin-scope` vs reszta

Wszystkie eleganckie komponenty (btn, toggle, form-group, detail-card, move-btn, rename-btn, search-wrap, ss-wrap, section-label, field-row, action-section) są **scoped do `.admin-scope`** — czyli działają tylko w panelu admin.

Reszta paneli (home, heat, podlewanie, raporty, cameras, battery) **nie ma tego wrappera**, więc każda karta wymyśla style na nowo:
- `.rol-btn` (roleta) = `.fanh-btn` (fan) = `.irr-mode-btn` (podlewanie) — ten sam wzorzec pill-button
- `.toggle` zdefiniowany w `style_admin.inc.css` + drugi raz w `card_strefy.inc.php`
- `.irr-set-btn` (przyciski +/−) analogiczne do `.therm-btn` (termostat)

**Skutek:** nowy formularz poza adminem = wymyślasz style od zera.

### 2. Inline style dla standardowych "cegiełek"

Top-10 powtórek:

| Wzorzec | Liczba wystąpień | Sugerowana klasa |
|---|---|---|
| `font-size:13px;color:var(--text-dim);margin-bottom:10px` (nagłówek sekcji w karcie) | 7+ | `.detail-section-label` |
| `background:rgba(255,255,255,0.04);border-radius:10px;padding:14px;margin-bottom:8px` (sub-card w builderze) | 4+ | `.sub-card` |
| `display:flex;gap:8px;align-items:center` (toolbar row) | 20+ | `.row-flex` |
| `display:flex;gap:4px;justify-content:flex-end;margin-bottom:8px` (toolbar ▲▼✕) | 3 | `.item-toolbar` |
| `background:var(--green);color:#fff` (button ON) | 3 | `.btn-on` / `.btn-green` |
| `background:var(--red);color:#fff` (button OFF/destruktywny) | 3 | już jest `.btn-danger` — używaj |
| `background:rgba(255,255,255,0.06);border:none;color:var(--text);border-radius:10px;padding:12px 14px;font-size:16px` (input) | 5+ | `.input-std` lub `.form-group input` (jest w admin-scope) |
| `style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;color:...;background:..."` (LED chip ON/OFF) | 4 | `.led-chip.on` / `.led-chip.off` |
| `style="display:inline-block;padding:4px 14px;border-radius:6px;font-size:14px;font-weight:600;color:...;background:...;border:1px solid ..."` (duży state pill) | 3 | `.state-pill.on/off/cover-open/cover-close` |
| `style="font-size:15px;font-weight:600;margin:24px 0 12px"` (nagłówek sekcji system) | 4 | `.section-h` |

### 3. Powtórki pill-button'ów (3 razy ten sam wzorzec)

```
.rol-btn    — card_roleta.inc.php
.fanh-btn   — card_reku_fan.inc.php
.irr-mode-btn — card_strefy.inc.php
```

Wszystkie robią to samo: flex button z hover/active, neutralne tło, niebieskie po kliknięciu.

**Do wspólnej klasy**: `.pill-btn` + `.pill-btn.active`.

### 4. `<style>` blok per karta

Wzorzec: każda PHP-karta ma własny `<style>...</style>` z 3-5 klasami scoped do siebie. Skutek: przy 40 kartach mamy 40 małych stylów wbudowanych w HTML, wysyłanych za każdym razem.

**Lepiej**: przenieść do `style.inc.css` (sekcje per komponent), skrypt JS `execScripts()` i tak zachowuje scoping przez prefiks klasy.

### 5. Powtarzające się wzorce JS

Te same rzeczy są w 3+ miejscach:

| Wzorzec | Gdzie | Powinien być |
|---|---|---|
| `setInterval(function(){ $.getJSON('api.php?action=X', ...); }, 5000)` | card_roleta, card_reku_fan, card_fanh-ctrl, inne | Wspólny `tymosPoll(cardId, url, onData, interval)` |
| `$.post('api.php',{action:'X',...},function(r){if(r.ok)loadCard(...)},'json')` — inline w onclick | card_logs, card_helpers, card_devices, card_actions | Wspólny `tymosAction(name, data, onSuccess)` |
| Podwójne RAF dla slide/fade transition | cards.inc.js | Już scentralizowane — OK |
| `tymosConfirmDelete` | panels.inc.js | ✅ już zrobione |
| Ręczne pill-button toggle (active class + POST) | card_roleta, card_reku_fan, card_reku_bypass | Możliwy `tymosPillGroup($el, {api, onChange})` |

## Proponowany plan refaktoru (etapami, bezpiecznie)

### Etap A — rozszerzenie `style.inc.css` o wspólne klasy (bez ruszania kart)

Dodać do `style.inc.css`:

```css
/* ---- UTILITIES ---- */
.row-flex      { display:flex; gap:8px; align-items:center; }
.row-flex.wrap { flex-wrap:wrap; }
.row-between   { display:flex; justify-content:space-between; align-items:center; gap:8px; }
.row-end       { display:flex; justify-content:flex-end; gap:4px; }
.stack-8       { display:flex; flex-direction:column; gap:8px; }

/* ---- TYPOGRAPHY ---- */
.section-h     { font-size:15px; font-weight:600; margin:24px 0 12px; }
.section-sh    { font-size:13px; color:var(--text-dim); margin-bottom:10px; }
.hint          { font-size:11px; color:var(--text-dim); }
.value-accent  { color:var(--accent); font-size:13px; }

/* ---- BUTTONS (dostępne poza .admin-scope) ---- */
.btn           { padding:10px 20px; border:none; border-radius:8px; font-size:14px; cursor:pointer; transition:background 0.15s; }
.btn-sm        { padding:8px 16px; font-size:13px; }
.btn-accent    { background:var(--accent); color:#fff; }
.btn-danger    { background:var(--red); color:#fff; }
.btn-green     { background:var(--green); color:#fff; }
.btn-ghost     { background:rgba(255,255,255,0.08); color:var(--text); }
.btn + .btn    { margin-left:8px; }

/* ---- PILL BUTTON (tap-friendly) ---- */
.pill-btn      { flex:1; padding:10px 4px; border:none; border-radius:10px; cursor:pointer;
                 background:#2a2a2a; color:#9a9a9a; display:flex; align-items:center;
                 justify-content:center; gap:4px; transition:background 0.15s,color 0.15s;
                 -webkit-tap-highlight-color:transparent; touch-action:manipulation; user-select:none; }
.pill-btn:active { opacity:0.75; }
.pill-btn.active { background:var(--accent); color:#fff; }

/* ---- STATE PILL / LED CHIP ---- */
.led-chip      { display:inline-block; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600; }
.led-chip.on   { color:var(--green); background:rgba(76,175,80,0.15); border:1px solid var(--green); }
.led-chip.off  { color:var(--red);   background:rgba(244,67,54,0.15); border:1px solid var(--red); }

.state-pill    { display:inline-block; padding:4px 14px; border-radius:6px; font-size:14px; font-weight:600; border:1px solid; }
.state-pill.on      { color:var(--green); background:rgba(76,175,80,0.15); border-color:var(--green); }
.state-pill.off     { color:var(--red);   background:rgba(244,67,54,0.15); border-color:var(--red); }
.state-pill.neutral { color:var(--text-dim); background:rgba(128,128,128,0.15); border-color:var(--text-dim); }

/* ---- TOGGLE — wyjmij z .admin-scope do globalnego ---- */
.toggle { position:relative; display:inline-block; width:36px; min-width:36px; height:20px; cursor:pointer; vertical-align:middle; }
.toggle input { display:none; }
.toggle span  { position:absolute; inset:0; background:rgba(255,255,255,0.1); border-radius:10px; transition:background 0.2s; }
.toggle span::after { content:''; position:absolute; width:16px; height:16px; border-radius:50%; background:#fff; top:2px; left:2px; transition:transform 0.2s; }
.toggle input:checked + span { background:var(--accent); }
.toggle input:checked + span::after { transform:translateX(16px); }

/* ---- FORM ELEMENTS (globalne, nie tylko admin) ---- */
.input-std, .select-std {
  background:rgba(255,255,255,0.06); border:none; color:var(--text);
  border-radius:10px; padding:12px 14px; font-size:16px; line-height:1.4;
  outline:none; box-sizing:border-box;
}
.input-std:focus, .select-std:focus { outline:1px solid rgba(255,255,255,0.15); }

.form-group  { margin-bottom:10px; }
.form-group > label { display:block; font-size:11px; color:var(--text-dim); margin-bottom:3px; letter-spacing:0.03em; }
.form-row    { display:flex; gap:10px; align-items:flex-start; }

/* ---- DETAIL CARD (globalne) ---- */
.detail-card { background:var(--card-bg); border-radius:var(--radius); padding:16px; margin-bottom:12px; border:1px solid var(--border); }
.sub-card    { background:rgba(255,255,255,0.04); border-radius:10px; padding:14px; margin-bottom:8px; }

/* ---- MOVE TOOLBAR (▲ ▼ ✕) ---- */
.move-btn       { cursor:pointer; background:rgba(255,255,255,0.06); color:var(--text-dim); border:none; border-radius:6px; width:26px; height:26px; display:inline-flex; align-items:center; justify-content:center; font-size:12px; user-select:none; }
.move-btn:active{ opacity:0.6; }
.move-btn.danger{ background:rgba(244,67,54,0.15); color:var(--red); }
.item-toolbar   { display:flex; gap:4px; align-items:center; }
```

### Etap B — wyjście `admin-scope`-only klas na poziom globalny

Zduplikowane w `.admin-scope`: `.btn`, `.toggle`, `.detail-card`, `.form-group`, `.move-btn`, `.section-label`.
Jeśli zostanie zdefiniowane globalnie (Etap A), admin-scope wersje można usunąć — wszędzie działa spójnie.

### Etap C — migracja kart (stopniowa)

Każda karta jedna po drugiej:
1. Usunąć lokalny `<style>` jeśli pokrywa się z globalnym.
2. Zamienić inline `style="..."` na klasy (`row-flex`, `section-sh`, `pill-btn`, itd.).
3. Usunąć duplikaty definicji `.toggle`, `.rol-btn`, `.fanh-btn` itp.

Kolejność (od prostych do złożonych):
- podlewanie/card_strefy, card_ustawienia
- home/card_roleta, card_reku_fan
- admin/tab_logs, tab_helpers
- admin/tab_devices (duże — na końcu)
- admin/tab_actions builder JS (największe — dopiero po C)

### Etap D — JS helpery

W `panels.inc.js` (lub nowy `helpers.inc.js`):

```js
// tymosPoll — zastępuje ręczne setInterval z getJSON
function tymosPoll(cardId, url, onData, interval) {
  interval = interval || 5000;
  var gen = Date.now();
  var $c = $('#card_' + cardId);
  if (!$c.length) return;
  $c.data('pollGen', gen);
  function tick() {
    var $cur = $('#card_' + cardId);
    if (!$cur.length || $cur.data('pollGen') !== gen) return;
    $.getJSON(url, function(d){ if (d && d.ok !== false) onData(d); });
  }
  tick();
  setInterval(tick, interval);
}

// tymosAction — wrapper na $.post + loadCard po sukcesie
function tymosAction(name, data, opts) {
  opts = opts || {};
  data = $.extend({action: name}, data);
  $.post('api.php', data, function(r) {
    if (r.ok === false) { if (opts.onError) opts.onError(r); return; }
    if (opts.onSuccess) opts.onSuccess(r);
    if (opts.reload) loadCard.apply(null, opts.reload);
  }, 'json');
}
```

## Co dostajemy

- Nowy formularz = klasy `row-flex`, `section-sh`, `input-std`, `detail-card` → wygląd spójny od razu
- Zmiana palety (np. accent z niebieskiego na fioletowy) = jedna linia w `:root`
- Mniej HTML w DOM (inline style to bajty wysyłane przy każdym renderze karty)
- Łatwiejsze czytanie PHP — tylko logika, nie styl

## Rekomendacja wdrożenia

**Bezpiecznie**: Etap A → test na jednej karcie → jak działa, to Etap B → migracja kart po jednej (C) → JS helpery (D) na końcu.

Nie robić wszystkiego na raz — każdy etap to osobne wdrożenie i test.
