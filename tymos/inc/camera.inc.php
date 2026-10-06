<?php
/**
 * inc/camera.inc.php — player WebRTC (WHEP) na go2rtc.
 *
 * Byl tu tez `camera_render_hls()` — usuniety 2026-09-02, bo nie wolal go zaden panel ani karta.
 * Razem z nim poszly streamy `parking_tiny` i `doorbell_tiny` (go2rtc.yaml + `$go2rtc_valid`
 * w api.php) — istnialy tylko dla niego i oba transkodowaly. Martwy player
 * nosil te same bledy cyklu zycia, co ten uzywany — listener `visibilitychange` rejestrowany
 * w closure i nigdy nie zdejmowany — czyli byl pulapka dla kazdego, kto by go kiedys odkopal.
 */

/**
 * WebRTC (WHEP) player — dla kamer wymagających niskiej latencji.
 *
 * $switch = lista streamow do PASKA MINIATUR na dole kadru (pusta = pojedyncza kamera, jak dotad).
 * Pierwszy element musi byc rowny $stream — od niego bierze sie id elementu (`cam_<stream>`),
 * ktore zostaje STALE mimo przelaczania kamer, zeby nakladki (alarm, ostrzezenia, glosnik)
 * i `window.cam_<stream>_listen` dzialaly bez zmian.
 *
 * Kafel moze wskazywac na `_hd` (np. `basen_hd` — slaba kamerka, w SD nic nie widac): otwiera
 * wtedy od razu HD, a przycisk SD/HD dziala jak zwykle.
 */
function camera_render(string $stream, string $title = '', bool $twoWayAudio = false, array $switch = []): void
{
    // Snapshot: PHP endpoint z no-cache headerami. Pobierany przez fetch+blob+objectURL
    // w refreshPoster() poniżej, NIE bezpośrednio przez atrybut <video poster>
    // (WebKit cachuje poster URL in-memory na życie taba ignorując no-store).
    $snapshot = 'api.php?action=camera_poster&src=' . $stream;
    $id       = 'cam_' . preg_replace('/[^a-z0-9]/', '_', $stream);
    $loadId   = $id . '_load';
    $pcVar    = $id . '_pc';
    ?>
<?php if ($title): ?>
<div class="card-title"><?= htmlspecialchars($title) ?></div>
<?php endif; ?>
<div style="position:relative;width:100%;background:#000;overflow:hidden;border-radius:12px;">
  <video id="<?= $id ?>" autoplay muted playsinline
         poster="<?= $snapshot ?>"
         style="position:absolute;top:0;left:0;width:100%;height:100%;display:block;object-fit:contain;z-index:1;"></video>
  <div id="<?= $loadId ?>" style="position:absolute;inset:0;z-index:2;display:flex;align-items:center;justify-content:center;pointer-events:none;transition:opacity 0.4s;">
    <span style="color:rgba(255,255,255,0.95);font-size:20px;font-weight:500;padding:8px 18px;background:rgba(0,0,0,0.65);border-radius:10px;text-shadow:0 1px 4px rgba(0,0,0,0.9);">Loading...</span>
  </div>
</div>
<script>
(function() {
    var ID          = <?= json_encode($id) ?>;
    var LOAD_ID     = <?= json_encode($loadId) ?>;
    var PC_VAR      = <?= json_encode($pcVar) ?>;
    var TWO_WAY     = <?= $twoWayAudio ? 'true' : 'false' ?>;
    var RESTART_VAR = ID + '_restart';
    // STREAM jest ZMIENNA, nie stala — przy przelaczeniu kamery podmieniamy tylko ja, a WHEP,
    // snapshot, camera_reset i _listen same podazaja za biezaca kamera.
    var STREAM      = <?= json_encode($stream) ?>;
    var TILES       = <?= json_encode(array_values($switch)) ?>;
    function whepUrl() { return '/g2r/api/webrtc?src=' + STREAM; }
    // Snapshot istnieje tylko dla jednej jakosci na kamere — `camera_poster` sam oddaje ta,
    // ktora ma (patrz api/camera_poster.inc.php), wiec pytamy wprost o biezacy stream.
    function snapUrl() { return 'api.php?action=camera_poster&src=' + STREAM; }
    function baseOf(s) { return String(s).replace(/_(sd|hd)$/, ''); }
    var pc          = null;
    var watchdog    = null;
    var lastTime    = -1;
    var localStream = null;
    var stallCount  = 0;
    var hasPlayed   = false;
    // Karta bywa wstrzykiwana ponownie (loadCard/swipe) — powstaje NOWY closure z wlasnym `pc`,
    // a `stop()` zamyka tylko swojego. Wczesniej stalo tu samo `window[PC_VAR] = null`, ktore
    // gubilo jedyne dojscie do PeerConnectiona poprzedniego closure — ten leciał dalej do konca
    // zycia dokumentu. Zmierzone 2026-09-02: DWIE sesje WHEP na strumien, 2x1,03 Mbit/s na
    // parking_sd, i to samo na doorbell_sd, na iPadzie i na Macu. Refresh nie byl przyczyna:
    // id sesji szly parami sasiadujacymi, czyli oba polaczenia powstawaly rownoczesnie.
    // Ten sam los mial `watchdog` — interwal zostawal w starym closure i dalej tykal, dlatego
    // jest publikowany w `window[ID + '_watchdog']`.
    // Wolamy `_stop()` POPRZEDNIEGO closure, nie `close()` na jednym wskazniku: `window[PC_VAR]`
    // trzyma tylko GLOWNY PeerConnection, a `stop()` zamyka takze `mic_pc` (rozmowa z dzwonkiem)
    // i `listen_pc` (dzwiek z kamery) oraz kasuje watchdoga. Bez tego przelaczenie panelu
    // w trakcie sluchania zostawialo audio lecace w tle.
    if (typeof window[ID + '_stop'] === 'function') { try { window[ID + '_stop'](); } catch (e) {} }
    if (window[PC_VAR]) { try { window[PC_VAR].close(); } catch (e) {} }
    if (window[ID + '_watchdog']) clearInterval(window[ID + '_watchdog']);
    window[PC_VAR]  = null;

    // Osobny PeerConnection dla mikrofonu two-way (backchannel do kamery, np. doorbell_talk)
    var mic_pc = null;
    // Osobny PeerConnection dla "sluchania" (audio recvonly z tego samego streamu — np. ptaszki w ogrodzie)
    var listen_pc = null;

    function stop() {
        clearInterval(watchdog);
        watchdog = null;
        window[ID + '_watchdog'] = null;
        if (pc) { try { pc.close(); } catch(e){} pc = null; }
        window[PC_VAR] = null;
        if (localStream) { localStream.getTracks().forEach(function(t){t.stop();}); localStream = null; }
        if (mic_pc) { try { mic_pc.close(); } catch(e){} mic_pc = null; }
        if (listen_pc) { try { listen_pc.close(); } catch(e){} listen_pc = null; }
        // Nie kasuj srcObject — zamrozona ramka zostanie az nowy stream ja zastapi
        lastTime = -1;
    }

    function start() {
        stop();
        var video = document.getElementById(ID);
        if (!video) return;
        var localPc = new RTCPeerConnection();
        pc = localPc;
        window[PC_VAR] = pc;
        pc.addTransceiver('video', {direction: 'recvonly'});
        // Glowny PC: TYLKO video — szybki start, zero negotiacji audio (DTLS audio potrafi wprowadzac
        // ~10s opoznienia gdy go2rtc ma `audio=none` w producerze).
        // Dla kamer z two-way (np. dzwonek): audio otwiera sie w OSOBNYM PC (backchannel stream),
        // aktywowanym dopiero gdy user klika przycisk "odbierz" (window[ID + '_mic'](true)).
        var newStream = new MediaStream();
        pc.ontrack = function(e) {
            if (pc !== localPc) return;
            var v = document.getElementById(ID);
            if (!v) return;
            newStream.addTrack(e.track);
            if (v.srcObject !== newStream) v.srcObject = newStream;
            if (e.track.kind === 'video') setTimeout(hideLoading, 500);
        };
        var _disconnectedTimer = null;
        pc.oniceconnectionstatechange = function() {
            if (pc !== localPc) return;
            console.log('[cam ' + ID + '] ICE state:', pc.iceConnectionState);
            if (pc.iceConnectionState === 'connected') {
                hideLoading();
                clearTimeout(_disconnectedTimer); _disconnectedTimer = null;
            }
            if (pc.iceConnectionState === 'failed') {
                clearTimeout(_disconnectedTimer); _disconnectedTimer = null;
                if (!document.hidden && document.getElementById(ID)) setTimeout(start, 3000);
            }
            // disconnected: daj 15s na powrot, potem reconnect
            if (pc.iceConnectionState === 'disconnected') {
                clearTimeout(_disconnectedTimer);
                _disconnectedTimer = setTimeout(function() {
                    if (pc === localPc && pc.iceConnectionState === 'disconnected') {
                        console.log('[cam ' + ID + '] ICE disconnected too long — reconnect');
                        start();
                    }
                }, 15000);
            }
        };
        pc.onicecandidate = function(e) {
            if (e.candidate) console.log('[cam ' + ID + '] local candidate:', e.candidate.candidate);
        };
        // Adaptive ICE gathering timeout — zaczynamy od 100ms (LAN, host candidates niemal natychmiast).
        // Jesli handshake zawiedzie, w onerror/watchdog zwiekszamy o 20% (zapisane w sessionStorage).
        var iceTimeoutKey = 'ice_to_' + STREAM;
        var iceTimeout = parseInt(sessionStorage.getItem(iceTimeoutKey), 10) || 100;
        pc.createOffer()
            .then(function(o) { return localPc.setLocalDescription(o); })
            .then(function() {
                return new Promise(function(resolve) {
                    // Czesto SDP juz ma host candidate zaraz po setLocalDescription — idziemy
                    if (localPc.localDescription && localPc.localDescription.sdp &&
                        localPc.localDescription.sdp.indexOf('a=candidate:') !== -1) {
                        resolve(); return;
                    }
                    if (localPc.iceGatheringState === 'complete') { resolve(); return; }
                    localPc.onicegatheringstatechange = function() {
                        if (localPc.iceGatheringState === 'complete') resolve();
                    };
                    setTimeout(resolve, iceTimeout);
                });
            })
            .then(function() {
                if (pc !== localPc) return Promise.reject('stale');
                return fetch(whepUrl(), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({type: 'offer', sdp: localPc.localDescription.sdp})
                });
            })
            .then(function(r) { return r.json(); })
            .then(function(ans) {
                if (pc !== localPc) return;
                console.log('[cam ' + ID + '] remote SDP candidates:', ans.sdp.match(/a=candidate:[^\r\n]*/g));
                return localPc.setRemoteDescription({type: 'answer', sdp: ans.sdp});
            })
            .catch(function(e) {
                if (e === 'stale') return;
                console.log('[cam ' + ID + '] error:', e);
                // Adaptive backoff: handshake zawiodl — zwieksz ICE gathering timeout o 20% (cap 2000ms)
                var cur = parseInt(sessionStorage.getItem(iceTimeoutKey), 10) || 100;
                var next = Math.min(Math.ceil(cur * 1.2), 2000);
                sessionStorage.setItem(iceTimeoutKey, String(next));
                console.log('[cam ' + ID + '] ICE timeout ' + cur + ' -> ' + next + 'ms');
                if (!document.hidden) setTimeout(start, 3000);
            });

        watchdog = window[ID + '_watchdog'] = setInterval(function() {
            var v = document.getElementById(ID);
            if (!v) { stop(); return; }
            if (v.currentTime === lastTime && v.srcObject) {
                stallCount++;
                console.log('[cam ' + ID + '] stall #' + stallCount);
                // Pokaz "Reconnecting..." overlay i swiezy snapshot zamiast zamrozonej ramki
                if (hasPlayed && stallCount >= 2) {
                    var ld = document.getElementById(LOAD_ID);
                    if (ld) { ld.querySelector('span').textContent = 'Reconnecting...'; ld.style.display = 'flex'; ld.style.opacity = '1'; }
                    refreshPoster();
                    v.removeAttribute('src');
                    if (v.srcObject) { v.srcObject = null; }
                    v.load();
                }
                // Co 3 stalle — reset streamu w go2rtc (nie caly serwis)
                if (hasPlayed && stallCount % 3 === 0) {
                    console.log('[cam ' + ID + '] resetting stream in go2rtc');
                    fetch('api.php?action=camera_reset', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'src=' + encodeURIComponent(STREAM)
                    }).catch(function(){});
                }
                // Adaptive backoff: stall -> zwieksz ICE timeout o 20% (moze gathering byl za krotki)
                var curSt = parseInt(sessionStorage.getItem(iceTimeoutKey), 10) || 100;
                var nextSt = Math.min(Math.ceil(curSt * 1.2), 2000);
                sessionStorage.setItem(iceTimeoutKey, String(nextSt));
                start();
            } else {
                if (stallCount > 0) console.log('[cam ' + ID + '] recovered after ' + stallCount + ' stalls');
                stallCount = 0;
            }
            lastTime = v.currentTime;
        }, 15000);
    }

    var video = document.getElementById(ID);
    function hideLoading() {
        var ld = document.getElementById(LOAD_ID);
        if (ld && ld.style.display !== 'none') { ld.style.opacity = '0'; setTimeout(function() { ld.style.display = 'none'; }, 400); }
    }
    video.onplaying = function() {
        hasPlayed = true;
        stallCount = 0;
        hideLoading();
        // Sukces — reset ICE timeout do minimum (zacznemy od 100ms przy nastepnym reconnect)
        sessionStorage.setItem('ice_to_' + STREAM, '100');
    };
    video.onloadeddata = hideLoading;

    // utrzymuj ratio 16:9 przy resize
    var wrapper = video.parentElement;
    var _fixRafPending = false;
    function fixRatio() {
        if (_fixRafPending) return;
        _fixRafPending = true;
        requestAnimationFrame(function() { _fixRafPending = false; wrapper.style.height = wrapper.offsetWidth * 9/16 + 'px'; });
    }
    fixRatio();
    if (window.ResizeObserver) {
        new ResizeObserver(fixRatio).observe(wrapper);
    } else {
        // Sciezka dla przegladarek bez ResizeObserver. Listener na `window` przezyje karte,
        // wiec zdejmujemy go przy sprzataniu — inaczej narastalby jak `visibilitychange`.
        window.addEventListener('resize', fixRatio);
        if (typeof cardOnUnload === 'function') {
            cardOnUnload(function() { window.removeEventListener('resize', fixRatio); });
        }
    }

    window[RESTART_VAR] = start;
    window[ID + '_stop'] = stop;
    window[ID + '_pause'] = function() { clearInterval(watchdog); watchdog = null; window[ID + '_watchdog'] = null; };

    // "Odbierz rozmowe" (przycisk phone) — otwiera OSOBNY PeerConnection do backchannel streamu
    // (np. doorbell_talk) z audio DWUKIERUNKOWYM:
    //   - sendonly: mikrofon iPada -> kamera (user mowi do gosci)
    //   - recvonly: audio z kamery -> ukryty <audio> element (user slyszy goscia)
    // Glowny PC (video) nie jest tkniety. Szybkie start/stop bez wplywu na stream video.
    window[ID + '_mic'] = function(enable) {
        if (!TWO_WAY) { console.log('[mic] TWO_WAY=false'); return Promise.resolve(false); }
        if (enable) {
            if (mic_pc) return Promise.resolve(true);
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                console.log('[mic] getUserMedia not available (need HTTPS)');
                return Promise.resolve(false);
            }
            var backchannel = STREAM.replace(/_sd$/, '_talk');
            return navigator.mediaDevices.getUserMedia({audio: true}).then(function(stream) {
                localStream = stream;
                mic_pc = new RTCPeerConnection();

                // Ukryty audio element dla odtwarzania glosu z kamery
                var audioEl = document.getElementById(ID + '_audio');
                if (!audioEl) {
                    audioEl = document.createElement('audio');
                    audioEl.id = ID + '_audio';
                    audioEl.autoplay = true;
                    audioEl.playsInline = true;
                    audioEl.style.display = 'none';
                    document.body.appendChild(audioEl);
                }
                var remoteAudio = new MediaStream();
                mic_pc.ontrack = function(e) {
                    if (e.track.kind === 'audio') {
                        remoteAudio.addTrack(e.track);
                        if (audioEl.srcObject !== remoteAudio) audioEl.srcObject = remoteAudio;
                    }
                };

                // Dodaj mic track — Safari auto-tworzy jeden sendrecv audio transceiver.
                // (Podwojne dodanie przez addTransceiver+addTrack generowalo duplikat a=msid w SDP.)
                mic_pc.addTrack(stream.getAudioTracks()[0], stream);

                return mic_pc.createOffer();
            }).then(function(offer) {
                return mic_pc.setLocalDescription(offer);
            }).then(function() {
                // Timeout 12s — cold start Reolink backchannel + keyframe wait moze trwac kilka sekund
                var ctrl = new AbortController();
                var toId = setTimeout(function(){ ctrl.abort(); }, 12000);
                return fetch('/g2r/api/webrtc?src=' + backchannel, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({type: 'offer', sdp: mic_pc.localDescription.sdp}),
                    signal: ctrl.signal
                }).finally(function(){ clearTimeout(toId); });
            }).then(function(r) {
                if (!r.ok) throw new Error('go2rtc HTTP ' + r.status);
                return r.json();
            })
              .then(function(ans) {
                if (!ans || !ans.sdp) throw new Error('go2rtc invalid answer');
                return mic_pc.setRemoteDescription({type: 'answer', sdp: ans.sdp});
            }).then(function() {
                console.log('[mic] talk PC up (' + backchannel + ')');
                return true;
            }).catch(function(e) {
                console.log('[mic] error:', (e && e.message) || e);
                if (mic_pc) { try { mic_pc.close(); } catch(ex){} mic_pc = null; }
                if (localStream) { localStream.getTracks().forEach(function(t){t.stop();}); localStream = null; }
                return false;
            });
        } else {
            if (mic_pc) { try { mic_pc.close(); } catch(e){} mic_pc = null; }
            if (localStream) { localStream.getTracks().forEach(function(t){t.stop();}); localStream = null; }
            var audioEl = document.getElementById(ID + '_audio');
            if (audioEl) { audioEl.srcObject = null; }
            console.log('[mic] talk PC down');
            return Promise.resolve(true);
        }
    };

    // "Sluchaj" (przycisk glosnika) — otwiera OSOBNY PeerConnection do tego samego streamu,
    // ale TYLKO audio recvonly. Audio podpinane do ukrytego <audio> elementu zamiast do glownego <video>,
    // zeby nie kolidowac z video (Safari mute flag na video element).
    window[ID + '_listen'] = function(enable) {
        if (enable) {
            if (listen_pc) return Promise.resolve(true);
            listen_pc = new RTCPeerConnection();
            listen_pc.addTransceiver('audio', {direction: 'recvonly'});

            var audioEl = document.getElementById(ID + '_listen_audio');
            if (!audioEl) {
                audioEl = document.createElement('audio');
                audioEl.id = ID + '_listen_audio';
                audioEl.autoplay = true;
                audioEl.playsInline = true;
                audioEl.style.display = 'none';
                document.body.appendChild(audioEl);
            }
            var remoteAudio = new MediaStream();
            listen_pc.ontrack = function(e) {
                if (e.track.kind === 'audio') {
                    remoteAudio.addTrack(e.track);
                    if (audioEl.srcObject !== remoteAudio) audioEl.srcObject = remoteAudio;
                }
            };

            return listen_pc.createOffer()
                .then(function(offer) { return listen_pc.setLocalDescription(offer); })
                .then(function() {
                    return new Promise(function(resolve) {
                        if (listen_pc.localDescription && listen_pc.localDescription.sdp &&
                            listen_pc.localDescription.sdp.indexOf('a=candidate:') !== -1) { resolve(); return; }
                        if (listen_pc.iceGatheringState === 'complete') { resolve(); return; }
                        listen_pc.onicegatheringstatechange = function() {
                            if (listen_pc.iceGatheringState === 'complete') resolve();
                        };
                        setTimeout(resolve, 300);
                    });
                })
                .then(function() {
                    var ctrl = new AbortController();
                    var toId = setTimeout(function(){ ctrl.abort(); }, 8000);
                    return fetch('/g2r/api/webrtc?src=' + STREAM, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({type: 'offer', sdp: listen_pc.localDescription.sdp}),
                        signal: ctrl.signal
                    }).finally(function(){ clearTimeout(toId); });
                })
                .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(function(ans) {
                    if (!ans || !ans.sdp) throw new Error('invalid answer');
                    return listen_pc.setRemoteDescription({type: 'answer', sdp: ans.sdp});
                })
                .then(function() {
                    console.log('[listen] PC up (' + STREAM + ')');
                    return true;
                })
                .catch(function(e) {
                    console.log('[listen] error:', (e && e.message) || e);
                    if (listen_pc) { try { listen_pc.close(); } catch(x){} listen_pc = null; }
                    return false;
                });
        } else {
            if (listen_pc) { try { listen_pc.close(); } catch(e){} listen_pc = null; }
            var audioEl = document.getElementById(ID + '_listen_audio');
            if (audioEl) audioEl.srcObject = null;
            return Promise.resolve(true);
        }
    };

    // Pobiera świeży snapshot przez fetch+blob+objectURL — omija WebKit "<video poster>
    // in-memory cache". Object URL żyje tylko w pamięci, jeden naraz (revoke poprzedniego).
    var _posterObjectUrl = null;
    function refreshPoster() {
        var want = STREAM;   // przelaczenie kamery w trakcie fetcha = ten poster jest juz nieaktualny
        fetch(snapUrl(), { cache: 'no-store' })
            .then(function(r) { return r.ok ? r.blob() : null; })
            .then(function(blob) {
                if (!blob || baseOf(want) !== baseOf(STREAM)) return;
                var url = URL.createObjectURL(blob);
                if (_posterObjectUrl) URL.revokeObjectURL(_posterObjectUrl);
                _posterObjectUrl = url;
                var ve = document.getElementById(ID);
                if (ve) ve.poster = url;
            })
            .catch(function(){});
    }

    // NAZWANA funkcja, nie anonimowa — inaczej nie ma jak jej zdjac. Kazde zaladowanie karty
    // (czyli kazde przejscie miedzy panelami) rejestrowalo kolejny listener przywiazany do swojego
    // starego closure; przy obudzeniu ekranu KAZDY z nich wolal wlasny `start()` i tworzyl osobny
    // PeerConnection, a `window[PC_VAR]` pamietal tylko ostatni. Reszta strumieni leciala w prozne.
    // Dodatkowo kazdy zywy listener trzymal caly stary closure z przechwyconym DOM — na kiosku
    // chodzacym tygodniami to po prostu roslo.
    function onVisibility() {
        if (document.hidden) {
            stop();
        } else {
            var v = document.getElementById(ID);
            if (v) {
                // Wyczysc srcObject zeby poster (snapshot z tmpfs) pojawil sie zanim stream wroci.
                // Bez tego Safari trzyma zamrozona/czarna ramke z poprzedniego MediaStream.
                if (v.srcObject) { v.srcObject = null; v.load(); }
                // Pokaz overlay "Loading..." az WebRTC wroci
                var ld = document.getElementById(LOAD_ID);
                if (ld) { ld.querySelector('span').textContent = 'Loading...'; ld.style.display = 'flex'; ld.style.opacity = '1'; }
                refreshPoster();
            }
            start();
        }
    }
    document.addEventListener('visibilitychange', onVisibility);

    // Karta znika (przejscie panelu, przeladowanie karty, swipe) — framework wola to sprzatanie
    // PRZED podmiana tresci. Zdejmujemy listener z `document` i zamykamy wszystkie strumienie.
    // Rejestrujemy sie na elemencie <video>, bo po nim framework poznaje, ze sprzatanie nalezy
    // do wymienianego fragmentu.
    if (typeof cardOnUnload === 'function') {
        cardOnUnload(function() {
            document.removeEventListener('visibilitychange', onVisibility);
            if (window[ID + '_tileTimer']) clearInterval(window[ID + '_tileTimer']);
            stop();
        });
    }

    // ---- PASEK MINIATUR (przelaczanie kamery w tym samym view divie) ----
    // ZAWSZE JEDEN STRUMIEN NARAZ: stary PeerConnection ginie od razu, a w jego miejsce od razu
    // wchodzi ostatni snapshot nowej kamery z tmpfs (swiezy co 10 s z akcji system_snapshot_refresh)
    // plus overlay "Loading...". Zero czerni i od razu widac, ze klikniecie doszlo.
    // Kafel bez dzialajacego snapshotu (kamera nieznana go2rtc — dzis garaz) sam robi sie szary
    // i nieklikalny, i sam ozyje, gdy kamera wroci do go2rtc (kafle odpytuja poster co 30 s).
    var SVG_OFFLINE = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" '
        + 'stroke="rgba(255,255,255,0.5)" stroke-width="1.6" stroke-linecap="round">'
        + '<path d="M3 7h11v10H3z"/><path d="M16 10l5-3v10l-5-3"/><path d="M3 21L21 3"/></svg>';

    function paintTiles() {
        var bar = document.getElementById(ID + '_tiles');
        if (!bar) return;
        [].forEach.call(bar.children, function(t) {
            var off = (t.dataset.off === '1');
            // kafel jest aktywny dla obu jakosci swojej kamery (basen_sd i basen_hd)
            var act = (baseOf(t.dataset.src) === baseOf(STREAM));
            t.style.opacity     = off ? '0.45' : (act ? '1' : '0.8');
            // biala ramka aktywnej dawala po oczach — aktywna ma CZARNA, reszta ciemnoszara
            t.style.borderColor = act ? '#000' : '#555';
            t.firstChild.style.display = off ? 'none' : 'block';
            t.lastChild.style.display  = off ? 'flex' : 'none';
        });
    }

    function switchTo(s) {
        if (s === STREAM) return;
        // Glosnik zawsze gasnie — inaczej slychac kamere, ktorej nie widac. Nowa startuje wyciszona.
        if (window[ID + '_listen']) window[ID + '_listen'](false);
        stop();
        STREAM = s;
        window[ID + '_stream'] = s;
        var v = document.getElementById(ID);
        if (v) {
            if (v.srcObject) v.srcObject = null;
            v.removeAttribute('poster');   // bez tego zostalby ostatni kadr POPRZEDNIEJ kamery
            v.load();
            var ld = document.getElementById(LOAD_ID);
            if (ld) { ld.querySelector('span').textContent = 'Loading...'; ld.style.display = 'flex'; ld.style.opacity = '1'; }
            // karta nasluchuje i resetuje wlasne ikony (np. przycisk glosnika)
            v.dispatchEvent(new CustomEvent('camswitch', {detail: {src: s}}));
        }
        paintTiles();
        refreshPoster();
        start();
    }

    // Przelaczanie streamu z ZEWNATRZ karty (np. przycisk jakosci SD/HD w card_camera_parking).
    // `window[ID + '_stream']` trzyma biezacy stream, zeby przycisk nie musial zgadywac stanu
    // po samym zdarzeniu camswitch (np. tuz po zaladowaniu karty, przed pierwszym przelaczeniem).
    window[ID + '_switch'] = switchTo;
    window[ID + '_stream'] = STREAM;

    function buildTiles() {
        if (!TILES.length) return;
        // Karta bywa ladowana ponownie (loadCard/swipe) — bez sprzatania zostawaly dwa paski
        // i dwa timery. Patrz ta sama pulapka przy #grzAlertBox.
        var old = document.getElementById(ID + '_tiles');
        if (old) old.remove();
        if (window[ID + '_tileTimer']) clearInterval(window[ID + '_tileTimer']);

        var bar = document.createElement('div');
        bar.id = ID + '_tiles';
        bar.style.cssText = 'position:absolute;left:8px;bottom:8px;z-index:12;display:flex;gap:6px;';

        TILES.forEach(function(s) {
            var t = document.createElement('button');
            t.dataset.src = s;
            t.style.cssText = 'position:relative;width:92px;height:52px;padding:0;border:1px solid #000;'
                + 'border-radius:4px;overflow:hidden;background:#000;cursor:pointer;opacity:0.8;'
                + 'transition:opacity 0.15s,border-color 0.15s;'
                + '-webkit-tap-highlight-color:transparent;touch-action:manipulation;user-select:none;';
            var img = document.createElement('img');
            img.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;';
            img.onload  = function() { t.dataset.off = '0'; paintTiles(); };
            img.onerror = function() { t.dataset.off = '1'; paintTiles(); };
            var glyph = document.createElement('span');
            glyph.style.cssText = 'width:100%;height:100%;display:none;align-items:center;justify-content:center;';
            glyph.innerHTML = SVG_OFFLINE;
            t.appendChild(img);
            t.appendChild(glyph);
            t.onclick = function() { if (t.dataset.off !== '1') switchTo(s); };
            bar.appendChild(t);
        });

        document.getElementById(ID).parentElement.appendChild(bar);

        function refreshTiles() {
            if (!document.getElementById(ID + '_tiles')) { clearInterval(window[ID + '_tileTimer']); return; }
            if (document.hidden) return;   // wygaszony iPad nie musi ciagnac miniatur
            var bust = '&_=' + Date.now();
            [].forEach.call(bar.children, function(t) {
                t.firstChild.src = 'api.php?action=camera_poster&src=' + t.dataset.src + bust;
            });
        }
        refreshTiles();
        window[ID + '_tileTimer'] = setInterval(refreshTiles, 30000);
        paintTiles();
    }

    buildTiles();

    refreshPoster();
    if (!document.hidden) start();
})();
</script>
    <?php
}
