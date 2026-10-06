// Panel/tab/card — ladowanie layoutu i renderowanie

var currentPanel = null;
var currentTab   = null;
var renderGen    = 0;
var cardTimers   = {};
var DEFAULT_REFRESH = 30000;

// Double-tap potwierdzenie usuwania — zastepuje native confirm() (ktore reflowuje layout w Safari).
// Pierwszy klik: tekst zmienia sie na "Kliknij aby potwierdzic" przez 3s. Drugi klik w oknie → odpal callback.
// Uzycie: <button onclick="tymosConfirmDelete(this, function(){ deleteX(id); })">Usun</button>
function tymosConfirmDelete(btn, onConfirm, confirmText) {
    var $b = $(btn);
    if ($b.data('tcConfirming')) {
        clearTimeout($b.data('tcTimeout'));
        $b.removeData('tcConfirming').removeData('tcTimeout');
        onConfirm();
        return;
    }
    $b.data('tcConfirming', true).data('tcOrigHtml', $b.html());
    $b.html(confirmText || 'Kliknij aby potwierdzić');
    var t = setTimeout(function() {
        if ($b.data('tcConfirming')) {
            $b.html($b.data('tcOrigHtml')).removeData('tcConfirming').removeData('tcTimeout').removeData('tcOrigHtml');
        }
    }, 3000);
    $b.data('tcTimeout', t);
}

function loadPanel(panel, tab) {
    tab = tab || null;
    closeWebRTC();
    var prevPanel = currentPanel;
    currentPanel = panel;
    renderGen++;
    $.each(cardTimers, function(_, id) { clearTimeout(id); });
    cardTimers = {};

    // Gorny pasek ikon paneli nie jest juz potrzebny NIGDZIE: do admina wchodzi sie zebatka
    // z kamery, a z powrotem domkiem w pasku zakladek. Element zostaje w DOM (loadPanel czyta
    // z niego stan aktywnego panelu), ale jest stale ukryty.
    $('body').addClass('menu-off');

    $('#divMenu .menu-item').each(function() {
        var isActive = $(this).data('panel') === panel;
        $(this).toggleClass('active', isActive);
        $(this).css('color', (!isActive && $(this).data('color')) ? $(this).data('color') : '');
    });

    var url = 'cnt.php?panel=' + encodeURIComponent(panel);
    if (tab) url += '&tab=' + encodeURIComponent(tab);

    $.getJSON(url, function(data) {
        currentTab = data.tab;
        try { localStorage.setItem('tymos_panel', panel); localStorage.setItem('tymos_tab', data.tab); } catch(e) {}
        renderSubMenu(panel, data.tabs, data.tab);
        renderMain(panel, data.tab, data.layout);
        // Panel_admin: odtworz zapisany stan (dev detail, filter, sort).
        // isSwitching=true gdy user klika subtab w tym samym panelu — pomin id (czysta lista z filter).
        if (panel === 'admin' && typeof _adminRestoreState === 'function') {
            _adminRestoreState(data.tab, prevPanel === 'admin');
        }
    });
}

function renderSubMenu(panel, tabs, activeTab) {
    var $el = $('#divSubMenu');
    var multi = tabs && tabs.length > 1;
    // Poza HOME pasek zakladek pokazujemy ZAWSZE, nawet przy jednej zakladce — siedzi w nim domek
    // i bez niego nie byloby czym wrocic (gorny pasek paneli jest ukryty na stale).
    if (panel === 'home' && !multi) { $el.addClass('hidden'); $('body').removeClass('subm-on'); return; }
    $el.removeClass('hidden').empty();
    $('body').addClass('subm-on');
    if (panel !== 'home') {
        $('<div>').addClass('tab-item').css('font-size', '18px')
            .attr({title: 'Powrót', 'aria-label': 'Powrót'})
            .text('←')
            .on('click', function() { loadPanel('home'); })
            .appendTo($el);
    }
    $.each(tabs, function(_, t) {
        $('<div>').addClass('tab-item' + (t.name === activeTab ? ' active' : ''))
            .attr({title: t.title || t.label, 'aria-label': t.title || t.label})
            .text(t.label)
            .on('click', function() { loadPanel(panel, t.name); })
            .appendTo($el);
    });
}

function _renderCard(panel, tab, card) {
    var cardName    = (typeof card === 'object') ? card.name : card;
    var cardId      = (typeof card === 'object' && card.id) ? card.id : cardName;
    var cardParams  = (typeof card === 'object') ? (card.params || null) : null;
    var cardRefresh = (typeof card === 'object' && card.refresh !== undefined) ? card.refresh : DEFAULT_REFRESH;
    var $cDiv = $('<div>').addClass('tymos-card').attr('id', 'card_' + cardId)
        .html('<div class="card-sw"><div class="card-loading"></div></div>');
    var colSpan = (typeof card === 'object') ? (card.cols || card.colSpan || 1) : 1;
    var rowSpan = (typeof card === 'object') ? (card.rows || card.rowSpan || 1) : 1;
    if (typeof card === 'object') {
        if (card.minWidth)  $cDiv.css('minWidth',  card.minWidth);
        if (card.minHeight) $cDiv.css('minHeight', card.minHeight);
        if (colSpan > 1)    $cDiv.css('gridColumn', 'span ' + colSpan);
        if (rowSpan > 1)    $cDiv.css('gridRow',    'span ' + rowSpan);
    }
    if (typeof card === 'object' && card.maxWidth) $cDiv.css('maxWidth', card.maxWidth);
    if (typeof card === 'object' && card.swipe) {
        var swipeNorm = [];
        $.each(card.swipe, function(_, item) {
            if (typeof item === 'string') {
                swipeNorm.push({ panel: panel, tab: tab, card: item, params: null });
            } else {
                swipeNorm.push({ panel: item.panel || panel, tab: item.tab || tab, card: item.card, params: item.params || null });
            }
        });
        var swipeInitIdx = (typeof card === 'object' && card.swipeIdx !== undefined) ? card.swipeIdx : 0;
        $cDiv.attr('data-swipe', JSON.stringify(swipeNorm)).attr('data-swipe-idx', String(swipeInitIdx)).attr('data-swipe-default-idx', String(swipeInitIdx));
    }
    loadCard(panel, tab, cardName, cardRefresh, null, cardId, cardParams);
    return $cDiv;
}

function renderMain(panel, tab, layout) {
    // Karty biezacego panelu zaraz znikna — najpierw daj im zamknac timery, listenery i strumienie.
    // `closeWebRTC()` w loadPanel() zostaje jako drugi bezpiecznik, gdyby jakas karta nie
    // zarejestrowala sprzatania.
    var $main = $('#divMain');
    winHide();   // otwarte okno nalezy do karty, ktora zaraz zniknie
    cardRunCleanup($main[0]);
    $main.empty();
    if (!layout || !layout.sections) return;

    $.each(layout.sections, function(_, section) {
        var cols = section.minColWidth
            ? 'repeat(auto-fill, minmax(' + section.minColWidth + ', 1fr))'
            : section.cols
                ? 'repeat(' + section.cols + ', 1fr)'
                : 'repeat(auto-fill, minmax(max(340px, calc((100% - 12px) / 2)), 1fr))';

        var $sDiv = $('<div>').addClass('tymos-section').css('gridTemplateColumns', cols);
        if (section.width)    $sDiv.css('width', section.width);
        if (section.maxWidth) $sDiv.css('maxWidth', section.maxWidth);

        if (section.columns) {
            // 4 px miedzy kolumnami — tyle samo, co miedzy kartami w kolumnie nizej. Bylo 8 px
            // i przerwa pionowa miedzy widgetami wychodzila wezsza niz pozioma.
            $sDiv.css({display:'flex',flexWrap:'wrap',gap:'4px'});
            var colMin = section.colMinWidth || '340px';
            $.each(section.columns, function(_, colCards) {
                // calc(50% - 2px) bo polowa gapu, ktory dzieli dwie kolumny
                var $col = $('<div>').css({display:'flex',flexDirection:'column',gap:'4px',flex:'1 1 calc(50% - 2px)',minWidth:colMin});
                $.each(colCards, function(_, card) {
                    var $cDiv = _renderCard(panel, tab, card).css('width','100%');
                    $col.append($cDiv);
                });
                $sDiv.append($col);
            });
        } else {
            $.each(section.cards, function(_, card) {
                var $cDiv = _renderCard(panel, tab, card);
                $sDiv.append($cDiv);
            });
        }

        $main.append($sDiv);
    });
}
