// Swipe gestures — touch + Mac trackpad
// Film strip: karta biezaca i nowa leza obok siebie w $strip (display:flex).
// Podczas gestu caly strip przesuwa sie — nowy wykres wjezdza jednoczesnie ze starym.
//
// STREFY SWIPE: normalnie cala karta (.tymos-card). Od 2026-08-17 dziala tez na
// WEWNETRZNYM kontenerze z klasa .sw-zone — dzieki temu mozna swipowac sam wykres
// w rozwinietym divie, nie ruszajac kafelkow karty. Taki kontener musi miec:
//   id="card_<divId>", klase .sw-zone, w srodku .card-sw,
//   oraz .data('swipe', [...]) i atrybuty data-swipe-idx / data-swipe-default-idx.
// closest() bierze najblizszy, wiec strefa wewnetrzna wygrywa z karta nadrzedna.
//
// .sw-stop = BARIERA GESTU (od 2026-08-18). Klasa dla rozwijanych divow (div_show_hide): sama nie ma
// listy swipe, wiec gest ktory w nia trafi po prostu umiera zamiast wedrowac do karty nadrzednej.
// Bez tego przeciagniecie po widgecie W SRODKU rozwinietego diva przewijalo widget PRZED divem —
// czyli rozwijanie sklejalo dwie niezalezne rzeczy w jedna. Zasada: kazdy wykres swipuje sie sam.
var SW_SEL = '.tymos-card, .sw-zone, .sw-stop';

// Ostatnia pozycja swipe per divId. Karty odswiezaja sie co 30 s i odtwarzaja swoje wewnetrzne
// strefy od zera — bez tej mapy kazde odswiezenie cofaloby wykres na "dzis"/"teraz" w trakcie ogladania.
window.swIdx = window.swIdx || {};

var _swTouch = null;
var _swWheelAcc = 0, _swWheelEl = null, _swWheelTimer = null, _swWheelNext = null;

function _swLoadingHtml(swH) {
    return '<div style="height:' + swH + 'px"></div>';
}

function _swPrepareNext(el, goRight) {
    var swipeData = $(el).data('swipe');
    if (!swipeData || !swipeData.length) return null;
    var idx = parseInt($(el).attr('data-swipe-idx') || '0');
    var ni  = goRight ? idx - 1 : idx + 1;
    if (ni < 0 || ni >= swipeData.length) return null;
    var t = swipeData[ni];

    var $sw = $(el).find('.card-sw').first();
    if (!$sw.length) return null;
    var W   = el.clientWidth;
    var H   = $sw[0].getBoundingClientRect().height;
    var pad = parseInt(window.getComputedStyle(el).paddingLeft) || 0;

    var cellCss = { flex: '0 0 ' + W + 'px', 'box-sizing': 'border-box',
                    padding: pad + 'px', overflow: 'hidden', 'min-height': H + 'px' };
    // Klon SLUZY TYLKO DO ANIMACJI — przez cardSetHtml, zeby jQuery nie ewaluowalo jego
    // skryptow. Ten klon i tak leci do kosza razem z paskiem, wiec nic z niego nie ma sie wykonac.
    var $cellCur = $('<div>').css(cellCss);
    cardSetHtml($cellCur[0], $sw.html());
    var $cellNew = $('<div>').css(cellCss).html(_swLoadingHtml(H - 2 * pad));

    var startX = goRight ? -W : 0;
    var $strip = $('<div>').addClass('sw-strip').css({
        position: 'absolute', top: 0, left: 0,
        width: (2 * W) + 'px', height: '100%', display: 'flex',
        transform: 'translateX(' + startX + 'px)', 'z-index': 2
    });
    goRight ? $strip.append($cellNew).append($cellCur) : $strip.append($cellCur).append($cellNew);

    $(el).css({ position: 'relative', overflow: 'hidden' });
    $sw.css('visibility', 'hidden');
    $(el).append($strip);

    var url = 'cnt.php?panel=' + encodeURIComponent(t.panel)
            + '&tab='        + encodeURIComponent(t.tab)
            + '&card='       + encodeURIComponent(t.card);
    if (t.params) $.each(t.params, function(k, v) { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v); });
    var pf = { html: null, done: false, onDone: null };
    $strip.data({ pf: pf, cellNew: $cellNew, goRight: goRight, W: W });
    $.get(url).done(function(html) {
        pf.html = html; pf.done = true;
        // Wstawiane, gdy komorka JUZ wisi w dokumencie — `.html()` odpalilby skrypty tutaj,
        // a `execScripts()` w promote() zrobilby to po raz drugi.
        cardSetHtml($cellNew[0], html);
        if (pf.onDone) pf.onDone(html);
    }).fail(function() { pf.done = true; if (pf.onDone) pf.onDone(null); });
    return $strip;
}

function _swipeCommit(el, goRight, $strip) {
    var id = el.id;
    if (!id || !id.startsWith('card_')) return;
    var divId = id.slice(5);
    if ($(el).data('sw-busy')) { _swipeCancel(el, $strip); return; }

    var swipeData = $(el).data('swipe');
    var targetPanel, targetTab, targetCard, targetParams, newIdx;
    if (swipeData && swipeData.length) {
        var idx = parseInt($(el).attr('data-swipe-idx') || '0');
        newIdx = goRight ? idx - 1 : idx + 1;
        if (newIdx < 0 || newIdx >= swipeData.length) { _swipeCancel(el, $strip); return; }
        var t = swipeData[newIdx];
        targetPanel = t.panel; targetTab = t.tab; targetCard = t.card; targetParams = t.params || null;
    } else {
        _swipeCancel(el, $strip); return;
    }

    clearTimeout(cardTimers[divId]);
    var defaultIdx = parseInt($(el).attr('data-swipe-default-idx') || '0');
    var cardRefresh = (newIdx === defaultIdx) ? DEFAULT_REFRESH : 0;
    var gen = renderGen;

    if (!$strip || !$strip.length) {
        $strip = _swPrepareNext(el, goRight);
        if (!$strip) return;
    }

    var pf = $strip.data('pf') || { html: null, done: false, onDone: null };
    var W = $strip.data('W') || el.clientWidth;
    var finalX = goRight ? 0 : -W;

    $(el).data('sw-busy', true);

    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            $strip.css({ transition: 'transform 0.1s ease', transform: 'translateX(' + finalX + 'px)' });
            setTimeout(function() {
                var $cellNew = $strip.data('cellNew');
                var $sw = $(el).find('.card-sw').first();
                if ($cellNew && $cellNew.length) $cellNew.detach();
                // Stara tresc karty odchodzi razem z paskiem — najpierw sprzatanie, potem remove.
                cardRunCleanup(el);
                $strip.remove();
                if ($sw.length) $sw.remove();
                $(el).css('overflow', '');

                function promote() {
                    if (renderGen !== gen) return;
                    if ($cellNew && $cellNew.length) {
                        $cellNew.css({ flex: '', 'box-sizing': '', padding: '', 'min-height': '', overflow: '' })
                                .addClass('card-sw');
                        $(el).append($cellNew);
                    } else {
                        var $fresh = $('<div>').addClass('card-sw');
                        $(el).append($fresh);
                        cardSetHtml($fresh[0], pf.html || '');
                    }
                    execScripts(el);
                    $(el).css('position', '').removeData('sw-busy');
                    if (newIdx !== null) {
                        $(el).attr('data-swipe-idx', String(newIdx));
                        window.swIdx[divId] = newIdx;
                        // hook dla kart, ktore maja WLASNE przyciski nawigacji obok swipe (np. ±24 h
                        // w card_pstryk_load) i musza po gescie odswiezyc ich stan
                        if (typeof window.swOnIdx === 'function') window.swOnIdx(divId, newIdx);
                    }
                    if (cardRefresh > 0) cardTimers[divId] = setTimeout(function() {
                        if (renderGen !== gen || !$('#card_' + divId).length) return;
                        loadCard(targetPanel, targetTab, targetCard, cardRefresh, null, divId, targetParams);
                    }, cardRefresh);
                }

                if (pf.done) { promote(); }
                else {
                    var $ph = $('<div>').addClass('card-sw').html(_swLoadingHtml(100));
                    $(el).append($ph);
                    pf.onDone = function() { $ph.remove(); promote(); };
                }
            }, 120);
        });
    });
}

function _swipeCancel(el, $strip) {
    if ($strip && $strip.length) {
        var goRight = $strip.data('goRight');
        var W = $strip.data('W') || el.clientWidth;
        $strip.css({ transition: 'transform 0.3s cubic-bezier(0.25,0.46,0.45,0.94)',
                     transform: 'translateX(' + (goRight ? -W : 0) + 'px)' });
        setTimeout(function() {
            $strip.remove();
            $(el).find('.card-sw').first().css('visibility', '');
            $(el).css({ overflow: '', position: '' });
        }, 320);
    } else {
        var $sw = $(el).find('.card-sw').first();
        if ($sw.length) {
            $sw.css({ transition: 'transform 0.3s cubic-bezier(0.25,0.46,0.45,0.94)', transform: 'translateX(0)' });
            setTimeout(function() { $sw.css({ transition: '', transform: '' }); $(el).css('position', ''); }, 320);
        } else { $(el).css('position', ''); }
    }
}

// touch
document.addEventListener('touchstart', function(e) {
    var el = $(e.target).closest(SW_SEL)[0];
    if (!el || $(el).data('sw-busy')) return;
    var t = e.touches[0];
    _swTouch = { x: t.clientX, y: t.clientY, el: el, dir: null, $next: null };
}, { passive: true });

document.addEventListener('touchmove', function(e) {
    if (!_swTouch) return;
    var t  = e.touches[0];
    var dx = t.clientX - _swTouch.x;
    var dy = t.clientY - _swTouch.y;
    if (_swTouch.dir === null) {
        if (Math.abs(dx) < 6 && Math.abs(dy) < 6) return;
        _swTouch.dir = Math.abs(dx) >= Math.abs(dy) ? 'h' : 'v';
        if (_swTouch.dir === 'h') _swTouch.$next = _swPrepareNext(_swTouch.el, dx > 0);
    }
    if (_swTouch.dir !== 'h') return;
    e.preventDefault();
    if (_swTouch.$next && _swTouch.$next.length) {
        var baseX = _swTouch.$next.data('goRight') ? -(_swTouch.$next.data('W') || _swTouch.el.clientWidth) : 0;
        _swTouch.$next.css({ transition: 'none', transform: 'translateX(' + (baseX + dx) + 'px)' });
    }
}, { passive: false });

document.addEventListener('touchend', function(e) {
    if (!_swTouch) return;
    var sw = _swTouch; _swTouch = null;
    if (sw.dir !== 'h') return;
    var dx = e.changedTouches[0].clientX - sw.x;
    if (Math.abs(dx) >= (sw.$next && sw.$next.data('W') || sw.el.clientWidth || 320) * 0.1) {
        _swipeCommit(sw.el, dx > 0, sw.$next);
    } else {
        _swipeCancel(sw.el, sw.$next);
    }
});

document.addEventListener('touchcancel', function() {
    if (!_swTouch) return;
    var sw = _swTouch; _swTouch = null;
    if (sw.dir === 'h') _swipeCancel(sw.el, sw.$next);
});

// Mac trackpad
document.addEventListener('wheel', function(e) {
    var el = $(e.target).closest(SW_SEL)[0];
    if (!el) return;
    if (Math.abs(e.deltaX) <= Math.abs(e.deltaY)) return;
    e.preventDefault();
    if (_swWheelEl && _swWheelEl !== el) {
        _swipeCancel(_swWheelEl, _swWheelNext);
        _swWheelAcc = 0; _swWheelNext = null;
    }
    _swWheelEl = el;
    _swWheelAcc += e.deltaX;
    var goRight = _swWheelAcc < 0;
    var W = el.clientWidth;
    var visualDx = Math.max(-W, Math.min(W, -_swWheelAcc / 2));

    if (!_swWheelNext && Math.abs(_swWheelAcc) > 20) {
        _swWheelNext = _swPrepareNext(el, goRight);
    }
    if (_swWheelNext && _swWheelNext.length) {
        var baseX = _swWheelNext.data('goRight') ? -W : 0;
        _swWheelNext.css({ transition: 'none', transform: 'translateX(' + (baseX + visualDx) + 'px)' });
    }

    clearTimeout(_swWheelTimer);
    _swWheelTimer = setTimeout(function() {
        var acc = _swWheelAcc, elem = _swWheelEl, $next = _swWheelNext;
        _swWheelAcc = 0; _swWheelEl = null; _swWheelNext = null;
        if (!elem) return;
        if (Math.abs(acc / 2) >= ($next && $next.data('W') || elem.clientWidth || 320) * 0.1) {
            _swipeCommit(elem, acc < 0, $next);
        } else {
            _swipeCancel(elem, $next);
        }
    }, 120);
}, { passive: false });
