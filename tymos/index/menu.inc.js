// Menu — klik + long press reload

$('#divMenu').on('click', '.menu-item', function() {
    var panel = $(this).data('panel');
    loadPanel(panel, panel === currentPanel ? currentTab : null);
});

(function() {
    var _lpt = null, _lpFired = false;
    $('#divMenu').on('touchstart', '.menu-item', function(e) {
        _lpFired = false;
        _lpt = setTimeout(function() {
            _lpFired = true;
            location.href = location.pathname + '?_=' + Date.now();
        }, 1000);
    }).on('touchend touchcancel touchmove', '.menu-item', function() {
        clearTimeout(_lpt); _lpt = null;
    }).on('click', '.menu-item', function(e) {
        if (_lpFired) e.stopImmediatePropagation();
    });
})();
