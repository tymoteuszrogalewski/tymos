// TymOS panel_admin — wspolne JS helpery: fuzzy search, searchable select (static/lazy),
// initFilter (auto-search po typing), adminLoadTab (wrapper na loadCard dla admin).

function _depolish(s) {
  var map = {'ą':'a','ć':'c','ę':'e','ł':'l','ń':'n','ó':'o','ś':'s','ź':'z','ż':'z','Ą':'A','Ć':'C','Ę':'E','Ł':'L','Ń':'N','Ó':'O','Ś':'S','Ź':'Z','Ż':'Z'};
  return s.replace(/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/g, function(c){ return map[c]||c; });
}

function _fuzzyMatch(text, query) {
  var t = text.toLowerCase(), td = _depolish(t);
  var words = query.toLowerCase().split(/\s+/).filter(function(w){return w;});
  return words.every(function(w){ var wd = _depolish(w); return t.indexOf(w) >= 0 || td.indexOf(wd) >= 0; });
}

// Wrapper na loadCard dla panel_admin — zamiast starego loadTab(tab, params).
// Merge params z localStorage: filter/sort persist, id tylko gdy w detail.
function adminLoadTab(tab, params) {
  var merged = params ? $.extend({}, params) : {};
  try {
    var state = JSON.parse(localStorage.getItem('admin_state') || '{}');
    var prev = state[tab] || {};
    // Wejscie w detail: zachowaj filter/sort z listy
    if (merged.id && !('filter' in merged) && prev.filter) merged.filter = prev.filter;
    if (merged.id && !('sort' in merged) && prev.sort) merged.sort = prev.sort;
    // Powrot do listy (brak id/filter/sort w params): przywroc filter/sort z poprzedniego stanu
    if (!merged.id && !('filter' in merged) && prev.filter) merged.filter = prev.filter;
    if (!merged.id && !('sort' in merged) && prev.sort) merged.sort = prev.sort;
    // Czysty pusty filter = brak filter
    if (merged.filter === '') delete merged.filter;
    state[tab] = merged;
    localStorage.setItem('admin_state', JSON.stringify(state));
  } catch(e) {}
  loadCard('admin', tab, 'main', 0, null, null, Object.keys(merged).length ? merged : null);
  // Scroll to top po zmianie widoku (wejscie detail / powrot do listy / zapis akcji)
  setTimeout(function() {
    var main = document.getElementById('divMain');
    if (main) main.scrollTop = 0;
    window.scrollTo(0, 0);
  }, 30);
}

// Wywolywane przez tymos loadPanel po renderMain — odtworz zapisane params.
// isSwitching=true gdy klik subtab (same panel) — wtedy pomin id (czysta lista z filter).
// isSwitching=false gdy cold start / Cmd+R — odtworz pelny stan (z id jesli byl w detail).
// Uwaga: renderGen++ unieważnia pierwszy loadCard (bez params) wywolany przez renderMain,
// zeby drugi loadCard (z params) byl finalnym renderem bez race condition.
function _adminRestoreState(tab, isSwitching) {
  try {
    var state = JSON.parse(localStorage.getItem('admin_state') || '{}');
    var s = state[tab];
    if (!s || !Object.keys(s).length) return;
    if (isSwitching && s.id) {
      var clean = {};
      if (s.filter) clean.filter = s.filter;
      if (s.sort) clean.sort = s.sort;
      if (Object.keys(clean).length) {
        if (typeof renderGen !== 'undefined') renderGen++;
        adminLoadTab(tab, clean);
      }
    } else {
      if (typeof renderGen !== 'undefined') renderGen++;
      adminLoadTab(tab, s);
    }
  } catch(e) {}
}

// jQuery delegation dla admin nav links — pewniejsze niz inline onclick (nie zostawia
// sie porwac przez browser extensions / default action).
$(document).on('click', '.admin-scope [data-admin-nav]', function(e) {
  e.preventDefault();
  e.stopPropagation();
  var tab = this.getAttribute('data-admin-nav');
  // Zbierz wszystkie data-* (poza data-admin-nav) jako params — dzieki temu
  // dowolny link moze przekazac id, level, filter, itd.
  var params = {};
  for (var i = 0; i < this.attributes.length; i++) {
    var a = this.attributes[i];
    if (a.name.indexOf('data-') === 0 && a.name !== 'data-admin-nav') {
      params[a.name.slice(5)] = a.value;
    }
  }
  adminLoadTab(tab, Object.keys(params).length ? params : null);
  return false;
});

// Zmiana taba w panel_admin — odpala subMenu i laduje karty
function adminSwitchTab(tab, params) {
  // loadPanel(panel, tab) — odswieza cale menu i laduje karty
  // Przekazanie params przez URL: tabs nie przyjmuja params w loadPanel, wiec po zmianie tabu
  // wywolujemy loadCard rownolegle zeby wpiac filter/id itp.
  loadPanel('admin', tab);
  if (params) setTimeout(function(){ adminLoadTab(tab, params); }, 50);
}

function initFilter(inputId, tab, extraParams) {
  var timer = null;
  var $input = $('#' + inputId);
  var $clear = $input.siblings('.search-clear');
  function toggleX() { $clear.toggleClass('visible', $input.val().length > 0); }
  function doSearch() {
    var v = $input.val();
    var p = $.extend({filter: v}, extraParams || {});
    adminLoadTab(tab, p);
    setTimeout(function() {
      var el = document.getElementById(inputId);
      if (el) { el.focus(); var len = el.value.length; el.setSelectionRange(len, len); }
      toggleX();
    }, 50);
  }
  $input.on('input', function() { clearTimeout(timer); toggleX(); timer = setTimeout(doSearch, 1000); })
    .on('keydown', function(e) { if (e.key === 'Enter') { clearTimeout(timer); doSearch(); } });
  $clear.on('click', function() { $input.val(''); toggleX(); clearTimeout(timer); doSearch(); });
  toggleX();
}

// Lazy searchable select — opcje ladowane AJAX-em przy focus.
// $el: input element (jQuery), url: API endpoint, opts: {value, text, onChange, params, dimFn}
function makeLazy($el, url, opts) {
  opts = opts || {};
  var $w = $el.closest('.ss-wrap');
  if (!$w.length) {
    $w = $('<div class="ss-wrap">');
    $el.wrap($w);
    $w = $el.parent();
    $w.append('<span class="ss-arrow">▾</span>');
  }
  var $drop = $w.find('.ss-drop');
  if (!$drop.length) { $drop = $('<div class="ss-drop">'); $w.append($drop); }
  $el.addClass('ss-input').attr('autocomplete', 'off');
  var _cache = null;
  var _loading = false;

  function renderOpts(filter) {
    $drop.empty();
    if (!_cache) { $drop.append('<div class="ss-opt dim">Ładowanie...</div>'); return; }
    var f = (filter||'').toLowerCase();
    var onItems = [], offItems = [];
    _cache.forEach(function(o) {
      if (f && !_fuzzyMatch(o.text, f)) return;
      if (o.dim) offItems.push(o); else onItems.push(o);
    });
    onItems.concat(offItems).forEach(function(o) {
      var $o = $('<div class="ss-opt">').text(o.text).attr('data-val', o.val);
      if (o.dim) $o.addClass('dim');
      if (String(o.val) === String(opts.value)) $o.addClass('active');
      $drop.append($o);
    });
    if (!$drop.children().length) $drop.append('<div class="ss-opt dim">Brak wyników</div>');
  }

  function load() {
    if (_cache || _loading) { renderOpts($el.val() === opts.text ? '' : $el.val()); $drop.addClass('open'); return; }
    _loading = true;
    renderOpts('');
    $drop.addClass('open');
    var ajaxParams = typeof opts.params === 'function' ? opts.params() : (opts.params || {});
    $.getJSON(url, ajaxParams, function(data) {
      _cache = [];
      data.forEach(function(d) {
        var val = d.id !== undefined ? d.id : (d.f || d.val || '');
        var text = d.name || d.label || d.text || String(val);
        var dim = opts.dimFn ? opts.dimFn(d) : false;
        _cache.push({val: String(val), text: text, dim: dim, data: d});
      });
      _loading = false;
      renderOpts($el.val() === opts.text ? '' : $el.val());
    });
  }

  $el.on('focus', function() { $el.select(); load(); });
  $el.on('input', function() { if (_cache) { renderOpts($el.val()); $drop.addClass('open'); } else load(); });
  $el.on('keydown', function(e) {
    if (e.key === 'Escape') { $el.val(opts.text || ''); $drop.removeClass('open'); $el.blur(); }
    if (e.key === 'Enter') { var $f = $drop.find('.ss-opt:not(.dim):first'); if ($f.length) $f.click(); e.preventDefault(); }
  });
  $drop.on('click', '.ss-opt:not(.dim)', function() {
    var v = $(this).attr('data-val'), t = $(this).text();
    opts.value = v;
    opts.text = t;
    $el.val(v ? t : '');
    $drop.removeClass('open');
    if (opts.onChange) opts.onChange(v, t, _cache ? _cache.find(function(c){return String(c.val)===String(v);}) : null);
  });
  $(document).on('mousedown', function(e) { if (!$w[0].contains(e.target)) $drop.removeClass('open'); });

  $el.data('lazyClear', function() { _cache = null; });
  return $el;
}

function makeSearchable($sel) {
  if (!$sel.length) return;
  $sel.each(function() {
    var $s = $(this);
    if ($s.data('ss-done')) return;
    $s.data('ss-done', 1).hide();
    var opts = [];
    $s.find('option').each(function(){ opts.push({val:$(this).val(), text:$(this).text(), disabled:$(this).prop('disabled')}); });
    var cur = opts.find(function(o){ return o.val === $s.val(); }) || opts[0] || {val:'',text:''};
    var selW = $s[0].style.width;
    var isFullWidth = $s.hasClass('w-full') || selW === '100%';
    var $w = $('<div class="ss-wrap">');
    if (selW && !isFullWidth && selW !== 'auto') $w.css('width', selW);
    var $inp = $('<input type="text" class="ss-input" autocomplete="off" placeholder="-- wybierz --">').val(cur.val ? cur.text : '');
    var $arrow = $('<span class="ss-arrow">▾</span>');
    var $drop = $('<div class="ss-drop">');
    $w.append($inp, $arrow, $drop);
    $s.after($w);
    function renderOpts(filter) {
      $drop.empty();
      var f = (filter||'').toLowerCase();
      opts.forEach(function(o) {
        if (o.disabled) return;
        if (f && !_fuzzyMatch(o.text, filter)) return;
        var $o = $('<div class="ss-opt">').text(o.text).attr('data-val', o.val);
        if (o.val === $s.val()) $o.addClass('active');
        $drop.append($o);
      });
    }
    $inp.on('focus', function() { $inp.select(); renderOpts(''); $drop.addClass('open'); });
    $inp.on('input', function() { renderOpts($inp.val()); $drop.addClass('open'); });
    $inp.on('keydown', function(e) {
      if (e.key === 'Escape') { $inp.val(cur.text); $drop.removeClass('open'); $inp.blur(); }
      if (e.key === 'Enter') {
        var $first = $drop.find('.ss-opt:first');
        if ($first.length) $first.click();
        e.preventDefault();
      }
    });
    $drop.on('click', '.ss-opt', function() {
      var v = $(this).attr('data-val'), t = $(this).text();
      $s.val(v).trigger('change');
      cur = {val:v, text:t};
      $inp.val(v ? t : '');
      $drop.removeClass('open');
    });
    $(document).on('mousedown', function(e) { if (!$w[0].contains(e.target)) $drop.removeClass('open'); });
  });
}
