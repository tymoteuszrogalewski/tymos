#!/usr/bin/env php
<?php
/**
 * telegram_send.php — fire-and-forget wysylka Telegram
 * Wejscie: stdin JSON:
 *   {"type":"text","msg":"..."}
 *   {"type":"photo","url":"...","caption":"...","nodelay":true}
 *   {"type":"video","url":"...","caption":"...","thumb":"..."}
 *   {"type":"album","items":[{"url":"...","caption":"..."},{"url":"..."}]}  (2-10 zdjec, JEDNA wiadomosc)
 * Opcjonalne "html":true (photo/album) = caption z parse_mode HTML (np. link <a href>). Nadawca sam escapuje tekst.
 * Opcjonalne pole "chat": brak/"default" = TG_CHAT (prywatny, wyciszony),
 *   "alert" = TG_CHAT_ALERT (kanal alertow, NIEwyciszony). Nieznana wartosc = default.
 *   W ciszy nocnej (23-6, tymos_cisza_nocna) "alert" przechodzi TYLKO z "alarm":true — inaczej default.
 * Opcjonalne "alarm":true = to jest alarm (kamery/zalanie), ma prawo budzic w nocy.
 *
 * ZDJECIA: zawsze sendPhoto (obrazek w TRESCI wiadomosci). Wariant sendDocument (plik 1:1,
 *   pelne 2688x1520 bez kompresji) byl probowany 2026-07-29 i ODRZUCONY — w watku pokazuje sie
 *   jako zalacznik z mala miniatura zamiast zdjecia. W Telegramie nie da sie miec obu naraz.
 * FILMIKI (od 2026-09-01): sendVideo z supports_streaming. Uzywa tego face_scan.py — dokleja
 *   6-sekundowy klip SD z go2rtc do OSTATNIEJ wiadomosci o zdarzeniu. Opcjonalny "thumb" ustawia
 *   miniature (kadr twarzy), zeby w watku bylo widac KOGO dotyczy klip, jeszcze przed odtworzeniem.
 * "nodelay" (tylko photo): pomija sekunde zwloki. Ta sekunda ma sens przy pojedynczej klatce
 *   z alarm_notify (czlowiek ma wejsc w kadr), ale nie przy face_scan, ktory i tak wybral
 *   najlepszy kadr z 5-sekundowej serii — tam to czysta strata czasu w powiadomieniu.
 * Odpalane przez daemon tymos.py przez subprocess.Popen bez .wait() — daemon nie blokuje sie na HTTP.
 * Test CLI: echo '{"type":"text","msg":"hello"}' | php telegram_send.php
 */

require_once __DIR__ . '/lib/_log.inc.php';

$raw = stream_get_contents(STDIN);
if (!$raw) exit(1);

$input = json_decode($raw, true);
if (!$input || !isset($input['type'])) exit(1);

$token   = TG_TOKEN;
$isAlert = (($input['chat'] ?? '') === 'alert') && defined('TG_CHAT_ALERT');
// CISZA NOCNA (2026-09-10, user): 23-6 na Wazne przechodza TYLKO alarmy (kamery, zalanie — alarm_notify.php
// i face_scan.py daja "alarm":true). Reszta (czujnik znikl/wrocil, koordynator padl/restart, bledy z logu,
// grzalki, pstryk...) leci na wyciszony czat z prefiksem ksiezyca, zeby nie budzic. Rano normalnie.
if ($isAlert && empty($input["alarm"]) && tymos_cisza_nocna()) {
    $isAlert = false;
    if (isset($input["msg"]))     $input["msg"]     = "🌙 " . $input["msg"];
    if (isset($input["caption"])) $input["caption"] = "🌙 " . $input["caption"];
}
$chat    = $isAlert ? TG_CHAT_ALERT : TG_CHAT;
$type    = $input['type'];

function tg_post($url, $fields, $timeout) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp];
}

if ($type === 'text') {
    $msg = $input['msg'] ?? '';
    if ($msg === '') exit(0);
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    [$code, $resp] = tg_post($url, [
        'chat_id'    => $chat,
        'text'       => $msg,
        'parse_mode' => 'HTML',
    ], 10);
    if ($code !== 200) {
        tymos_log('ERROR', "Telegram text HTTP {$code}: " . substr($resp, 0, 200));
        exit(1);
    }
    exit(0);
}

function tg_fetch($url, $timeout = 10) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($data && $code === 200) ? $data : null;
}

if ($type === 'photo') {
    $photoUrl = $input['url']     ?? '';
    $caption  = $input['caption'] ?? '';
    if ($photoUrl === '') exit(0);

    // Opoznienie 1s zeby osoba miala szanse wejsc w kadr. face_scan.py podaje "nodelay",
    // bo wybiera najlepsza klatke z calej serii — czekanie nic tam nie dodaje, a opoznia
    // wiadomosc o rozpoznaniu, ktora ma byc jak najszybciej.
    if (empty($input['nodelay'])) sleep(1);

    $data = tg_fetch($photoUrl, 10);
    if (!$data) {
        tymos_log('ERROR', "Telegram photo: fetch failed url={$photoUrl}");
        exit(1);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'tg_f') . '.jpg';
    file_put_contents($tmp, $data);

    // ZAWSZE sendPhoto — zdjecie w TRESCI wiadomosci (inline). sendDocument dawalby plik 1:1
    // w pelnej rozdzielczosci, ale w watku wyglada jak zalacznik z mala okragla miniatura,
    // wiec user go odrzucil (2026-07-29). Cena: Telegram przekodowuje i skaluje (~1280 px).
    $name = 'snapshot_' . date('Ymd_His') . '.jpg';

    $fields = [
        'chat_id' => $chat,
        'photo'   => new CURLFile($tmp, 'image/jpeg', $name),
    ];
    if ($caption !== '') { $fields['caption'] = $caption; if (!empty($input['html'])) $fields['parse_mode'] = 'HTML'; }
    $url = "https://api.telegram.org/bot{$token}/sendPhoto";
    [$code, $resp] = tg_post($url, $fields, 30);
    @unlink($tmp);

    if ($code !== 200) {
        tymos_log('ERROR', "Telegram sendPhoto HTTP {$code}: " . substr($resp, 0, 200));
        exit(1);
    }
    exit(0);
}

// ALBUM (od 2026-09-07): sendMediaGroup — kilka zdjec w JEDNEJ wiadomosci (dzwonek + parking).
// Podpis pod albumem Telegram pokazuje tylko wtedy, gdy DOKLADNIE JEDNO zdjecie ma caption,
// dlatego alarm_notify daje caption tylko pierwszemu. Zdjecie, ktorego nie da sie pobrac,
// jest pomijane; gdy zostanie jedno, leci zwyklym sendPhoto, gdy zero — blad.
if ($type === 'album') {
    $items = $input['items'] ?? [];
    if (!is_array($items) || !$items) exit(0);
    if (empty($input['nodelay'])) sleep(1);

    $tmps = []; $media = []; $fields = ['chat_id' => $chat];
    foreach ($items as $it) {
        $u = $it['url'] ?? '';
        if ($u === '') continue;
        $data = tg_fetch($u, 10);
        if (!$data) { tymos_log('WARN', "Telegram album: fetch failed url={$u}"); continue; }
        $tmp = tempnam(sys_get_temp_dir(), 'tg_a') . '.jpg';
        file_put_contents($tmp, $data);
        $k = 'file' . count($tmps);
        $tmps[] = $tmp;
        $fields[$k] = new CURLFile($tmp, 'image/jpeg', $k . '.jpg');
        $m = ['type' => 'photo', 'media' => 'attach://' . $k];
        if (!empty($it['caption'])) { $m['caption'] = $it['caption']; if (!empty($input['html'])) $m['parse_mode'] = 'HTML'; }
        $media[] = $m;
    }
    if (!$media) { tymos_log('ERROR', 'Telegram album: zadnego zdjecia nie udalo sie pobrac'); exit(1); }

    if (count($media) === 1) {
        $url = "https://api.telegram.org/bot{$token}/sendPhoto";
        $f = ['chat_id' => $chat, 'photo' => $fields['file0']];
        if (!empty($media[0]['caption'])) { $f['caption'] = $media[0]['caption']; if (!empty($input['html'])) $f['parse_mode'] = 'HTML'; }
        [$code, $resp] = tg_post($url, $f, 30);
    } else {
        $fields['media'] = json_encode($media, JSON_UNESCAPED_UNICODE);
        $url = "https://api.telegram.org/bot{$token}/sendMediaGroup";
        [$code, $resp] = tg_post($url, $fields, 45);
    }
    foreach ($tmps as $t) @unlink($t);
    if ($code !== 200) {
        tymos_log('ERROR', "Telegram sendMediaGroup HTTP {$code}: " . substr($resp, 0, 200));
        exit(1);
    }
    exit(0);
}

if ($type === 'video') {
    $videoUrl = $input['url']     ?? '';
    $caption  = $input['caption'] ?? '';
    $thumbUrl = $input['thumb']   ?? '';
    if ($videoUrl === '') exit(0);

    // Bez sleep(1): klip jest juz nagrany, czekanie tylko opoznialoby wiadomosc.
    $data = tg_fetch($videoUrl, 30);
    if (!$data) {
        tymos_log('ERROR', "Telegram video: fetch failed url={$videoUrl}");
        exit(1);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'tg_v') . '.mp4';
    file_put_contents($tmp, $data);

    $fields = [
        'chat_id'            => $chat,
        'video'              => new CURLFile($tmp, 'video/mp4',
                                             'clip_' . date('Ymd_His') . '.mp4'),
        'supports_streaming' => 'true',
    ];
    if ($caption !== '') $fields['caption'] = $caption;

    // Miniatura: kadr twarzy. Telegram wymaga JPEG do 200 kB i do 320 px — face_scan.py
    // podaje juz przeskalowany. Brak miniatury NIE jest bledem, klip leci dalej.
    $tmpThumb = null;
    if ($thumbUrl !== '') {
        $t = tg_fetch($thumbUrl, 10);
        if ($t) {
            $tmpThumb = tempnam(sys_get_temp_dir(), 'tg_t') . '.jpg';
            file_put_contents($tmpThumb, $t);
            $fields['thumbnail'] = new CURLFile($tmpThumb, 'image/jpeg', 'thumb.jpg');
        }
    }

    $url = "https://api.telegram.org/bot{$token}/sendVideo";
    [$code, $resp] = tg_post($url, $fields, 60);
    @unlink($tmp);
    if ($tmpThumb) @unlink($tmpThumb);

    if ($code !== 200) {
        tymos_log('ERROR', "Telegram sendVideo HTTP {$code}: " . substr($resp, 0, 200));
        exit(1);
    }
    exit(0);
}

exit(1);
