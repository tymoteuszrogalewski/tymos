<?php
require_once __DIR__ . '/../../inc/camera.inc.php';
// ROSZADA KAMER 2026-09-06: parking_* = kamera .197, basen_* = kamera .145 (kadr ogrod/basen).
// 'ogrod_sd' (kamera .243 na przyszlym maszcie) jest w go2rtc, ale NIE w przelaczniku — kamera
// odlaczona, kafel bylby szary na miesiace. Gdy maszt stanie: dopisac 'ogrod_sd' ponizej
// i do $STREAMS w actions/snapshot_refresh.php.
// Basen kafelkiem i snapshotem idzie w HD — kamerka jest slaba i w SD nic nie widac; przycisk
// SD/HD dziala normalnie, wiec zejscie na substream to jedno klikniecie.
camera_render('parking_sd', '', false, ['parking_sd', 'basen_hd']);
?>
<script>
// Nakladki dziela sie na dwie grupy: te o KADRZE (zebatka, jakosc SD/HD, dzwiek) sa zawsze,
// a te o DOMU (pogoda, tarcze alarmu, pasek ostrzezen) tylko na parkingu. Kazda siedzi we
// wlasnym IIFE, wiec warunek musi byc wspolny — inaczej rosnie w kopiach.
window.camParkingActive = function () {
    return String(window['cam_parking_sd_stream'] || 'parking_sd').indexOf('parking') === 0;
};
(function(){
    var ID = 'cam_parking_sd';
    var v = document.getElementById(ID);
    if (!v) return;
    var wrap = v.parentElement;
    var on = false;

    var btn = document.createElement('button');
    btn.style.cssText = 'position:absolute;bottom:12px;right:12px;z-index:10;border:none;border-radius:50%;cursor:pointer;width:44px;height:44px;display:flex;align-items:center;justify-content:center;padding:0;background:rgba(0,0,0,0.3);color:rgba(255,255,255,0.85);transition:background 0.15s,color 0.15s;-webkit-tap-highlight-color:transparent;touch-action:manipulation;user-select:none;';
    var svgOn  = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>';
    var svgOff = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>';
    btn.innerHTML = svgOff;

    btn.onclick = function() {
        var fn = window[ID + '_listen'];
        if (!fn) return;
        if (on) {
            fn(false);
            on = false;
            btn.style.background = 'rgba(0,0,0,0.3)';
            btn.style.color = 'rgba(255,255,255,0.85)';
            btn.innerHTML = svgOff;
        } else {
            btn.disabled = true;
            Promise.resolve(fn(true)).then(function(ok) {
                btn.disabled = false;
                if (ok) {
                    on = true;
                    btn.style.background = 'rgba(33,150,243,0.35)';
                    btn.style.color = '#fff';
                    btn.innerHTML = svgOn;
                }
            });
        }
    };
    v.addEventListener('camswitch', function() {
        on = false;
        btn.disabled = false;
        btn.style.background = 'rgba(0,0,0,0.3)';
        btn.style.color = 'rgba(255,255,255,0.85)';
        btn.innerHTML = svgOff;
    });

    wrap.appendChild(btn);
})();

// JAKOSC SD / HD (gora, na srodku kadru) — pokazuje AKTUALNY stan i przelacza klikiem.
// Substream leci na iPada domyslnie (male pasmo, natychmiastowy start), HD na zadanie.
// Przelaczanie robi `switchTo` z inc/camera.inc.php, wystawione jako window[ID + '_switch'] —
// ta sama sciezka, ktorej uzywa pasek miniatur, wiec poster, glosnik i nakladki same nadazaja.
//
// Przycisk jest UNIWERSALNY: zamienia koncowke biezacego streamu _sd <-> _hd. Czyli po
// przelaczeniu kafelkiem na basen dziala tak samo, tylko na basen_*.
//
// AUDIO ZOSTAJE NA SD (swiadomie): parking_hd ma w go2rtc `#audio=none`. Po wejsciu w HD
// glosnik nie ma czego grac — `switchTo` i tak go gasi przy kazdej zmianie.
(function(){
    var ID = 'cam_parking_sd';
    var v = document.getElementById(ID);
    if (!v) return;
    var wrap = v.parentElement;

    // Karta bywa ladowana ponownie (loadCard/swipe) — bez tego zostawalyby dwa przyciski.
    var old = document.getElementById('camQualityBtn');
    if (old) old.remove();

    var b = document.createElement('button');
    b.id = 'camQualityBtn';
    b.style.cssText = 'position:absolute;top:12px;left:50%;transform:translateX(-50%);z-index:12;'
        + 'border:none;border-radius:10px;cursor:pointer;width:44px;height:44px;display:flex;'
        + 'align-items:center;justify-content:center;padding:0;font:700 15px/1 system-ui,sans-serif;'
        + 'letter-spacing:0.5px;transition:background 0.15s,color 0.15s;'
        + '-webkit-tap-highlight-color:transparent;touch-action:manipulation;user-select:none;';

    function biezacy() { return window[ID + '_stream'] || 'parking_sd'; }

    function paint() {
        var hd = biezacy().slice(-3) === '_hd';
        b.textContent = hd ? 'HD' : 'SD';
        // HD swieci (jak wlaczony glosnik), SD jest neutralne — od razu widac, ze cos jest wlaczone
        b.style.background = hd ? 'rgba(33,150,243,0.35)' : 'rgba(0,0,0,0.3)';
        b.style.color      = hd ? '#fff' : 'rgba(255,255,255,0.85)';
        b.title = hd ? 'HD — kliknij, żeby wrócić na substream' : 'Substream — kliknij, żeby przejść na HD';
    }

    b.onclick = function() {
        var s = biezacy();
        var cel = (s.slice(-3) === '_hd') ? s.slice(0, -3) + '_sd' : s.slice(0, -3) + '_hd';
        var fn = window[ID + '_switch'];
        if (!fn) return;
        b.disabled = true;
        fn(cel);
        // switchTo jest synchroniczne w czesci, ktora nas obchodzi (podmiana STREAM), reszta
        // to juz negocjacja WHEP — odblokowujemy po chwili, zeby nie dalo sie klikac w kolko.
        setTimeout(function(){ b.disabled = false; paint(); }, 600);
    };

    // Kafelki tez zmieniaja stream (parking <-> ogrod) i wracaja na _sd — napis ma za tym nadazyc.
    v.addEventListener('camswitch', function(){ paint(); });

    paint();
    wrap.appendChild(b);
})();

// ZEBATKA (lewy gorny rog) — wchodzi PROSTO do panelu admina. W HOME gorny pasek menu jest
// schowany (patrz panels.inc.js), zeby oddac miejsce widgetom; w adminie pasek jest widoczny,
// wiec powrot do HOME idzie normalnie ikona domku.
(function(){
    var v = document.getElementById('cam_parking_sd');
    if (!v) return;
    var wrap = v.parentElement;

    var old = document.getElementById('menuBtn');
    if (old) old.remove();

    var b = document.createElement('button');
    b.id = 'menuBtn';
    b.title = 'Ustawienia';
    b.style.cssText = 'position:absolute;top:12px;left:12px;z-index:12;border:none;border-radius:50%;'
        + 'cursor:pointer;width:44px;height:44px;display:flex;align-items:center;justify-content:center;'
        + 'padding:0;background:rgba(0,0,0,0.3);color:rgba(255,255,255,0.85);'
        + '-webkit-tap-highlight-color:transparent;touch-action:manipulation;user-select:none;';
    b.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/>'
        + '<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 '
        + '1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 '
        + '1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 '
        + '4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 '
        + '1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83'
        + 'l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>';
    b.onclick = function() { loadPanel('admin'); };
    wrap.appendChild(b);
})();

// TEMPERATURY ZEWNETRZNE (lewa krawedz, pod zebatka) — PROBA 2026-08-23.
// Kamera pokazuje dwor, wiec dwor ma tu swoje liczby. Zrodlo: api.php?action=klimat_tiles.
// 2026-08-29: WIDOCZNE TYLKO NA KADRZE PARKINGU. Wczesniej zostawaly przy przelaczeniu na ogrod
// czy basen — z zalozeniem, ze dotycza pogody, a nie konkretnej kamery. W praktyce zaslanialy
// obraz kamery, ktorej sie akurat oglada, wiec chowaja sie razem z tarczami alarmu.
//
// Oba wiersze siedza na JEDNYM tle (zaokraglony prostokat, to samo krycie co ikony) — dwa osobne
// pill-e rozjezdzaly sie wizualnie, bo maja rozne szerokosci liczb.
//
// ROZMIAR SKALUJE SIE Z SZEROKOSCIA KADRU: na iPhonie kamera ma ~440 px i wiecej sie nie zmiesci,
// na iPadzie ~1000 px i wtedy liczby maja byc czytelne z drugiego konca pokoju.
(function(){
    var v = document.getElementById('cam_parking_sd');
    if (!v) return;
    var wrap = v.parentElement;

    var old = document.getElementById('camTempBox');
    if (old) old.remove();
    if (window.camTempTimer) clearInterval(window.camTempTimer);


    var box = document.createElement('div');
    box.id = 'camTempBox';
    box.style.cssText = 'position:absolute;left:12px;z-index:11;display:flex;flex-direction:column;'
        + 'background:rgba(0,0,0,0.3);border-radius:14px;pointer-events:none;';
    wrap.appendChild(box);

    var last = null;
    function scale() { return Math.max(1, Math.min(wrap.offsetWidth / 440, 2.2)); }

    // Cien to temperatura, wg ktorej sie decyduje (okna, chlodzenie) — dostaje wiekszy stopien
    // pisma niz slonce, ktore jest tylko kontekstem „ile dokłada operacja".
    // JEDEN DIV NA WIERSZ, kazdy na pelna szerokosc nakladki, zawartosc wysrodkowana w srodku.
    // Siatka dwukolumnowa (proba wczesniej) dawala ikony w jednej osi i wartosci w drugiej —
    // wygladalo to jak dwie osobne kolumny, a nie jak dwa wiersze pogody.
    function row(icon, txt, stale, color, fs, ics) {
        var k = scale(), d = document.createElement('div');
        d.style.cssText = 'display:flex;width:100%;justify-content:center;align-items:center;'
            + 'gap:' + Math.round(9 * k) + 'px;color:' + color + ';font-weight:700;white-space:nowrap;'
            + 'font-size:' + Math.round(fs * k) + 'px;line-height:1.05;' + (stale ? 'opacity:.45;' : '');
        d.innerHTML = '<span style="font-size:' + Math.round(ics * k) + 'px;line-height:1">' + icon + '</span>'
            + '<span>' + (txt === null ? '--' : Number(txt).toFixed(1).replace('.', ',') + '\u00B0C') + '</span>';
        return d;
    }

    function paint() {
        if (!last) return;
        var k = scale();
        // NA SZTYWNO pod zebatka: ona ma stale 44 px i top 12, wiec 64 px trzyma ten sam odstep
        // na kazdym ekranie. Wczesniej top skalowal sie razem z tekstem i na szerokim kadrze
        // nakladka odjezdzala na srodek.
        box.style.top     = '64px';
        box.style.padding = Math.round(6 * k) + 'px ' + Math.round(9 * k) + 'px';
        box.style.gap = Math.round(4 * k) + 'px';
        box.innerHTML = '';
        // kolory 1:1 ze starymi kafelkami (card_temps): slonce zolte, cien szaroniebieski
        // Ikony ROWNE (19), mimo ze wartosci nie: cien ma wieksza liczbe, bo to on niesie realna
        // temperature, ale przy ikonach ta sama proporcja wygladala przesadnie — chmura jest
        // dodatkowo szersza od slonca przy tym samym rozmiarze czcionki.
        box.appendChild(row('\u2600\uFE0F', last.slonce.t, last.slonce.stale, '#ffd633', 17, 19));
        box.appendChild(row('\u2601\uFE0F', last.cien.t,   last.cien.stale,   '#dfe7ea', 30, 19));
    }

    function load() {
        $.getJSON('api.php?action=klimat_tiles&_=' + Date.now(), function(d){
            if (d && d.ok) { last = d; paint(); }
        });
    }
    // UWAGA: przywracamy 'grid', nie '' — pusty string KASUJE display z elementu i nakladka
    // wraca do block, przez co ikony ladowaly pod wartosciami zamiast obok nich.
    function visBox() { box.style.display = window.camParkingActive() ? 'flex' : 'none'; }
    v.addEventListener('camswitch', visBox);
    visBox();

    load();
    window.camTempTimer = setInterval(load, 60000);
    if (typeof cardOnUnload === 'function') cardOnUnload(function() { clearInterval(window.camTempTimer); window.camTempTimer = null; });
    if (window.ResizeObserver) new ResizeObserver(paint).observe(wrap);
})();

// POZIOM ALARMU (prawy gorny rog) — helper alarm_zazbrojony 0/1/2, TRZY tarcze obok siebie:
//   zielona przekreslona = 0 rozbrojony, pomaranczowa = 1 NOC (dom zajety, tylko zdarzenia
//   zewnetrzne + zalanie), czerwona = 2 NIKOGO (wszystko, takze ruch wewnatrz).
// Osobny przycisk na poziom (nie cykl) — stan widac bez zgadywania i nie da sie przeskoczyc
// o jeden za daleko. Aktywny swieci pelnym kolorem, pozostale przygaszone.
// Stan czytany z serwera (musi przezyc odswiezenie kiosku i zmiane z telefonu) + poll 15 s.
(function(){
    var v = document.getElementById('cam_parking_sd');
    if (!v) return;
    var wrap = v.parentElement;
    var level = 0;

    // Karta bywa ladowana ponownie (przejscie panelu, loadCard) i blok wykonuje sie znowu.
    // Wczesniej trzy tarcze byly dopinane wprost do `wrap`, bez id i bez sprzatania, wiec KAZDE
    // zaladowanie dokladalo kolejne trzy — niewidocznie, bo leza absolutnie w tym samym miejscu,
    // jedna na drugiej. Klikalo sie w najwyzsza i wszystko wygladalo normalnie. Teraz caly rzed
    // siedzi w JEDNYM kontenerze z id: stary leci do kosza, nowy jest przerysowany w calosci
    // z tablicy `modes`. Kontener jest staticowy, wiec `position:absolute` przyciskow nadal
    // liczy sie wzgledem `wrap` — uklad bez zmian.
    var prevRow = document.getElementById('camAlarmRow');
    if (prevRow) prevRow.remove();
    if (window.camAlarmTimer) clearInterval(window.camAlarmTimer);

    var row = document.createElement('div');
    row.id = 'camAlarmRow';
    wrap.appendChild(row);

    var svgOn  = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>';
    var svgOff = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19.69 14a6.9 6.9 0 0 0 .31-2V5l-8-3-3.16 1.18"/><path d="M4.73 4.73L4 5v7c0 6 8 10 8 10a20.29 20.29 0 0 0 5.62-4.38"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    // kolejnosc = od lewej: rozbrojony, NOC, NIKOGO
    var modes = [
        // Nasycony kolor przy tej samej alfie czyta sie mocniej niz czarne tlo anteny — stad 0.35,
        // zeby tarcza aktywna wygladala rownie lekko jak reszta nakladek.
        {v: 0, bg: 'rgba(67,160,71,0.35)', fg: 'rgba(129,199,132,0.9)', svg: svgOff, title: 'Rozbrojony'},
        {v: 1, bg: 'rgba(251,140,0,0.35)', fg: 'rgba(255,183,77,0.9)',  svg: svgOn,  title: 'NOC — zdarzenia zewnętrzne'},
        {v: 2, bg: 'rgba(229,57,53,0.35)', fg: 'rgba(239,154,154,0.9)', svg: svgOn,  title: 'NIKOGO — wszystko'}
    ];
    var btns = [];


    function load() {
        $.getJSON('api.php?action=helper_state&name=alarm_zazbrojony', function(d){
            if (d && d.ok) { level = parseInt(d.value, 10) || 0; render(); }
        });
    }

    function setLevel(val) {
        for (var i = 0; i < btns.length; i++) btns[i].disabled = true;
        $.post('api.php', {action: 'alarm_set', v: val}, function(d){
            for (var i = 0; i < btns.length; i++) btns[i].disabled = false;
            if (d && d.ok) { level = parseInt(d.value, 10) || 0; render(); }
        }, 'json').fail(function(){
            for (var i = 0; i < btns.length; i++) btns[i].disabled = false;
        });
    }

    // JEDNO miejsce, ktore rysuje rzed — z tablicy `modes` i biezacego `level`. Kazda zmiana stanu
    // (poll, klik) wola render() i pasek powstaje od nowa, zamiast recznego dopinania przyciskow
    // i doklejania poprawek do tych, ktore juz wisza. Trzy przyciski co 15 s to koszt zaniedbywalny,
    // a DOM nie ma jak rozjechac sie ze stanem.
    function render() {
        row.innerHTML = '';
        btns = [];
        modes.forEach(function(m, i) {
            // right liczone od konca, zeby idx 0 (rozbrojony) byl NAJBARDZIEJ Z LEWEJ
            var right = 12 + (modes.length - 1 - i) * 52;
            var act = (m.v === level);
            var b = document.createElement('button');
            b.style.cssText = 'position:absolute;top:12px;right:' + right + 'px;z-index:10;border:none;'
                + 'border-radius:50%;cursor:pointer;width:44px;height:44px;display:flex;align-items:center;'
                + 'justify-content:center;padding:0;transition:background 0.15s,color 0.15s;'
                + '-webkit-tap-highlight-color:transparent;touch-action:manipulation;user-select:none;'
                + 'background:' + (act ? m.bg : 'rgba(0,0,0,0.3)') + ';color:' + (act ? '#fff' : m.fg) + ';';
            b.innerHTML = m.svg;
            b.title = m.title;
            b.onclick = function() { setLevel(m.v); };
            btns.push(b);
            row.appendChild(b);
        });
    }

    // Tarcze alarmu tez nalezą do domu, nie do kamery — poza parkingiem chowamy je razem
    // z temperaturami, zeby nie leżały na obrazie z ogrodu czy basenu. Chowa sie CALY rzed,
    // nie kazdy przycisk po kolei — jeden element, jeden stan.
    function visRow() { row.style.display = window.camParkingActive() ? '' : 'none'; }
    v.addEventListener('camswitch', visRow);

    render();
    visRow();
    load();
    window.camAlarmTimer = setInterval(load, 15000);
    if (typeof cardOnUnload === 'function') cardOnUnload(function() { clearInterval(window.camAlarmTimer); window.camAlarmTimer = null; });
})();

// DRUGA LINIA IKON (prawa strona, pod tarczami alarmu) = PASEK OSTRZEZEN I STANU.
// Miejsce na rozne ikony; kolejnosc od prawej wg waznosci. Dzis: [1] stan sieci Zigbee (zawsze
// widoczny), [2..] awarie grzalek bufora (tylko gdy sa). Na srodku gory obie grupy wchodzilyby
// na siebie na wąskim ekranie iPhone'a — stad prawa strona i osobny wiersz.
//
// AWARIA GRZALEK BUFORA — czerwone ikonki grzalek, ktore maja stan ON, ale nie ciagna
// pradu (wyskoczylo STB, reset tylko fizycznie na glowicy). Telegram czyta tylko user, a kamera
// w kuchni wisi na widoku i widza ja tez domownicy — dlatego to samo ostrzezenie jest tutaj.
// Kreska POD ikona = grzalka dolna, NAD ikona = gorna. Klik = „rozumiem": ikona znika do konca tej
// proby grzania, przy nastepnej (albo po ponownym wykryciu) wraca sama. Zrodlo: api grzalki_alert.
(function(){
    var v = document.getElementById('cam_parking_sd');
    if (!v) return;
    var wrap = v.parentElement;

    // Karta bywa ladowana ponownie (loadCard/swipe), a wtedy ten blok wykonuje sie drugi raz.
    // Bez sprzatania zostawaly DWIE nakladki z osobnymi timerami — ikony sie dublowaly i klik chowal
    // tylko te z nowszej. Dlatego stary box i jego interval leca do kosza przed zbudowaniem nowego.
    var prev = document.getElementById('grzAlertBox');
    if (prev) prev.remove();
    if (window.grzAlertTimer) clearInterval(window.grzAlertTimer);

    var box = document.createElement('div');
    box.id = 'grzAlertBox';
    // Tarcze alarmu maja top:12 i 44 px wysokosci, wiec druga linia zaczyna sie na 64.
    // WSZYSTKIE ikony w obu liniach trzymaja ten sam raster 44x44 (kolka alarmu, kwadrat anteny,
    // trojkaty grzalek) — rozne ksztalty, jedna siatka.
    // row-reverse: pierwszy dopisany element lezy NAJBARDZIEJ Z PRAWEJ — tam siedzi ikona Zigbee.
    box.style.cssText = 'position:absolute;top:64px;right:12px;z-index:11;'
        + 'display:flex;flex-direction:row-reverse;gap:8px;pointer-events:none;';
    wrap.appendChild(box);

    // Dwa NIEZALEZNE sloty w pasku. Wczesniej antena byla rysowana w tej samej funkcji, ktora
    // czysci pasek dla grzalek (`box.innerHTML=''`) i dorysowywana z odpowiedzi async — gdy
    // zapytanie o grzalki bylo wolniejsze albo nie doszlo, antena po prostu znikala („co ktorys
    // refresh nie ma ikony"). Teraz kazdy slot ma wlasne zapytanie i wlasny cykl.
    var slotZb = document.createElement('div');   // stan Zigbee — skrajnie z prawej (row-reverse)
    var slotGrz = document.createElement('div');  // ostrzezenia grzalek
    slotZb.style.cssText = slotGrz.style.cssText = 'display:flex;flex-direction:row-reverse;gap:8px;';
    box.appendChild(slotZb);
    box.appendChild(slotGrz);

    // Znak ostrzegawczy: czerwony trojkat (rogi zaokraglone obrysem w tym samym kolorze, bez bialej
    // ramki), w srodku waska sylwetka grzalki bojlerowej — rurka zagieta w podwojne U + belka kolnierza,
    // bez nozek, zeby nie wchodzila na falke. Falka mowi, ktora to grzalka: NAD grzalka = gorna,
    // POD kolnierzem = dolna. Dwie pelne fale, cienka linia, wysrodkowane na osi trojkata (x=24).
    function svgGrzalka(pos) {
        var falka = '<path d="M18 %Y%q1.5-2 3 0t3 0t3 0t3 0" stroke-width="1.6"/>';
        return '<svg width="44" height="40" viewBox="0 0 48 44" fill="none" stroke="#fff" '
             + 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
             + '<path d="M24 3.5 L45.5 40 H2.5 Z" fill="#e53935" stroke="#e53935" stroke-width="3.5" '
             + 'fill-opacity="0.55" stroke-opacity="0.55"/>'
             + '<path d="M18.9 31V21.7A1.7 1.7 0 0 1 22.3 21.7V27A1.7 1.7 0 0 0 25.7 27V21.7'
             + 'A1.7 1.7 0 0 1 29.1 21.7V31" stroke-width="1.8"/>'
             + '<path d="M17.4 31.6h13.2" stroke-width="2.4"/>'
             + falka.replace('%Y%', pos === 'gora' ? '16' : '36.4')
             + '</svg>';
    }

    // Antena z falami — stan otwarcia sieci Zigbee. Szara = zamknieta, zielona pulsujaca = otwarta
    // (obojetnie czy otworzyl ja user, czy watchdog rejoinu po starcie Z2M).
    // zielony = otwarta, szary = zamknieta, bursztyn z ukosnikiem = Zigbee2MQTT nie zyje
    function svgZigbee(on, offline) {
        var c = offline ? '#f59e0b' : (on ? '#22c55e' : 'rgba(255,255,255,0.45)');
        return '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="' + c + '" '
             + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
             + '<path d="M12 10v10"/>'
             + '<path d="M8.5 8.5a5 5 0 0 1 0-5"/><path d="M15.5 3.5a5 5 0 0 1 0 5"/>'
             + '<path d="M5.5 11a9 9 0 0 1 0-11"/><path d="M18.5 0a9 9 0 0 1 0 11"/>'
             + '<circle cx="12" cy="8" r="1.6" fill="' + c + '" stroke="none"/>'
             + (offline ? '<path d="M3 21L21 3"/>' : '')
             + '</svg>';
    }

    function paintZigbee(st) {
        var b = document.createElement('button');
        b.id = 'zbBtn';
        b.style.cssText = 'pointer-events:auto;border:none;border-radius:10px;cursor:pointer;'
            + 'width:44px;height:44px;display:flex;align-items:center;justify-content:center;padding:0;'
            + 'background:rgba(0,0,0,0.3);'
            + '-webkit-tap-highlight-color:transparent;touch-action:manipulation;user-select:none;'
            + (st.on ? 'animation:zbPulse 1.2s ease-in-out infinite;' : '');
        b.innerHTML = svgZigbee(st.on, st.offline);
        b.title = st.offline
            ? 'Zigbee2MQTT nie działa — koordynator niedostępny. Sterowanie Zigbee stoi.'
            : (st.on
                ? ('Sieć Zigbee OTWARTA na nowe urządzenia' + (st.force ? ' (ręcznie, bez limitu)' : '')
                   + '. Kliknij, żeby zamknąć')
                : 'Sieć Zigbee zamknięta. Kliknij, żeby otworzyć bez limitu czasu');
        b.onclick = function() {
            if (st.offline) return;   // martwy Z2M i tak nie odbierze zadania
            b.disabled = true;
            $.post('api.php', {action: 'zigbee_join_set', v: st.on ? 0 : 1}, function(d){
                // Rysujemy od razu stan docelowy: Z2M aktualizuje retained `bridge/info` dopiero
                // po ~1 s, wiec odpytanie zaraz po kliknieciu zwracaloby STARA wartosc i ikona
                // wygladalaby jak zawieszona. Po 2 s i tak sprawdzamy, co naprawde jest.
                paintZigbee({on: !!(d && d.on), force: !!(d && d.on)});
                setTimeout(loadZigbee, 2000);
            }, 'json').fail(function(){ b.disabled = false; });
        };
        slotZb.innerHTML = '';
        slotZb.appendChild(b);
    }

    function loadZigbee() {
        $.getJSON('api.php?action=zigbee_join_state&_=' + Date.now(), function(st){
            paintZigbee(st && st.ok ? st : {on: false, force: false, offline: true});
        });
    }

    function paint(items) {
        slotGrz.innerHTML = '';
        items.forEach(function(it) {
            var b = document.createElement('button');
            // tlo przezroczyste — ksztaltem jest sam trojkat; cien pod spodem, zeby byl czytelny
            // takze na jasnym kadrze z kamery
            // PULSUJE: to nie jest ikona statusu, tylko rzecz, ktora wymaga wyjscia do kotlowni
            // i wcisniecia resetu na glowicy. Nieruchomy trojkat wtapial sie w kadr kamery.
            b.style.cssText = 'pointer-events:auto;border:none;background:none;cursor:pointer;'
                + 'width:44px;height:44px;display:flex;align-items:center;justify-content:center;padding:0;'
                + 'filter:drop-shadow(0 2px 4px rgba(0,0,0,0.6));'
                + 'animation:grzPulse 1.3s ease-in-out infinite;'
                + '-webkit-tap-highlight-color:transparent;touch-action:manipulation;user-select:none;';
            b.innerHTML = svgGrzalka(it.pos);
            b.title = it.label + (it.mins !== null ? ' — ON bez prądu od ' + it.mins + ' min' : ' — nie pobiera prądu (STB)') + '. Kliknij: rozumiem';
            b.onclick = function() {
                b.disabled = true;
                $.post('api.php', {action: 'grzalki_ack', id: it.id}, function(){ load(); }, 'json')
                 .fail(function(){ b.disabled = false; });
            };
            slotGrz.appendChild(b);
        });
    }

    function load() {
        $.getJSON('api.php?action=grzalki_alert&_=' + Date.now(), function(d){
            if (d && d.ok) paint(d.items || []);
        });
    }

    if (!document.getElementById('zbPulseCss')) {
        var st = document.createElement('style');
        st.id = 'zbPulseCss';
        st.textContent = '@keyframes zbPulse{0%,100%{opacity:1}50%{opacity:.35}}';
        document.head.appendChild(st);
    }
    if (!document.getElementById('grzPulseCss')) {
        var st2 = document.createElement('style');
        st2.id = 'grzPulseCss';
        st2.textContent = '@keyframes grzPulse{0%,100%{transform:scale(1);opacity:1}'
            + '50%{transform:scale(1.14);opacity:.55}}';
        document.head.appendChild(st2);
    }

    load();
    loadZigbee();
    // Pasek ostrzezen mowi o DOMU (siec Zigbee, grzalki bufora), a nie o tym, co widac w kadrze —
    // wiec poza parkingiem chowa sie tak samo jak pogoda i tarcze alarmu. Na pozostalych kamerach
    // zostaja tylko rzeczy dotyczace samego obrazu: zebatka, jakosc SD/HD i dzwiek.
    function visAlert() { box.style.display = window.camParkingActive() ? '' : 'none'; }
    v.addEventListener('camswitch', visAlert);
    visAlert();

    window.grzAlertTimer = setInterval(function(){ load(); loadZigbee(); }, 15000);
    if (typeof cardOnUnload === 'function') cardOnUnload(function() { clearInterval(window.grzAlertTimer); window.grzAlertTimer = null; });
})();
</script>

<?php
// AKTYWACJA DZWIEKU — kolo zeszlo z tego kadru na SRODEK KADRU DZWONKA
// (card_camera_doorbell.inc.php, blok "AKTYWACJA DZWIEKU"). Dzwiek, ktory jest zablokowany,
// dotyczy dzwonka, wiec i wskaznik siedzi na dzwonku. Sam gest pozostaje dowolny — kazde
// dotkniecie ekranu odblokowuje audio (index/doorbell.inc.js + mostek w ui.php).
?>
