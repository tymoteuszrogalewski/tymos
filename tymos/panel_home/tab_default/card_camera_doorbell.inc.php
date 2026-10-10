<?php
require_once __DIR__ . '/../../inc/camera.inc.php';
// Kontrolka „aktywuj dzwiek" siedzi na koncu tego pliku (blok AKTYWACJA DZWIEKU) — czerwone
// pulsujace kolo na srodku TEGO kadru. Byla przez chwile na kamerze parkingowej, wrocila tutaj,
// bo blokada audio dotyczy dzwonka. Sam gest moze byc dowolny: kazde dotkniecie ekranu odblokowuje
// audio (index/doorbell.inc.js + mostek w ui.php) — kolo jest tylko po to, zeby bylo widac, ze trzeba.

// true = dostepny przycisk "odbierz rozmowe" (mikrofon + sluchanie).
// camera.inc.php udostepnia wtedy window['cam_doorbell_sd_mic'](true/false) ktore
// otwiera OSOBNE polaczenie audio (doorbell_talk) — glowny strumien video zostaje nietkniety.
camera_render('doorbell_sd', '', true);
?>
<script>
// PROBA 2026-08-23 — czarny pas dzwonka przejmuje kafelek "dom".
// 2026-08-29: ROLETA STAD ZDJETA — przeniesiona do karty wody, jako wiersz nad podlewaniem.
// Na kadrze zostaje sama temperatura i wilgotnosc.
// Kadr dzwonka to kolo 4:3 w kontenerze 16:9, wiec po bokach sa martwe czarne pasy. Dosuwamy obraz
// do LEWEJ (object-position), przez co po prawej powstaje jeden szeroki pas. Nakladka dostaje
// DOKLADNIE jego szerokosc (liczona z proporcji strumienia), zeby siedziala w nim wysrodkowana,
// a nie "gdzies przy krawedzi".
(function(){
    var ID = 'cam_doorbell_sd';
    var v = document.getElementById(ID);
    if (!v) return;
    var wrap = v.parentElement;
    v.style.objectPosition = 'left center';

    var old = document.getElementById('dbSideBox');
    if (old) old.remove();
    var oldTile = document.getElementById('dbHomeTile');
    if (oldTile) oldTile.remove();
    if (window.dbTileTimer) clearInterval(window.dbTileTimer);

    // JEDNA kolumna w czarnym pasie: 🏠 / temperatura / wilgotnosc / krople, od gory.
    // 2026-08-29: blok przestal wyjezdzac na kadr. Wczesniej wchodzil na obraz o staly `over`,
    // przez co domek i "C" lezaly na niebie, a pas obok stal do polowy pusty.
    var box = document.createElement('div');
    box.id = 'dbSideBox';
    box.style.cssText = 'position:absolute;right:0;z-index:12;display:flex;flex-direction:column;'
        + 'align-items:center;justify-content:center;background:#1a1a1a;';   // --bg: pstryk i kalendarz nie maja wlasnego tla, wiec widac przez nie
        // tlo strony. `none` tu nie zadziala, bo pod spodem jest czarny wrapper kamery.
    wrap.appendChild(box);

    function scale() { return Math.max(1, Math.min(wrap.offsetWidth / 440, 2.2)); }

    // Szerokosc czarnego pasa = szerokosc kontenera minus szerokosc obrazu przy object-fit:contain.
    // Przed pierwsza ramka videoWidth = 0, wiec fallback 4:3 (sub-stream Reolinka).
    function barWidth() {
        var W = wrap.offsetWidth, H = wrap.offsetHeight;
        var ar = (v.videoWidth && v.videoHeight) ? (v.videoWidth / v.videoHeight) : (4 / 3);
        return Math.max(0, W - Math.min(W, H * ar));
    }

    var tile = document.createElement('div');
    tile.style.cssText = 'display:flex;flex-direction:column;align-items:center;justify-content:center;'
        + 'width:100%;color:#fff;font-weight:700;white-space:nowrap;line-height:1.02;';
    box.appendChild(tile);

    // STEROWNIK ROLETY w dolnej czesci pasa: ikona okna, a pod nia dwie strzalki obok siebie.
    // Te same ksztalty i kolory co w karcie wody — jeden jezyk dla tej samej rzeczy.
    var rol = document.createElement('div');
    rol.id = 'dbRol';
    rol.style.cssText = 'margin-top:auto;display:flex;flex-direction:column;align-items:center;'
        + 'white-space:nowrap;-webkit-tap-highlight-color:transparent;';
    box.appendChild(rol);

    // OKNO POKAZUJE POLOZENIE: roleta w srodku jest opuszczona dokladnie na tyle, na ile jest
    // naprawde (position 100 = zwinieta u gory, 0 = calkiem zasloniete). Dzieki temu stan czyta
    // sie wprost z rysunku, a strzalki sa juz tylko przyciskami — nie musza niesc informacji.
    // Uklad 2x2 (2026-09-02): okno zajmuje CALY pierwszy wiersz (grid-column 1/-1), a strzalki
    // stoja pod nim jako dwa duze przyciski. Wczesniej wszystkie trzy elementy staly w jednym
    // rzedzie i strzalki wychodzily waskie — na iPadzie trudno bylo trafic palcem.
    // viewBox jest liczony z proporcji, zeby okno bylo szerokie, a nie rozciagniete kwadratem.
    function winIcon(wPx, hPx, pos) {
        var p = (pos == null) ? 100 : Math.max(0, Math.min(100, pos));
        var VH = 24, VW = Math.max(24, Math.round(VH * wPx / hPx));
        // Margines = POLOWA grubosci obrysu (stroke-width 1.5), nic wiecej. Dzieki temu zewnetrzna
        // krawedz ramki lezy dokladnie na krawedzi svg, wiec WIDOCZNA szerokosc okna rowna sie
        // szerokosci elementu — mozna ja zestawic z borderami strzalek pod spodem.
        var m = 0.75;
        var X = m, Y = m, W = VW - 2 * m, H = VH - 2 * m;
        var bh = Math.max(1.8, H * (100 - p) / 100);          // 1,8 = zwinieta roleta w skrzynce
        var slats = '';
        for (var y = Y + 2.6; y < Y + bh - 0.6; y += 2.6) {
            slats += '<line x1="' + X + '" y1="' + y.toFixed(1) + '" x2="' + (X + W) + '" y2="' + y.toFixed(1)
                   + '" stroke="rgba(0,0,0,.34)" stroke-width="0.9"/>';
        }
        // -webkit-touch-callout/user-select: DLUGIE PRZYTRZYMANIE okna otwiera okienko z algorytmem
        // (blok nizej), wiec iOS nie moze na tym gescie pokazywac swojego menu ani zaznaczac.
        return '<button data-rol="stop" title="Stop" style="grid-column:1/-1;background:none;border:none;'
             + 'padding:0;margin:0;cursor:pointer;display:flex;justify-content:center;'
             + '-webkit-touch-callout:none;user-select:none;-webkit-user-select:none;'
             + '-webkit-tap-highlight-color:transparent;touch-action:manipulation">'
             + '<svg width="' + wPx + '" height="' + hPx + '" viewBox="0 0 ' + VW + ' ' + VH + '" style="display:block">'
             + '<rect x="' + X + '" y="' + Y + '" width="' + W + '" height="' + H + '" rx="1.6" fill="#16232f"/>'
             + '<rect x="' + X + '" y="' + Y + '" width="' + W + '" height="' + bh.toFixed(1) + '" rx="1.2" fill="#4a9eff"/>'
             + slats
             + '<rect x="' + X + '" y="' + Y + '" width="' + W + '" height="' + H + '" rx="1.6" fill="none" stroke="#8a8a8a" stroke-width="1.5"/>'
             + '<line x1="' + (VW / 2) + '" y1="' + Y + '" x2="' + (VW / 2) + '" y2="' + (Y + H) + '" stroke="#8a8a8a" stroke-width="1" opacity=".55"/>'
             + '</svg></button>';
    }

    var ROL_UP   = '<path d="M12 19V6"/><polyline points="6 12 12 6 18 12"/>';
    var ROL_DOWN = '<path d="M12 5v13"/><polyline points="6 12 12 18 18 12"/>';
    // `lit` = silnik jedzie WLASNIE w te strone. Strzalka nie niesie polozenia (to robi okno),
    // wiec niebieski moze znaczyc wylacznie ruch i nie miesza sie z niczym innym.
    // Przycisk WYPELNIA komorke grida (wPx x hPx), a strzalka siedzi w nim wysrodkowana — cale pole
    // jest klikalne, nie tylko sama ikona. `display:block` na svg konieczne, bo inline SVG dostaje
    // dolna przestrzen od line-height i pole dotyku rozjezdza sie w pionie.
    function rolBtn(dir, body, wPx, hPx, lit) {
        // Border obrysowuje realne pole dotyku strzalki. Krycie 0,12 — dwa razy przyciemniane od
        // pierwszej wersji (0,45 -> 0,22 -> 0,12): ramka ma tylko podpowiadac, gdzie celowac.
        // box-sizing:border-box — inaczej `width` liczy sie BEZ bordera i dwie strzalki wychodza
        // o 4 px szersze niz okno nad nimi, ktore ma dokladnie `colW * 2 + gap`.
        return '<button data-rol="' + dir + '" style="background:none;margin:0;padding:0;'
             + 'box-sizing:border-box;'
             + 'border:1px solid rgba(255,255,255,.12);border-radius:' + Math.round(hPx * 0.14) + 'px;'
             + 'width:' + wPx + 'px;height:' + hPx + 'px;'
             + 'cursor:pointer;display:flex;align-items:center;justify-content:center;'
             + 'color:' + (lit ? '#4a9eff' : '#7e7e7e') + ';'
             + '-webkit-tap-highlight-color:transparent;touch-action:manipulation">'
             + '<svg width="' + Math.round(Math.min(wPx, hPx) * 0.92) + '" height="' + Math.round(Math.min(wPx, hPx) * 0.92) + '" '
             + 'viewBox="0 0 24 24" fill="none" '
             + 'stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" '
             + 'style="display:block">' + body + '</svg></button>';
    }

    // Impuls: sterownik gubi pozycje przy pulse, wiec potwierdzeniem jest odswiezenie stanu.
    // Roleta jedzie kilkanascie sekund, stad kilka doczytan zamiast jednego.
    rol.addEventListener('click', function(e){
        var b = e.target.closest('[data-rol]'); if (!b) return;
        e.stopPropagation();
        // Po dlugim przytrzymaniu okna przegladarka i tak dosyla `click` przy puszczeniu palca —
        // nie moze on zatrzymac rolety, bo user chcial tylko zobaczyc algorytm.
        if (algoFired) { algoFired = false; return; }
        var dir = b.getAttribute('data-rol');
        // Klik w SAMO OKNO zatrzymuje roletę — celujesz w to, co ma stanac, wiec nie trzeba
        // trzeciego przycisku i rzad zostaje trzyelementowy.
        if (dir === 'stop') {
            $.post('api.php', {action: 'roleta_stop'});
            b.style.opacity = '.45';
            setTimeout(function(){ b.style.opacity = '1'; }, 900);
        } else {
            $.post('api.php', {action: 'roleta_move', dir: dir});
            b.style.color = '#4a9eff';                              // blysk = klikniecie doszlo
            setTimeout(function(){ b.style.color = '#7e7e7e'; }, 900);
        }
        // Gestsze doczytania niz przy samej pozycji: podswietlenie ma nadazac za jazda,
        // a przejazd trwa kilkanascie sekund.
        [600, 1500, 3000, 5000, 8000, 12000, 17000, 23000, 30000].forEach(function(ms){ setTimeout(load, ms); });
    });

    // ======== DLUGIE PRZYTRZYMANIE OKNA = OKIENKO „ALGORYTM ROLETY" (2026-09-09) ========
    // User: „zebym nie musial pytac ciagle, czy roleta powinna juz zjechac". Okienko pokazuje WSZYSTKIE
    // warianty automatu (rano / dzien / geometria / zachod / blokada / flagi) z ocena kazdego warunku
    // TAK/NIE wg aktualnych odczytow — liczy to api/roleta_algo.inc.php (warunki 33 i 110 czyta z bazy).
    // Gest: palec na oknie >= ALGO_HOLD ms bez przesuniecia. Krotki tap = STOP jak dotad.
    // Pointer events (iPadOS 13+), `algoFired` tlumi `click`, ktory przychodzi po puszczeniu palca.
    var ALGO_HOLD = 600, algoTimer = null, algoFired = false, algoStart = null, algoRefresh = null;
    function algoCancel() { if (algoTimer) { clearTimeout(algoTimer); algoTimer = null; } }
    rol.addEventListener('pointerdown', function(e){
        var b = e.target.closest('[data-rol="stop"]'); if (!b) return;
        algoStart = {x: e.clientX, y: e.clientY};
        algoCancel();
        algoTimer = setTimeout(function(){ algoTimer = null; algoFired = true; algoOpen(); }, ALGO_HOLD);
    });
    rol.addEventListener('pointermove', function(e){
        if (!algoTimer || !algoStart) return;
        if (Math.abs(e.clientX - algoStart.x) > 10 || Math.abs(e.clientY - algoStart.y) > 10) algoCancel();
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function(ev){ rol.addEventListener(ev, algoCancel); });
    rol.addEventListener('contextmenu', function(e){ e.preventDefault(); });   // iOS/desktop: bez menu na hold

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    // Wiersz sprawdzenia: [label, wartosc, ok, typ]. ok=true zielony, false czerwony, null szary (info).
    function algoRow(r) {
        var ok = r[2], typ = r[3] || '';
        var col = ok === true ? '#4caf50' : (ok === false ? '#e05252' : '#777');
        var mark = ok === true ? 'TAK' : (ok === false ? 'NIE' : '·');
        return '<div style="display:flex;gap:8px;align-items:baseline;padding:3px 0 3px ' + (typ === 'sub' ? '18px' : '0') + ';'
             + 'border-top:1px solid #2a2a2a;font-size:13px;line-height:1.3">'
             + '<span style="flex:0 0 34px;font-weight:700;color:' + col + '">' + mark + '</span>'
             + '<span style="flex:1;color:' + (typ === 'group' ? '#ddd' : '#bbb') + '">' + esc(r[0]) + '</span>'
             + '<span style="flex:0 0 auto;color:#eee;font-variant-numeric:tabular-nums;text-align:right">' + esc(r[1]) + '</span></div>';
    }
    function algoBlock(b) {
        var vcol = b.ok === true ? '#4caf50' : (b.ok === false ? '#e05252' : '#aaa');
        var h = '<div style="background:#232323;border-radius:12px;padding:10px 12px;margin-bottom:10px;'
              + (b.enabled ? '' : 'opacity:.5;') + '">'
              + '<div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px">'
              + '<div style="font-size:15px;font-weight:700;color:#fff">' + esc(b.title) + (b.id ? ' <span style="color:#666;font-weight:400;font-size:12px">#' + b.id + '</span>' : '') + '</div>'
              + '<div style="font-size:12px;color:#888;white-space:nowrap">' + esc(b.when) + '</div></div>'
              + '<div style="font-size:12px;color:#999;margin:4px 0 8px;line-height:1.35">' + esc(b.desc) + '</div>';
        (b.rows || []).forEach(function(r){ h += algoRow(r); });
        h += '<div style="margin-top:8px;font-size:13px;font-weight:700;color:' + vcol + '">' + esc(b.verdict) + '</div>';
        if (b.last) h += '<div style="font-size:12px;color:#777">ostatnio odpaliła: ' + esc(b.last) + '</div>';
        return h + '</div>';
    }
    function algoRender(d) {
        var body = document.getElementById('dbAlgoBody'); if (!body) return;
        var s = d.sun || {}, v = d.vals || {};
        var h = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 14px;font-size:13px;color:#ccc;margin-bottom:12px">'
              + '<div>roleta: <b style="color:#fff">' + (d.pos == null ? '—' : d.pos + '%') + '</b> (' + esc(d.dir) + ')</div>'
              + '<div>blokada ręczna: <b style="color:' + (d.lock ? '#e05252' : '#4caf50') + '">' + (d.lock ? 'do ' + d.lock : 'brak') + '</b></div>'
              + '<div>salon <b style="color:#fff">' + esc(v.salon) + '</b> · komfort <b style="color:#fff">' + esc(v.komfort) + '</b></div>'
              + '<div>słońce <b style="color:#fff">' + esc(v.slonce) + '</b> · cień <b style="color:#fff">' + esc(v.cien) + '</b></div>'
              + '<div>wschód ' + esc(s.sunrise) + ' · zachód ' + esc(s.sunset) + '</div>'
              + '<div>az ' + esc(s.az) + '° · elev ' + esc(s.elev) + '° · tryb ' + esc(v.tryb) + '</div>'
              + '</div>';
        (d.blocks || []).forEach(function(b){ h += algoBlock(b); });
        body.innerHTML = h;
        var t = document.getElementById('dbAlgoTime'); if (t) t.textContent = 'stan ' + d.now;
    }
    function algoLoad() {
        $.getJSON('api.php?action=roleta_algo&_=' + Date.now(), function(d){ if (d && d.ok) algoRender(d); })
         .fail(function(){ var b = document.getElementById('dbAlgoBody'); if (b) b.innerHTML = '<div style="color:#e05252">Błąd pobierania</div>'; });
    }
    function algoClose() {
        var m = document.getElementById('dbAlgo'); if (m) m.remove();
        if (algoRefresh) { clearInterval(algoRefresh); algoRefresh = null; }
    }
    function algoOpen() {
        algoClose();
        // z-index 10000: nad kolem aktywacji dzwieku (9999). Tlo lapie tap = zamknij.
        var m = document.createElement('div');
        m.id = 'dbAlgo';
        m.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.72);display:flex;'
            + 'align-items:center;justify-content:center;padding:14px;-webkit-tap-highlight-color:transparent';
        m.innerHTML = '<div style="background:#1a1a1a;border:1px solid #444;border-radius:16px;width:min(560px,100%);'
            + 'max-height:100%;display:flex;flex-direction:column;box-shadow:0 8px 40px rgba(0,0,0,.8);color:#ddd;'
            + 'font-family:inherit">'
            + '<div style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid #333">'
            + '<div style="font-size:17px;font-weight:700;color:#fff;flex:1">Algorytm rolety</div>'
            + '<div id="dbAlgoTime" style="font-size:12px;color:#888"></div>'
            + '<button id="dbAlgoX" style="background:#333;border:none;color:#fff;font-size:18px;width:36px;height:36px;'
            + 'border-radius:50%;cursor:pointer;-webkit-tap-highlight-color:transparent">✕</button></div>'
            + '<div id="dbAlgoBody" style="overflow-y:auto;padding:12px 14px;-webkit-overflow-scrolling:touch">'
            + '<div style="color:#888">Ładowanie…</div></div></div>';
        m.addEventListener('click', function(e){ if (e.target === m || e.target.id === 'dbAlgoX') algoClose(); });
        document.body.appendChild(m);
        algoLoad();
        algoRefresh = setInterval(algoLoad, 60000);
    }
    if (typeof cardOnUnload === 'function') cardOnUnload(algoClose);

    var last = null;

    // Kolor liczony wzgledem TEMPERATURY KOMFORTOWEJ (helper komfort_target), nie nastawy termostatu.
    // Paleta 1:1 ze starymi kafelkami: komfort zielony, cieplej pomaranczowy, goraco czerwone,
    // chlodniej niebieskie.
    function tempColor(t, komfort) {
        if (t === null || komfort === null) return '#44ff44';
        var d = t - komfort;
        if (d >  3.0) return '#ff4444';
        if (d >  1.0) return '#ffa500';
        if (d < -1.0) return '#44ccff';
        return '#44ff44';
    }
    // Progi: <45 sucho, 45-55 norma, 55-65 PODWYZSZONE, >65 duzo.
    // 57% to jeszcze nie jest „duzo wody", tylko podniesiona wilgotnosc — stad osobny stopien.
    function humColor(h) {
        if (h === null) return '#44ccff';
        if (h < 45) return '#C2B280';
        if (h > 65) return '#1f7fd0';
        if (h > 55) return '#2b9fe6';
        return '#44ccff';
    }

    // ILOSC kropli niesie informacje szybciej niz kolor czy sama liczba:
    //   sucho (<45%)  — kropla PUSTA, sam obrys (zima z rekuperatorem, to sie chce widziec),
    //   norma         — jedna pelna kropla,
    //   wilgotno (>55%) — trzy pelne krople, rzuca sie w oczy natychmiast.
    // Wilgotnosc jest wzgledna, ale w domu jest stale ~20-24 stopni, wiec progi znacza to samo caly rok.
    var DROP = 'M12 3.2 C12 3.2 6.2 10.6 6.2 14.4 A5.8 5.8 0 0 0 17.8 14.4 C17.8 10.6 12 3.2 12 3.2 Z';
    // ZAWSZE TRZY SLOTY: tyle, ile wynosi maksimum. Pelne sa kolorowe, brakujace szare —
    // dzieki temu widac nie tylko "ile jest wody", ale i "ile moze byc", bez znajomosci skali.
    function dropIcon(h, px) {
        function drop(sz, gray) {
            return '<span style="font-size:' + sz + 'px;line-height:1;'
                 + (gray ? 'filter:grayscale(1) brightness(.55);opacity:.5;' : '')
                 + '">\uD83D\uDCA7</span>';
        }
        // Sucho (<45 %): pierwszy slot to rysowany OBRYS w kolorze suchosci — emoji nie da sie
        // zrobic "pustego", a to wlasnie brak wody jest tu informacja (zima z rekuperatorem).
        function outline(sz, col) {
            return '<svg width="' + sz + '" height="' + sz + '" viewBox="0 0 24 24" style="display:block">'
                 + '<path d="' + DROP + '" fill="none" stroke="' + col + '" stroke-width="2.2" stroke-linejoin="round"/></svg>';
        }
        var full = (h === null) ? 1 : (h > 65 ? 3 : (h > 55 ? 2 : (h < 45 ? 0 : 1)));
        var out  = '<span style="display:flex;align-items:center;gap:' + Math.max(1, Math.round(px * 0.06)) + 'px">';
        // Wypelnienie narasta od PRAWEJ, wiec szare sloty stoja z lewej i czytaja sie jak
        // "tyle jeszcze brakuje". Slot skrajnie prawy jest pierwszym, ktory sie zapala.
        for (var i = 0; i < 3; i++) {
            var filled = (i >= 3 - full);
            if (i === 2 && full === 0) out += outline(px, humColor(h));   // sucho: obrys tam, gdzie zapala sie pierwsza
            else                       out += drop(px, !filled);
        }
        return out + '</span>';
    }

    function paint() {
        var k = scale(), bar = barWidth();
        var padX = Math.round(7 * k), padY = Math.round(8 * k);
        var w = bar ? Math.round(bar) : Math.round(150 * k);
        box.style.top          = '0px';
        box.style.bottom       = '0px';
        box.style.width        = w + 'px';
        box.style.minWidth     = '0px';
        // Dolny padding MALY, gorny zostaje: grupa rolety ma siedziec przy samej dolnej krawedzi
        // pasa, a domek z temperatura potrzebuje odstepu od gory.
        box.style.padding      = padY + 'px ' + padX + 'px ' + Math.round(3 * k) + 'px';
        box.style.gap             = '0px';
        // Grupa siedzi PRZY GORZE, nie na srodku — dol pasa zostaje wolny pod sterownik rolety.
        box.style.justifyContent  = 'flex-start';
        // Promien jak wrapper kamery, na sztywno. Probowalem skalowac go przez `k`, zeby prawe
        // naorzniki byly wyrazniejsze na iPadzie — user kazal to olac, wiec zostaje 12 px.
        box.style.borderRadius = '0 12px 12px 0';

        if (!last) return;
        // toFixed(1) zamiast surowej wartosci: API zwraca 23 dla rownej temperatury, a format ma byc
        // jednolity xx,x — inaczej raz jest "23", raz "23,4" i kolumna skacze.
        var t = last.dom.t === null ? '--' : Number(last.dom.t).toFixed(1).replace('.', ',') + '\u00B0C';
        var h = last.dom.h === null ? '--' : last.dom.h + '%';
        var avail = w - 2 * padX;

        // Font DOPASOWANY POMIAREM, nie wzorem: "24,2°C" jest o dwa znaki dluzsze niz "24°C",
        // wiec staly dzielnik raz zostawial pustke, raz wypychal tekst na kadr. Rysujemy raz
        // w rozmiarze docelowym, mierzymy realna szerokosc i jesli nie miesci sie w pasie,
        // skalujemy w dol proporcjonalnie — jeden dodatkowy przebieg, bez petli.
        function render(fT) {
            // Dwie pary: domek z temperatura i wilgotnosc z kroplami. W parze maly odstep,
            // miedzy parami wyrazny — inaczej cztery elementy czytaja sie jak jedna kolumna liczb.
            tile.style.gap = Math.round(6 * k) + 'px';
            tile.innerHTML =
                '<span style="font-size:' + Math.round(fT * 0.95) + 'px;line-height:1">\uD83C\uDFE0</span>'
              + '<span class="dbT" style="color:' + tempColor(last.dom.t, last.komfort) + ';line-height:1.05">' + t + '</span>'
              + '<span style="font-size:' + Math.round(fT * 0.75) + 'px;font-weight:400;line-height:1.2;'
              + 'margin-top:' + Math.round(8 * k) + 'px;color:' + humColor(last.dom.h) + '">' + h + '</span>'
              + '<span style="line-height:1">' + dropIcon(last.dom.h, Math.round(fT * 0.48)) + '</span>';
            tile.style.fontSize = fT + 'px';
        }

        tile.style.opacity = last.dom.stale ? '.45' : '1';
        var base = Math.round(30 * k);
        render(base);
        var el = tile.querySelector('.dbT');
        var fFinal = base;
        if (el && el.scrollWidth > avail) {
            fFinal = Math.max(10, Math.floor(base * avail / el.scrollWidth));
            render(fFinal);
        }

        // ROLETA rysowana na koncu, bo ikona okna ma byc DOKLADNIE tej samej wielkosci co domek —
        // a domek skaluje sie z finalnym rozmiarem temperatury, nie z wartoscia wyjsciowa.
        // Strzalka w gore swieci przy pozycji >= 50, w dol ponizej; brak odczytu => zadna.
        var rp   = (last.roleta_pos == null) ? null : last.roleta_pos;
        var rgap = Math.round(4 * k);
        var colW = Math.floor((avail - rgap) / 2);      // dwie kolumny na cala szerokosc pasa
        var rpx  = Math.round(fFinal * 1.05);           // baza: tyle, ile ikona domku wyzej
        // Okno 20% wyzsze od bazy, przemnozone przez 0,783 — tyle zajmowala widoczna ramka, gdy
        // svg mialo jeszcze margines 2,6 w viewBoxie. Bez tej korekty zdjecie marginesu podnioslo
        // by okno o kolejne 21%, o ktore nikt nie prosil.
        var winH = Math.round(rpx * 1.20 * 0.783);
        var btnH = Math.round(rpx * 1.35);              // wiersz strzalek WYZSZY, palec ma w co trafic
        rol.style.paddingBottom = '0px';
        rol.innerHTML =
            '<div style="display:grid;grid-template-columns:' + colW + 'px ' + colW + 'px;'
          + 'gap:' + rgap + 'px;justify-items:center;align-items:center">'
          + winIcon(colW * 2 + rgap, winH, rp)
          + rolBtn('otworz',  ROL_UP,   colW, btnH, last.roleta_dir === 'up')
          + rolBtn('zamknij', ROL_DOWN, colW, btnH, last.roleta_dir === 'down')
          + '</div>';
    }

    function load() {
        $.getJSON('api.php?action=klimat_tiles&_=' + Date.now(), function(d){
            if (d && d.ok) { last = d; paint(); }
        });
    }
    paint();
    load();
    window.dbTileTimer = setInterval(load, 60000);
    if (typeof cardOnUnload === 'function') cardOnUnload(function() { clearInterval(window.dbTileTimer); window.dbTileTimer = null; });
    v.addEventListener('loadedmetadata', paint);   // dopiero wtedy znamy proporcje strumienia
    if (window.ResizeObserver) new ResizeObserver(paint).observe(wrap);
})();
</script>
<?php
?>
<script>
(function(){
    var ID = 'cam_doorbell_sd';
    var v = document.getElementById(ID);
    if (!v) return;
    var wrap = v.parentElement;
    var on = false;

    // Status overlay "Laczenie..." / "Rozlaczanie..." — feedback podczas handshake WebRTC
    // Karta bywa ladowana ponownie (loadCard/swipe) i wtedy ten blok wykonuje sie drugi raz.
    // Bez sprzatania na kadrze zostawaly DWIE sluchawki, a stara zamarzala w stanie „dzwoni":
    // czyscilismy jej timer (window.dbRingTimer), ale sam przycisk zostawal w DOM.
    var oldBtn = document.getElementById('dbPhoneBtn');
    if (oldBtn) oldBtn.remove();
    var oldSt = document.getElementById('dbPhoneStatus');
    if (oldSt) oldSt.remove();

    var status = document.createElement('div');
    status.id = 'dbPhoneStatus';
    status.style.cssText = 'position:absolute;bottom:12px;left:80px;z-index:10;background:rgba(0,0,0,0.7);color:rgba(255,255,255,0.9);font-size:13px;padding:6px 14px;border-radius:16px;display:none;pointer-events:none;';
    wrap.appendChild(status);
    function showStatus(text) { status.textContent = text; status.style.display = 'block'; }
    function hideStatus() { status.style.display = 'none'; }

    var btn = document.createElement('button');
    btn.id = 'dbPhoneBtn';
    // Gruba biala ramka POJAWIA SIE TYLKO NA CZAS DZWONKA: bez niej pulsowanie (scale + poswiata)
    // po prostu nie bylo widac — zielone kolo na zielonej trawie z kamery nie ma o co "bic".
    // W spoczynku ramki nie ma, bo nic sie nie rusza i tylko dodawalaby halasu na kadrze.
    // box-sizing, zeby ramka zjadla srodek, a nie powiekszyla przycisk.
    btn.style.cssText = 'position:absolute;bottom:12px;left:12px;z-index:10;box-sizing:border-box;'
        + 'border:0 solid rgba(255,255,255,0.85);border-radius:50%;cursor:pointer;'
        + 'width:56px;height:56px;display:flex;align-items:center;justify-content:center;padding:0;'
        + 'transition:left .25s,bottom .25s,width .25s,height .25s,background .25s;';

    var svgPhone = '<svg width="28" height="28" viewBox="0 0 24 24" fill="white" stroke="none"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';

    function setOff() {
        showStatus('Rozłączanie...');
        btn.disabled = true;
        on = false;
        btn.style.background = 'rgba(76,175,80,0.85)';
        btn.innerHTML = svgPhone;
        btn.style.transform = 'none';
        v.muted = true;
        var fn = window[ID + '_mic'];
        if (fn) try { fn(false); } catch(e){}
        setTimeout(function() { hideStatus(); btn.disabled = false; }, 300);
        applyRing();
    }

    function setOn() {
        btn.disabled = true;
        showStatus('Łączenie...');
        v.muted = false;
        var fn = window[ID + '_mic'];
        if (!fn) return;
        Promise.resolve(fn(true)).then(function(ok) {
            btn.disabled = false;
            if (ok) {
                on = true;
                hideStatus();
                btn.style.background = 'rgba(244,67,54,0.85)';
                btn.innerHTML = svgPhone;
                btn.style.transform = 'rotate(135deg)';
                applyRing();
            } else {
                showStatus('Połączenie nieudane');
                setTimeout(setOff, 1500);
            }
        });
    }

    on = false;
    btn.style.background = 'rgba(76,175,80,0.85)';
    btn.innerHTML = svgPhone;

    btn.onclick = function() {
        if (btn.disabled) return;
        if (on) { setOff(); }
        else {
            // POTWIERDZENIE: odebranie zamyka TEN dzwonek. Bez tego rozlaczenie wracalo do migania,
            // bo okno dzwonka trwa 60 s i `ringing` bylo dalej true — a odebrana rozmowa znaczy
            // „obslugane", nie „ktos nadal dzwoni". Pulsar wroci dopiero na NOWSZY stempel.
            // Znacznik na `window`, zeby przezyl przeladowanie samej karty (loadCard/swipe).
            window._dbRingAck = curT;
            setOn();
        }
        ringing = false;
        applyRing();
    };
    wrap.appendChild(btn);

    // KTOS DZWONI -> sluchawka pulsuje tak samo mocno jak kolo „aktywuj dzwiek", bo to tez rzecz
    // do zrobienia TERAZ. Zrodlo: api doorbell_check, ktore patrzy w log na 'doorbell=True' z
    // ostatnich 60 s — okno gasnie samo, wiec nie trzeba niczego kasowac po rozmowie.
    // Pulsujemy TYLKO gdy nieodebrane: po kliknieciu sluchawka obraca sie o 135 stopni (rozlacz),
    // a animacja nadpisywalaby ten transform i ikona by sie "prostowala".
    var ringing = false;
    var curT    = 0;                                    // stempel dzwonka z api (0 = cisza)
    if (window._dbRingAck == null) window._dbRingAck = 0;

    if (!document.getElementById('dbRingCss')) {
        var rst = document.createElement('style');
        rst.id = 'dbRingCss';
        rst.textContent = '@keyframes dbRingPulse{0%,100%{transform:scale(1);box-shadow:0 0 0 0 rgba(76,175,80,.65)}'
            + '50%{transform:scale(1.1);box-shadow:0 0 0 40px rgba(76,175,80,0)}}';
        document.head.appendChild(rst);
    }

    // Dzwoni -> sluchawka WYCHODZI Z ROGU: wieksza, blizej srodka kadru i z wieksza poswiata,
    // zeby dalo sie w nia trafic bez celowania. Zielen schodzi do 35% krycia — dopoki nie odbierzesz,
    // wazniejsze jest to, KTO stoi za drzwiami, niz sam przycisk. Po odebraniu wraca w rog.
    function applyRing() {
        var live = ringing && !on;
        btn.style.animation   = live ? 'dbRingPulse 1.1s ease-in-out infinite' : 'none';
        btn.style.width       = btn.style.height = live ? '104px' : '56px';
        btn.style.left        = live ? '8%' : '12px';
        btn.style.bottom      = live ? '8%' : '12px';
        btn.style.borderWidth = live ? '4px' : '0';   // ramka tylko gdy pulsuje — to ona pokazuje ruch
        if (live)     btn.style.background = 'rgba(76,175,80,0.35)';
        else if (!on) btn.style.background = 'rgba(76,175,80,0.85)';   // spoczynek: pelna zielen jak dawniej; przezroczysta jest tylko ta pulsujaca
        var svg = btn.querySelector('svg');
        if (svg) { svg.setAttribute('width', live ? '48' : '28'); svg.setAttribute('height', live ? '48' : '28'); }
    }

    function pollRing() {
        if (!document.getElementById(ID)) return;
        $.getJSON('api.php?action=doorbell_check&_=' + Date.now(), function(d){
            curT    = (d && d.t) ? d.t : 0;
            ringing = curT > 0 && curT > window._dbRingAck;
            applyRing();
        });
    }
    if (window.dbRingTimer) clearInterval(window.dbRingTimer);
    pollRing();
    window.dbRingTimer = setInterval(pollRing, 3000);
    if (typeof cardOnUnload === 'function') cardOnUnload(function() { clearInterval(window.dbRingTimer); window.dbRingTimer = null; });
})();
</script>
<script>
// AKTYWACJA DZWIEKU — duze czerwone pulsujace kolo na SRODKU KADRU DZWONKA, na samym wierzchu.
// Dopoki AudioContext nie gra, dzwonek i alarmy NIE ZAGRAJA, wiec to wymagana akcja, nie detal.
// Wczesniej kolo siedzialo na kadrze parkingowym; zeszlo tutaj, bo blokada dotyczy dzwonka.
// Samo kolo nie musi byc celem — kazde dotkniecie ekranu odblokowuje audio (index/doorbell.inc.js
// lapie touchstart/click na document, a ui.php przekazuje gest z ramki do shella).
//
// SRODEK LICZONY Z OBRAZU, NIE Z KONTENERA: kadr dzwonka to kolo 4:3 dosuniete do lewej w
// kontenerze 16:9, wiec geometryczny srodek wrappera wypada w czarnym pasie. Kolo stawiamy na
// polowie SZEROKOSCI OBRAZU — tak samo jak barWidth() w bloku wyzej.
(function(){
    var ID = 'cam_doorbell_sd';
    var v = document.getElementById(ID);
    if (!v) return;
    var wrap = v.parentElement;

    var old = document.getElementById('audioOv');
    if (old) old.remove();

    if (!document.getElementById('audioOvCss')) {
        var st = document.createElement('style');
        st.id = 'audioOvCss';
        st.textContent = '@keyframes audioOvPulse{0%,100%{transform:scale(1);box-shadow:0 0 0 0 rgba(220,40,40,.55)}'
            + '50%{transform:scale(1.06);box-shadow:0 0 0 22px rgba(220,40,40,0)}}';
        document.head.appendChild(st);
    }

    // z-index 9999: nad sluchawka (10), nakladkami domu i rolety (12) oraz loaderem.
    var ov = document.createElement('div');
    ov.id = 'audioOv';
    ov.style.cssText = 'position:absolute;top:50%;transform:translate(-50%,-50%);z-index:9999;'
        + 'display:none;align-items:center;cursor:pointer;'
        + '-webkit-tap-highlight-color:transparent;touch-action:manipulation;user-select:none;';

    var circle = document.createElement('div');
    // Krycie 0,35 jak przy pulsujacej sluchawce: kolo ma krzyczec, a nie zaslaniac kadr.
    circle.style.cssText = 'border-radius:50%;background:rgba(200,30,30,0.35);'
        + 'border:3px solid rgba(255,255,255,0.85);display:flex;align-items:center;justify-content:center;'
        + 'animation:audioOvPulse 1.6s ease-in-out infinite;';
    var bell = document.createElement('div');
    circle.appendChild(bell);

    ov.appendChild(circle);

    // Szerokosc obrazu przy object-fit:contain. Przed pierwsza ramka videoWidth = 0 -> fallback 4:3.
    function imgWidth() {
        var W = wrap.offsetWidth, H = wrap.offsetHeight;
        var ar = (v.videoWidth && v.videoHeight) ? (v.videoWidth / v.videoHeight) : (4 / 3);
        return Math.min(W, H * ar);
    }

    // Kolo skalowane obrazem, ale z widelkami: na iPhonie nie moze zjesc calego kadru,
    // na iPadzie ma byc czytelne z drugiego konca pokoju.
    function paint() {
        var iw = imgWidth();
        var d = Math.max(84, Math.min(150, Math.round(iw * 0.30)));
        ov.style.left = Math.round(iw / 2) + 'px';
        circle.style.width = d + 'px';
        circle.style.height = d + 'px';
        var s = Math.round(d / 2);
        bell.innerHTML = '<svg width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="#fff" '
            + 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
            + '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>'
            + '<path d="M13.73 21a2 2 0 0 1-3.46 0"/>'
            + '<line x1="2" y1="2" x2="22" y2="22" stroke-width="2.2"/></svg>';
    }

    function unlock() {
        if (window._tymosUnlock) { try { window._tymosUnlock(); } catch(e){} }
        window._dbUnlocked = true;
        sessionStorage.setItem('db_audio', '1');
        setTimeout(refresh, 300);
    }
    ov.addEventListener('touchstart', unlock);
    ov.addEventListener('click', unlock);

    // Petla weryfikacyjna co 3 s: kolo widoczne dokladnie wtedy, gdy AudioContext NIE gra.
    // Jesli wroci samo na spokojnie stojacym kiosku => Safari uspilo dzwiek.
    function refresh() {
        var s = window._tymosAudioState ? window._tymosAudioState() : 'none';
        ov.style.display = (s === 'running') ? 'none' : 'flex';
    }

    wrap.appendChild(ov);
    paint();
    refresh();
    v.addEventListener('loadedmetadata', paint);   // dopiero wtedy znamy proporcje strumienia
    if (window.ResizeObserver) new ResizeObserver(paint).observe(wrap);
    if (window.audioOvTimer) clearInterval(window.audioOvTimer);
    window.audioOvTimer = setInterval(refresh, 3000);
    if (typeof cardOnUnload === 'function') cardOnUnload(function() { clearInterval(window.audioOvTimer); window.audioOvTimer = null; });
})();
</script>
<script>
// HIFI NA KADRZE DZWONKA (PROBA 2026-10-10) — te same przyciski co karta card_hifi, ktora na razie zostaje:
// prawy GORNY rog obrazu: poprzedni / stop-graj / nastepny, prawy DOLNY: ciszej / glosnosc / glosniej.
// "Prawy rog" = prawa krawedz OBRAZU, nie kontenera — za nia jest czarny pas z temperatura (dbSideBox),
// wiec offset liczony tak samo jak szerokosc tego pasa. Wlasny poll co 5 s, pauzowany przy wygaszonym ekranie.
(function(){
    var ID = 'cam_doorbell_sd';
    var v = document.getElementById(ID);
    if (!v) return;
    var wrap = v.parentElement;

    ['dbHifiTop', 'dbHifiBot'].forEach(function(id){ var o = document.getElementById(id); if (o) o.remove(); });

    var ROW = 'position:absolute;z-index:12;display:flex;gap:8px;align-items:center;';
    var BTN = 'border:none;border-radius:50%;cursor:pointer;width:44px;height:44px;display:flex;align-items:center;'
        + 'justify-content:center;padding:0;background:rgba(0,0,0,0.3);color:rgba(255,255,255,0.85);'
        + '-webkit-tap-highlight-color:transparent;touch-action:manipulation;user-select:none;';
    function svg(d, id) {
        return '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path'
            + (id ? ' id="' + id + '"' : '') + ' d="' + d + '"/></svg>';
    }
    function button(title, html, fn) {
        var b = document.createElement('button');
        b.title = title; b.style.cssText = BTN; b.innerHTML = html; b.onclick = fn;
        return b;
    }

    var st = '', lastSrc = '';

    var top = document.createElement('div');
    top.id = 'dbHifiTop';
    top.style.cssText = ROW + 'top:12px;';
    function ctl(c) {
        if (c === 'pp') {
            if (st === 'playing') c = 'pause';
            else if (st === 'paused') c = 'play';
            else if (lastSrc === 'spotify') {
                // Zatrzymany Spotify: wznowienie ostatniej sesji — jak w karcie HiFi
                $.post('api.php', {action: 'hifi_set', op: 'src', src: 'spotify'}, function(){ setTimeout(poll, 1500); }, 'json');
                return;
            }
            else c = 'play';
        }
        $.post('api.php', {action: 'hifi_set', op: 'ctl', ctl: c}, function(){ setTimeout(poll, 800); }, 'json');
    }
    top.appendChild(button('Poprzedni',   svg('M6 5h2v14H6zM20 5v14L9 12z'),   function(){ ctl('previous'); }));
    top.appendChild(button('Stop / graj', svg('M7 5v14l12-7z', 'dbHifiPpIc'), function(){ ctl('pp'); }));
    top.appendChild(button('Następny',    svg('M16 5h2v14h-2zM4 5v14l11-7z'),  function(){ ctl('next'); }));

    var bot = document.createElement('div');
    bot.id = 'dbHifiBot';
    // Glosnosc w PIONIE (user 2026-10-10): glosniej u gory, liczba, ciszej na dole.
    bot.style.cssText = ROW + 'bottom:12px;flex-direction:column;';
    var volEl = document.createElement('span');
    volEl.style.cssText = 'min-width:44px;height:44px;display:flex;align-items:center;justify-content:center;'
        + 'border-radius:22px;background:rgba(0,0,0,0.3);color:rgba(255,255,255,0.85);font:600 17px/1 system-ui,sans-serif;';
    volEl.textContent = '–';
    // Glosnosc: liczba zmienia sie OD RAZU (optymistycznie), poll i tak potwierdzi stan z wiezy.
    function vol(d) {
        var cur = parseInt(volEl.textContent, 10);
        if (!isNaN(cur)) volEl.textContent = Math.max(0, Math.min(100, cur + d));
        $.post('api.php', {action: 'hifi_set', op: 'vol', delta: d}, function(r){
            if (r && r.ok) volEl.textContent = r.volume; else poll();
        }, 'json');
    }
    bot.appendChild(button('Głośniej', svg('M5 16h14l-7-9z'), function(){ vol(1); }));
    bot.appendChild(volEl);
    bot.appendChild(button('Ciszej',   svg('M5 8h14l-7 9z'),  function(){ vol(-1); }));

    function show(d) {
        if (!d || !d.ok) { volEl.textContent = '–'; st = ''; return; }
        volEl.textContent = d.volume;
        volEl.style.textDecoration = d.mute ? 'line-through' : '';
        st = d.state;
        lastSrc = d.title ? d.src : (d.last ? d.last.src : '');
        var ic = document.getElementById('dbHifiPpIc');
        if (ic) ic.setAttribute('d', st === 'playing' ? 'M6 6h12v12H6z' : 'M7 5v14l12-7z');
    }
    function poll() {
        if (!document.getElementById('dbHifiTop')) { clearInterval(window.dbHifiTimer); return; }
        if (document.hidden) return;
        $.getJSON('api.php?action=hifi_state&_=' + Date.now(), show);
    }

    // Ten sam wzor co szerokosc czarnego pasa w bloku temperatury (fallback 4:3 przed pierwsza ramka).
    function place() {
        var W = wrap.offsetWidth, H = wrap.offsetHeight;
        var ar = (v.videoWidth && v.videoHeight) ? (v.videoWidth / v.videoHeight) : (4 / 3);
        var r = Math.max(0, W - Math.min(W, H * ar)) + 12;
        top.style.right = r + 'px';
        bot.style.right = r + 'px';
    }

    wrap.appendChild(top);
    wrap.appendChild(bot);
    place();
    v.addEventListener('loadedmetadata', place);
    if (window.ResizeObserver) new ResizeObserver(place).observe(wrap);

    poll();
    if (window.dbHifiTimer) clearInterval(window.dbHifiTimer);
    window.dbHifiTimer = setInterval(poll, 5000);
    if (typeof cardOnUnload === 'function') cardOnUnload(function() { clearInterval(window.dbHifiTimer); window.dbHifiTimer = null; });
})();
</script>
