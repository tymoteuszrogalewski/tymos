// TymOS sounds — polling tymos_sound_check + audio engine

(function() {
    var _lastId = 0;
    var _ctx = null;
    var _unlocked = false;
    var _uiVer = null;      // wersja UI z api (plik tymos/VERSION) — zmiana => pelne przeladowanie

    function _unlock() {
        if (!_ctx) {
            try { _ctx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e) { return; }
        }
        if (_ctx.state === 'suspended') _ctx.resume();
        if (!_unlocked && _ctx.state === 'running') {
            var o = _ctx.createOscillator();
            var g = _ctx.createGain();
            g.gain.value = 0;
            o.connect(g); g.connect(_ctx.destination);
            o.start(); o.stop(_ctx.currentTime + 0.001);
            _unlocked = true;
        }
        _mp3Preload();
    }

    document.addEventListener('touchstart', _unlock);
    document.addEventListener('click', _unlock);
    if (sessionStorage.getItem('db_audio')) window._dbUnlocked = true;

    // iOS: video playing → unlock audio
    new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            m.addedNodes.forEach(function(n) {
                if (n.tagName === 'VIDEO' || (n.querySelectorAll && n.querySelectorAll('video').length)) {
                    var vids = n.tagName === 'VIDEO' ? [n] : n.querySelectorAll('video');
                    for (var i = 0; i < vids.length; i++) {
                        vids[i].addEventListener('playing', _unlock);
                    }
                }
            });
        });
    }).observe(document.body, {childList: true, subtree: true});

    // keepalive: wybudz uspiony kontekst + cichy ping co 30s
    // (iOS usypia AudioContext samo z siebie, nawet na aktywnym kiosku)
    setInterval(function() {
        if (!_ctx) return;
        if (_ctx.state === 'suspended') _ctx.resume();
        if (_ctx.state === 'running') {
            var o = _ctx.createOscillator();
            var g = _ctx.createGain();
            g.gain.value = 0;
            o.connect(g); g.connect(_ctx.destination);
            o.start(); o.stop(_ctx.currentTime + 0.001);
        }
        _mp3Preload();   // retry gdy pobranie/dekodowanie mp3 sie nie udalo
    }, 30000);

    // --- MP3 ---
    // Pliki mp3 ida przez TEN SAM AudioContext co dzwieki syntetyczne (fetch + decodeAudioData),
    // wiec dziedzicza istniejacy unlock po dotyku i keepalive. Swiadomie NIE <audio>/new Audio() —
    // to osobna warstwa autoplay na iOS, ktora trzeba by odblokowywac drugi raz.
    // Bufor dekodowany RAZ (przy unlocku / keepalive) i trzymany w pamieci — odtworzenie = zero opoznienia.

    // Lektorzy mp3 NIE sa wypisani w tym pliku — liste podaje serwer (api tymos_sound_list =
    // zawartosc katalogu snd/), a nazwa dzwieku = nazwa pliku bez .mp3. Nowy lektor to wiec samo
    // wgranie mp3 na Pi: zero zmian w JS i zero przeladowania kiosku (przeladowanie shella kosztuje
    // tapniecie w ekran, bo iOS gasi AudioContext). Pozwala tez generowac dzwieki w locie.
    // `_mp3Mtime` trzyma mtime kazdego pliku — wedruje do URL, wiec podmienione nagranie
    // dociaga sie samo, bez czekania na przeladowanie strony.
    var _mp3Mtime = {};                                   // nazwa => mtime pliku na serwerze
    var _mp3Bust  = Date.now();                           // cache-buster, staly w obrebie jednego load
    var _mp3Raw   = {};                                   // url => ArrayBuffer | 'loading' | null (blad)
    var _mp3Buf   = {};                                   // url => AudioBuffer | 'loading' | null (blad)
    var _mp3Wait  = {};                                   // url => callback po zdekodowaniu (odtworz od razu)

    function _mp3StateStr(v) {
        if (v === 'loading') return 'loading';
        if (v === 'dead')    return 'brak pliku';
        if (v === null)      return 'error';
        if (v === undefined) return 'none';
        return 'ok';
    }

    // Etap 1: pobranie pliku — bez AudioContextu, wiec mozna od razu po zaladowaniu strony
    // (nie tworzymy tu ctx, zeby _tymosAudioState() dalej odrozniala 'none' od 'suspended').
    function _mp3Fetch(url) {
        // 'dead' = serwer odpowiedzial bledem (np. plik jeszcze nie wgrany) — nie ponawiamy
        // i nie logujemy drugi raz pod TYM url. Gdy plik sie pojawi, lista poda jego mtime,
        // url bedzie inny i pobranie ruszy od nowa — bez przeladowania strony.
        if (_mp3Raw[url] === 'dead' || _mp3Raw[url] === 'loading' || (_mp3Raw[url] && _mp3Raw[url] !== null)) return;
        _mp3Raw[url] = 'loading';
        var xhr = new XMLHttpRequest();
        // Cache-buster siedzi juz w URL (_mp3Url: mtime pliku albo czas zaladowania strony) —
        // Safari cache'uje mp3, bo Apache nie daje naglowkow cache dla /snd/.
        xhr.open('GET', url, true);
        xhr.responseType = 'arraybuffer';
        xhr.onload = function() {
            if (xhr.status !== 200) {
                _mp3Raw[url] = 'dead';
                _jsLog('ERROR', 'mp3 fetch ' + url + ' http=' + xhr.status);
                return;
            }
            _mp3Raw[url] = xhr.response;
            _mp3Decode(url);
        };
        xhr.onerror = function() { _mp3Raw[url] = null; _jsLog('ERROR', 'mp3 fetch failed ' + url); };
        xhr.send();
    }

    // Etap 2: dekodowanie — wymaga AudioContextu, ale NIE stanu 'running'
    function _mp3Decode(url) {
        if (!_ctx) return;
        var raw = _mp3Raw[url];
        if (!raw || raw === 'loading' || raw === 'dead') return;
        if (_mp3Buf[url] === 'loading' || (_mp3Buf[url] && _mp3Buf[url] !== null)) return;
        _mp3Buf[url] = 'loading';
        _ctx.decodeAudioData(raw,
            function(buf) {
                _mp3Buf[url] = buf;
                if (_mp3Wait[url]) { var cb = _mp3Wait[url]; _mp3Wait[url] = null; cb(); }
            },
            // blad dekodowania: czyscimy tez raw (Safari „neutralizuje" ArrayBuffer po decode) — retry pobierze plik od nowa
            function(e) {
                _mp3Buf[url] = null; _mp3Raw[url] = null; _mp3Wait[url] = null;
                _jsLog('ERROR', 'mp3 decode failed ' + url + ' ' + (e && e.message ? e.message : ''));
            });
    }

    function _mp3Load(url) { _mp3Fetch(url); _mp3Decode(url); }

    // Wersja w URL: mtime pliku, a gdy listy jeszcze nie ma (dzwiek wgrany przed chwila) — czas
    // zaladowania strony, zeby w ogole cos zagralo.
    function _mp3Url(name) { return 'snd/' + name + '.mp3?v=' + (_mp3Mtime[name] || _mp3Bust); }

    // Nazwa rozwijana do URL dopiero w chwili grania — po podmianie pliku leci nowe nagranie.
    function _mp3PlayName(name) {
        return function() { _mp3Play(_mp3Url(name), 1.0)(); };
    }

    function _mp3Preload() {
        for (var n in _mp3Mtime) _mp3Load(_mp3Url(n));
    }

    // Lista lektorow z serwera: rejestruje nowe dzwieki w `_sounds` i preloaduje pliki.
    // Wolana przy starcie, co 5 min i gdy przyjdzie nazwa, ktorej jeszcze nie znamy.
    function _mp3ListLoad() {
        $.getJSON('api.php?action=tymos_sound_list', function(list) {
            if (!list || !list.length) return;
            for (var i = 0; i < list.length; i++) {
                var n = list[i].n;
                _mp3Mtime[n] = list[i].m;
                if (!_sounds[n]) _sounds[n] = _mp3PlayName(n);
            }
            _mp3Preload();
        });
    }

    // Raport do tabeli `log` (source='js') — kiosk iPad nie ma dostepnej konsoli
    function _jsLog(level, msg) {
        try { $.get('api.php?action=js_log&level=' + level + '&msg=' + encodeURIComponent(msg)); } catch(e) {}
    }

    function _mp3Play(url, vol) {
        function fire() {
            if (!_ctx || _ctx.state !== 'running') {
                _jsLog('WARN', 'mp3 fire skip ' + url + ' ctx=' + (_ctx ? _ctx.state : 'none'));
                return;
            }
            var src = _ctx.createBufferSource(), g = _ctx.createGain();
            g.gain.value = (vol === undefined) ? 1.0 : vol;
            src.buffer = _mp3Buf[url];
            src.connect(g); g.connect(_ctx.destination);
            src.start(0);
        }
        return function() {
            var buf = _mp3Buf[url];
            // bufor jeszcze nie gotowy → doladuj i zagraj ZARAZ PO zaladowaniu (nie gub powiadomienia)
            if (!buf || buf === 'loading') {
                _jsLog('WARN', 'mp3 play ' + url + ' bufor niegotowy: ctx=' + (_ctx ? _ctx.state : 'none')
                             + ' raw=' + _mp3StateStr(_mp3Raw[url]) + ' buf=' + _mp3StateStr(buf));
                _mp3Wait[url] = fire; _mp3Load(url); return;
            }
            fire();
        };
    }

    // Pobierz liste i pliki od razu po zaladowaniu strony (dekodowanie dojdzie przy pierwszym
    // _unlock/keepalive), potem odswiezaj co 5 min — tyle wynosi opoznienie, z jakim kiosk zobaczy
    // lektora wgranego bez przeladowania. Nazwa nieznana z listy i tak zagra od razu (_play).
    _mp3ListLoad();
    setInterval(_mp3ListLoad, 300000);

    // Diagnostyka: 'ready' (zdekodowany) / 'fetched' (pobrany, czeka na ctx) / 'loading' / 'error' / 'none'
    window._tymosMp3State = function() {
        var out = {};
        for (var k in _mp3Mtime) {
            var url = _mp3Url(k), b = _mp3Buf[url], r = _mp3Raw[url];
            if (b && b !== 'loading')  out[k] = 'ready';
            else if (b === 'loading')  out[k] = 'loading';
            else if (b === null)       out[k] = 'error';
            else                       out[k] = _mp3StateStr(r);
        }
        return out;
    };

    // --- Dzwieki ---

    function _note(t, freq, dur, vol, type) {
        var o = _ctx.createOscillator(), g = _ctx.createGain();
        o.connect(g); g.connect(_ctx.destination);
        o.type = type || 'sine';
        o.frequency.value = freq;
        g.gain.setValueAtTime(vol, t);
        g.gain.exponentialRampToValueAtTime(0.01, t + dur);
        o.start(t); o.stop(t + dur + 0.02);
    }

    function _richNote(t, freq, dur, vol) {
        var types = ['sawtooth','square','triangle'];
        var mults = [1, 2, 1.5];
        var vols = [vol, vol*0.3, vol*0.2];
        for (var j = 0; j < 3; j++) {
            _note(t, freq * mults[j], dur, vols[j], types[j]);
        }
    }

    var _sounds = {
        ping: function() {
            if (!_ctx || _ctx.state !== 'running') return;
            var t = _ctx.currentTime;
            _note(t, 880, 0.5, 0.3);
        },
        ping_short: function() {
            if (!_ctx || _ctx.state !== 'running') return;
            var t = _ctx.currentTime;
            _note(t, 880, 0.25, 0.3);
        },
        doorbell: function() {
            if (!_ctx || _ctx.state !== 'running') return;
            var t = _ctx.currentTime;
            var notes = [392,392,523,523,659,784];
            var times = [0,0.12,0.24,0.36,0.48,0.6];
            var durs  = [0.1,0.1,0.1,0.1,0.15,0.4];
            for (var rep = 0; rep < 2; rep++) {
                var off = rep * 1.1;
                for (var i = 0; i < notes.length; i++) _richNote(t + off + times[i], notes[i], durs[i], 0.7);
            }
        },
        alarm: function() {
            if (!_ctx || _ctx.state !== 'running') return;
            var t = _ctx.currentTime;
            for (var rep = 0; rep < 4; rep++) {
                var off = rep * 0.5;
                _note(t + off, 1200, 0.15, 0.5, 'square');
                _note(t + off + 0.25, 800, 0.15, 0.5, 'square');
            }
        },
        // Nazwa z sufiksem, bo lektor `pralka` to teraz snd/pralka.mp3 — nazwy dzwiekow i plikow sa 1:1.
        pralka_pik: function() {   // "PIK PIK PIK PIK PIK" — koniec prania
            if (!_ctx || _ctx.state !== 'running') return;
            var t = _ctx.currentTime;
            for (var i = 0; i < 5; i++) _note(t + i * 0.32, 1000, 0.16, 0.5, 'square');
        },
        // lektorzy mp3 dopisywani AUTOMATYCZNIE w _mp3ListLoad() (nazwa = plik w snd/ bez .mp3)
    };

    // Odtworz dzwiek, wybudzajac uspiony AudioContext jesli trzeba
    function _play(name) {
        // Nazwa spoza listy = lektor wgrany po ostatnim jej odswiezeniu (albo wygenerowany w locie).
        // Gramy wprost z pliku i odswiezamy liste; gdy pliku nie ma, _mp3Fetch zaloguje 404.
        if (!_sounds[name]) { _sounds[name] = _mp3PlayName(name); _mp3ListLoad(); }
        if (!_ctx) {
            try { _ctx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e) { return; }
        }
        if (_ctx.state === 'running') {
            _sounds[name]();
        } else {
            // iOS: bez gestu uzytkownika resume() czesto NIE rozwiazuje promisy — powiadomienie
            // wisi w kolejce i odpala sie dopiero po pierwszym dotknieciu ekranu. Bez tego logu
            // bylo to calkowicie niewidoczne z serwera (cichy zgon powiadomienia).
            _jsLog('WARN', 'dzwiek ' + name + ' czeka na dotkniecie ekranu, ctx=' + _ctx.state);
            _ctx.resume().then(function() { _sounds[name](); }).catch(function() {});
        }
    }

    // Eksport do przegladarki dzwonkow w admin
    window._tymosSounds = _sounds;
    window._tymosUnlock = _unlock;
    // Stan AudioContext dla diagnostyki (panel_home pokazuje red button gdy != running)
    window._tymosAudioState = function() { return _ctx ? _ctx.state : 'none'; };

    setInterval(function() {
        $.getJSON('api.php?action=tymos_sound_check', function(d) {
            if (d.id && d.id !== _lastId) {
                if (d.sound) _play(d.sound);
                _lastId = d.id;
            }
            // Wymuszone PELNE przeladowanie po deployu. Serwer podaje `ver` z pliku tymos/VERSION
            // (bump na koncu deployu). Pierwszy poll tylko zapamietuje wartosc — reload leci dopiero
            // gdy sie ZMIENI, czyli nigdy zaraz po zaladowaniu strony.
            // location.replace na NOWY url (?v=...), a nie location.reload(): iOS w trybie
            // home-screen app potrafi obsluzyc reload z wlasnego snapshotu, zmiana URL to wyklucza.
            // JS jest include'owany server-side do index.php (brak osobnych <script src>), a index.php
            // leci z no-store => swiezy HTML = swiezy JS. Mp3 maja wlasny cache-buster (_mp3Bust).
            // UWAGA: po kazdym przeladowaniu AudioContext jest suspended do pierwszego dotyku (iOS) —
            // czerwony pasek #db_audio_ov to pokazuje i tap go odblokowuje.
            if (d.ver) {
                if (_uiVer === null) {
                    _uiVer = d.ver;
                } else if (d.ver !== _uiVer) {
                    var _f = document.getElementById('uiFrame');
                    _jsLog('INFO', 'UI reload: ver ' + _uiVer + ' -> ' + d.ver
                                 + (_f ? ' (ramka — audio zostaje)' : ' (cala strona — audio zgasnie)'));
                    _uiVer = d.ver;
                    // 300 ms zeby log zdazyl wyjsc przed nawigacja (fetch inaczej zostaje anulowany)
                    setTimeout(function() {
                        if (_f) {
                            // Shell (index2.php): przeladuj TYLKO ramke. Dokument shella zyje dalej,
                            // wiec AudioContext nie ginie i nie trzeba dotykac ekranu.
                            _f.src = 'ui.php?v=' + encodeURIComponent(d.ver);
                        } else {
                            // Klasyczny index.php bez ramki — pelne przeladowanie, audio wymaga tapniecia.
                            location.replace(location.pathname + '?v=' + encodeURIComponent(d.ver));
                        }
                    }, 300);
                }
            }
        });
    }, 3000);
})();
