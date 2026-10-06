// Card loading + script execution

// ---- SPRZATANIE PO KARCIE ----------------------------------------------------------------
// Karta, ktora znika (przejscie panelu, przeladowanie karty, swipe), musi miec czym zamknac
// swoje timery, listenery na `document`/`window` i PeerConnectiony. Wczesniej kazda karta
// pilnowala tego sama przez `window.<x>Timer` — i wystarczylo zapomniec, zeby zostawic
// tykajacy poller albo listener trzymajacy caly stary closure. Teraz robi to framework:
// karta rejestruje sprzatanie razem z elementem, do ktorego nalezy, a `cardRunCleanup()`
// wola je PRZED podmiana tresci.
window.cardCleanup = window.cardCleanup || [];   // [{el, fn}]

function cardOnCleanup(el, fn) {
    window.cardCleanup.push({ el: el, fn: fn });
}

// Wersja bez podawania elementu — do uzycia W SKRYPCIE KARTY, na jego poziomie glownym.
// `window.cardRoot` ustawia execScripts() na czas wykonania skryptu, wiec karta nie musi
// szukac wlasnego kontenera (nie da sie tego zrobic przez `document.currentScript`, bo skrypt
// jest odpalany z osobnego wezla dopietego do <body>).
// UWAGA: wolane z callbacka async dostanie `cardRoot` = null, a takie sprzatanie leci przy
// NAJBLIZSZYM sprzataniu czegokolwiek. Rejestrowac na poziomie glownym skryptu karty.
function cardOnUnload(fn) {
    cardOnCleanup(window.cardRoot || null, fn);
}

// Wola i wyrzuca kazde sprzatanie, ktore nalezy do wymienianego fragmentu `root` albo do DOM,
// ktorego juz nie ma (`isConnected` = false). Wpis bez elementu (`el` = null) leci przy
// najblizszym sprzataniu — lepiej posprzatac za duzo niz zostawic tykajacy timer. Reszta
// zostaje w rejestrze.
function cardRunCleanup(root) {
    window.cardCleanup = window.cardCleanup.filter(function(e) {
        var mine = !e.el || !e.el.isConnected || (root && root.contains && root.contains(e.el));
        if (mine) { try { e.fn(); } catch (err) {} }
        return !mine;
    });
}

// ---- WSTAWIANIE HTML KARTY --------------------------------------------------------------
// JEDYNA droga wstawiania tresci karty. Nie uzywac jQuery `.html()`: ono samo ewaluuje bloki
// <script>, zostawiajac je w DOM, wiec `execScripts()` odpalalo je po raz drugi (kazdy skrypt
// karty wykonywal sie dwukrotnie — stad podwojne sesje WHEP i podwojone nakladki).
// `innerHTML` skryptow nie odpala. Typ jest przestawiany na wlasny, bo wezel <script> wstawiony
// przez innerHTML nie jest "already started" i potrafi wystrzelic sam, gdy karta zostanie
// PRZENIESIONA w DOM (swipe robi detach + append). Z obcym typem nie odpali go ani przegladarka,
// ani jQuery — zostaje wylacznie `execScripts()`.
function cardSetHtml(el, html) {
    $(el).empty();
    el.innerHTML = html;
    [].forEach.call(el.querySelectorAll('script'), function(sc) { sc.type = 'text/tymos-card'; });
    // Przeladowanie karty zmiotlo element pokazany w oknie — bez tego zostalaby sama szyba.
    if (_win && !document.body.contains(_win.el)) winHide();
}

function loadCard(panel, tab, card, refresh, slideFrom, divId, params) {
    divId = divId || card;
    if (refresh === undefined) refresh = DEFAULT_REFRESH;
    clearTimeout(cardTimers[divId]);
    var gen = renderGen;
    var url = 'cnt.php?panel=' + encodeURIComponent(panel)
            + '&tab='        + encodeURIComponent(tab)
            + '&card='       + encodeURIComponent(card);
    if (params) {
        $.each(params, function(k, v) { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v); });
    }

    $.get(url, function(html) {
        if (renderGen !== gen) return;
        var $el = $('#card_' + divId);
        if (!$el.length) return;
        var $sw = $el.find('.card-sw');
        if (slideFrom !== 'fade') {
            var h = $el.outerHeight();
            if (h > 0) $el.css('minHeight', h + 'px');
        }
        if (slideFrom && slideFrom !== 'fade' && $sw.length) {
            $sw.css({ transition: 'none', transform: 'translateX(' + (slideFrom === 'left' ? '-100%' : '100%') + ')' });
        }
        if (slideFrom === 'fade' && $sw.length) $sw.css({ transition: 'none', transform: 'translateX(0)', opacity: '0', background: '' });
        // Stara tresc karty odchodzi — najpierw daj jej zamknac timery i listenery.
        cardRunCleanup($el[0]);
        cardSetHtml($sw.length ? $sw[0] : $el[0], html);
        execScripts($el[0]);
        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                if (slideFrom === 'fade' && $sw.length) {
                    $sw.css({ transition: 'opacity 0.3s ease', opacity: '1' });
                    setTimeout(function() {
                        $sw.css({ transition: '', opacity: '', 'min-height': '' });
                    }, 350);
                } else {
                    $el.css('minHeight', '');
                    if (slideFrom && $sw.length) {
                        $sw.css({ transition: 'transform 0.22s ease', transform: 'translateX(0)' });
                        setTimeout(function() { $sw.css({ transition: '', transform: '' }); }, 250);
                    }
                }
            });
        });
        if (refresh > 0) {
            cardTimers[divId] = setTimeout(function() {
                if (renderGen !== gen) return;
                if ($('#card_' + divId).length) loadCard(panel, tab, card, refresh, null, divId, params);
            }, refresh);
        }
    }).fail(function() {
        if (renderGen !== gen) return;
        $('#card_' + divId).html('<div class="card-error">Blad ladowania</div>');
        if (refresh > 0) {
            cardTimers[divId] = setTimeout(function() {
                if (renderGen !== gen) return;
                if ($('#card_' + divId).length) loadCard(panel, tab, card, refresh, null, divId, params);
            }, refresh);
        }
    });
}

// ---- OKNO NAD PANELEM (2026-09-04) -----------------------------------------------------
// Rozwiniecia (wykresy reku, raporty pstryka, gear/raporty ogrzewania, konfiguracja podlewania)
// nie rozpychaja juz karty w dol, tylko wyskakuja w OKNIE na srodku ekranu, nad szara szyba
// (#winGlass w ui.php), ktora przykrywa i blokuje reszte UI.
// Element NIE jest przenoszony w DOM — dostaje klase .win (position:fixed, style.inc.css) i zostaje
// tam, gdzie byl. Dzieki temu delegowane handlery karty ($k.on(...)) i pollery szukajace kontrolek
// przez $k.find() dzialaja bez zmian. Szerokosc = szerokosc POJEDYNCZEGO widgetu (iPhone: caly
// ekran, iPad/Mac: pol panelu), bo cala tresc rozwiniec byla robiona pod te szerokosc.
// Zamkniecie: klik w szybe, X w prawym gornym rogu okna, Escape, zmiana panelu (renderMain),
// przeladowanie karty-wlasciciela (cardSetHtml). `onClose` = sprzatanie karty po zamknieciu.
var _win = null;   // {el, onClose}
var _winGlassEl = null;

// Szyba MUSI lezec w tym samym kontekscie warstw co okno. iOS robi z przewijanego #divMain
// osobny kontekst (compositing), wiec szyba zostawiona w <body> przykrywala okno mimo nizszego
// z-index (Mac byl OK). Stad: przy kazdym pokazie szyba jedzie do #divMain; renderMain robi
// $main.empty(), wiec trzymamy referencje i doczepiamy z powrotem. Klik natywnie (onclick),
// bo jQuery .empty() zdejmuje handlery .on() z usuwanych elementow.
function _winGlass() {
    var g = _winGlassEl || document.getElementById('winGlass');
    if (!g) return null;
    _winGlassEl = g;
    var main = document.getElementById('divMain');
    if (main && g.parentNode !== main) main.appendChild(g);
    g.onclick = function() { winHide(); };
    return g;
}

function winCardWidth(shown) {
    var w = 0;
    $('.tymos-card').each(function() {
        if (w || this === shown || !$(this).is(':visible')) return;
        // karty w sekcji `columns` (kolumna = div bez klasy) maja szerokosc pojedynczego widgetu;
        // karty bezposrednio w .tymos-section moga byc rozciagniete (cols: 2)
        if (this.parentNode && !$(this.parentNode).hasClass('tymos-section')) w = $(this).outerWidth();
    });
    return w || Math.min(document.documentElement.clientWidth - 4, 446);
}

function winShow(el, onClose) {
    if (!el) return;
    if (_win && _win.el !== el) winHide();
    _win = { el: el, onClose: onClose || null };
    el.style.width   = winCardWidth(el) + 'px';
    el.style.display = 'block';
    // .sw-stop: gest swipe w oknie umiera w oknie, nie przewija karty pod szyba
    $(el).addClass('win sw-stop');
    if (!$(el).children('.win-x').length) {
        $('<button class="win-x" title="Zamknij">\u00D7</button>').prependTo(el)
            .on('click', function(e) { e.stopPropagation(); winHide(); });
    }
    var g = _winGlass();
    if (g) g.style.display = 'block';
}

function winHide() {
    if (!_win) return;
    var w = _win; _win = null;
    if (_winGlassEl) _winGlassEl.style.display = 'none';
    $(w.el).removeClass('win').children('.win-x').remove();
    w.el.style.display = 'none';
    w.el.style.width   = '';
    if (w.onClose) { try { w.onClose(); } catch (e) {} }
}

$(document).on('keydown', function(e) { if (e.key === 'Escape') winHide(); });

// Przelacznik rozwijanej czesci karty.
//
// Stan trzymany w window.cardOpen_<divId>, zeby przetrwal auto-refresh karty (co 30 s)
// oraz przelaczanie zakladek. Zwiniete = loadCard BEZ params, czyli PHP nie generuje
// wykresow wcale (patrz card_expanded() w inc/panels.inc.php). Rozwiniete = expand=1,
// ktore loadCard sam przekazuje dalej przy kazdym kolejnym odswiezeniu.
//
// card  - nazwa karty, gdy inna niz divId
// refresh - jak w loadCard; pomin, zeby uzyc DEFAULT_REFRESH
function cardToggle(divId, card, refresh) {
    var key = 'cardOpen_' + divId;
    window[key] = !window[key];
    loadCard(currentPanel, currentTab, card || divId, refresh, 'fade', divId,
             window[key] ? { expand: 1 } : null);
}

// Czy dana karta jest rozwinieta — do uzycia w JS karty przy odtwarzaniu stanu.
function cardIsOpen(divId) {
    return !!window['cardOpen_' + divId];
}

// Jedyne miejsce, ktore odpala skrypty kart. Wezel po wykonaniu LECI Z DOM — inaczej moglby
// wystrzelic drugi raz przy przenoszeniu karty (swipe) albo przy kolejnym `execScripts`.
function execScripts(container) {
    $(container).find('script').each(function() {
        var node = this;
        window.cardRoot = container;   // zeby karta wiedziala, do czego nalezy (cardOnUnload)
        $('<script>').text($(node).text()).appendTo('body').remove();
        window.cardRoot = null;
        if (node.parentNode) node.parentNode.removeChild(node);
    });
}
