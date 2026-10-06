#!/usr/bin/env php
<?php
/**
 * alarm_notify.php <cel> <polityka> [komunikat...]
 *
 * Powiadomienia Telegram z jednolita gramatyka: CEL mowi CO wyslac, POLITYKA mowi KIEDY i GDZIE.
 * Dzieki temu sama tresc akcji w panelu dokumentuje zachowanie — nie trzeba zagladac w kod.
 *
 * POZIOMY ALARMU — helper `alarm_zazbrojony`, czytany LIVE z SQL:
 *   0  rozbrojony  — dom zyje normalnie
 *   1  NOC         — dom zajety: alarmuja tylko zdarzenia ZEWNETRZNE + zalanie
 *   2  NIKOGO      — nikogo nie ma: alarmuje wszystko, takze ruch wewnatrz
 * Poziom 2 zawiera 1 (zewnetrzne alarmuja na obu).
 *
 * CEL:
 *   parking | ogrod | dzwonek_gong | dzwonek_osoba      zdjecie z kamery
 *   garaz_otwarte | garaz_zamkniete                     tekst gotowy
 *   przedsionek_otwarte                                zdjecie z kamery dzwonka
 *   przedsionek_zamkniete                              tekst gotowy
 *   zalanie_techniczny | zalanie_kuchnia                tekst gotowy
 *   text                                                komunikat z dalszych argumentow
 *
 * POLITYKA:
 *   wewn     tylko poziom 2 (NIKOGO)            -> kanal "Wazne". Ponizej CISZA.
 *   zewn     poziom >= 1 (NOC lub NIKOGO)       -> kanal "Wazne". Przy rozbrojonym CISZA.
 *   always   zawsze, niezaleznie od poziomu     -> kanal "Wazne". Zdarzenia krytyczne same
 *                                                  w sobie (ZALANIE).
 *   auto     zawsze, ale kanal wg poziomu       -> >=1: "Wazne", 0: czat domyslny (wyciszony).
 *   armed    alias `wewn` (stare akcje sprzed podzialu na poziomy)
 *
 * Prefix "🚨 ALARM: " dokladany wtedy i tylko wtedy, gdy wiadomosc idzie na kanal "Wazne".
 *
 * DEDUPLIKACJA: cele z 'dedup' (garaz, przedsionek — otwarte/zamkniete) milcza, gdy stan sie nie zmienil —
 * chroni przed republishem stanow przez Z2M po restarcie koordynatora. Stan w `settings`.
 *
 * SERIA KLATEK: cele z 'burst' (parking, ogrod) przy CZYNNYM alarmie wysylaja `burst` zdjec
 * co BURST_GAP sekund (6 x 5 s = ~pol minuty podgladu, wieksza szansa na twarz i widac kierunek
 * ruchu). Przy rozbrojonym leci jedna klatka.
 *
 * TWARZE: cele z 'face' (parking, ogrod, dzwonek_osoba) odpalaja dodatkowo utils/face_scan.py —
 * osobny proces, ktory przez ~5 s zbiera klatki, wybiera najlepsza twarz i wysyla ja osobno.
 * Nie zmienia to niczego w zachowaniu samego alarmu; gdy Pythona/modeli nie ma, po prostu nic
 * nie przychodzi i alarm dziala jak dotad.
 *
 * GLOSOWKA NA TABLECIE: INSERT do tymos_sounds gdy cel ma 'snd' i wiadomosc idzie na kanal
 * "Wazne" — czyli dokladnie ta sama bramka co prefix ALARM. Przy rozbrojonym tablet milczy
 * (inaczej kazde przejscie domownika przed kamera parkingu gadaloby do salonu).
 *
 * Zazbrojenie dziala natychmiast (helper z SQL, nie z cache demona) — bez flagi tymos_reload
 * i bez restartu demona.
 *
 * Akcje typu script (tymos.py robi script.split(), argumenty przechodza):
 *   {"type":"script","script":"/opt/tymos/actions/alarm_notify.php parking auto"}
 *   {"type":"script","script":"/opt/tymos/actions/alarm_notify.php text wewn WC — ruch"}
 * Test CLI: php alarm_notify.php zalanie_kuchnia always
 */

require_once __DIR__ . '/lib/_log.inc.php';

// src = stream go2rtc (z /api/streams). Zawsze _hd: parking 2688x1520, basen (C320WS) 2560x1440, doorbell 2560x1920
// (doorbell_sd ma tylko 640x480 — nie uzywac). txt w konwencji "Miejsce — co".
// snd = nazwa dzwieku w tymos_sounds = plik snd/<nazwa>.mp3 (kiosk bierze liste z katalogu sam).
// burst = ile klatek wyslac po sobie GDY ALARM CZYNNY (patrz BURST_GAP nizej); brak = jedna.
const BURST_GAP = 5;   // sekund miedzy klatkami serii
// Adres TymOS z telefonu i Maka przez Tailscale. MUSI byc pelna nazwa MagicDNS: Telegram nie robi
// linku z adresu bez TLD (https://tymos/ pokazal sie jako zwykly tekst, 2026-09-07). Na iOS wewnetrzna
// przegladarka Telegrama nie akceptuje samopodpisanego certu -> docelowo cert Let's Encrypt z
// `tailscale cert` (wymaga wlaczenia HTTPS Certificates w panelu Tailscale) + vhost w Apache.
const TYMOS_URL = 'https://' . TYMOS_HOST_VPN . '/';
const LAST_FACE_MAX_AGE = 300;   // sekund — starsza "ostatnia twarz" nie ma juz zwiazku z dzwonkiem

$targets = [
    'parking'            => ['src' => 'parking_hd',  'txt' => 'Parking — osoba',                'snd' => 'alarm_parking', 'burst' => 6, 'face' => 'parking_hd'],
    // ROSZADA 2026-09-06: kadr na ogrod/basen daje kamera .145 (basen_*). Glosowka zostaje `alarm_ogrod`
    // („Wykryto osobę w ogrodzie.") — pliku alarm_basen.mp3 nie ma; gdy powstanie, podmienic 'snd'.
    'basen'              => ['src' => 'basen_hd',    'txt' => 'Basen — osoba',                  'snd' => 'alarm_ogrod',   'burst' => 6, 'face' => 'basen_hd'],
    // 'ogrod' = kamera .243 na przyszlym maszcie — dzis OFFLINE, zadna akcja tego celu nie wola.
    'ogrod'              => ['src' => 'ogrod_hd',    'txt' => 'Ogród — osoba',                  'snd' => 'alarm_ogrod',   'burst' => 6, 'face' => 'ogrod_hd'],
    // dzwonek bez glosowki — user ma fizyczne dzwonki w gniazdkach w dwoch miejscach w domu
    // 'extra' = DRUGIE zdjecie z innej kamery, tuz po glownym (2026-09-07, user): kurier dzwoni i wraca
    // do auta zanim zlapie go kamera dzwonka — po samochodzie na parkingu widac, kto to byl.
    'dzwonek_gong'       => ['src' => 'doorbell_hd', 'txt' => 'Dzwonek — ktoś dzwoni do drzwi', 'wazne' => true, 'last_face' => true,
                             'extra' => ['src' => 'parking_hd', 'txt' => 'Parking w chwili dzwonka'],
                             'link'  => ['url' => TYMOS_URL, 'txt' => 'Odbierz w TymOS']],   // link w podpisie (HTML)
    'dzwonek_osoba'      => ['src' => 'doorbell_hd', 'txt' => 'Dzwonek — osoba przy drzwiach', 'face' => 'doorbell_hd'],
    'garaz_otwarte'      => ['txt' => 'Garaż — drzwi OTWARTE',  'snd' => 'alarm_garaz', 'dedup' => 'garaz'],
    'garaz_zamkniete'    => ['txt' => 'Garaż — drzwi zamknięte', 'dedup' => 'garaz'],   // zamkniecie = tylko slad w kanale
    // kamera dzwonka patrzy na drzwi wejsciowe — jedna klatka w chwili otwarcia (user 2026-09-19).
    // Bez 'burst' i bez 'face': ma byc widac kto wchodzi, nie pol minuty podgladu.
    'przedsionek_otwarte'   => ['src' => 'doorbell_hd', 'txt' => 'Przedsionek — drzwi OTWARTE',   'snd' => 'alarm_przedsionek', 'dedup' => 'przedsionek',
                                'extra' => ['src' => 'parking_hd', 'txt' => 'Parking w chwili otwarcia']],   // auto zlodzieja / co sie dzialo pod domem
    'przedsionek_zamkniete' => ['txt' => 'Przedsionek — drzwi zamknięte', 'dedup' => 'przedsionek'],   // zamkniecie = tylko slad w kanale
    'zalanie_techniczny' => ['txt' => 'Techniczny — ZALANIE!',  'snd' => 'alarm_zalanie_techniczny'],
    'zalanie_kuchnia'    => ['txt' => 'Kuchnia — ZALANIE!',     'snd' => 'alarm_zalanie_kuchnia'],
];

$target = $argv[1] ?? '';
$policy = $argv[2] ?? '';

if ($target !== 'text' && !isset($targets[$target])) { tymos_log('ERROR', "alarm_notify: nieznany cel '{$target}'"); exit(1); }
if ($policy === 'armed') $policy = 'wewn';   // zgodnosc ze starymi akcjami
if (!in_array($policy, ['wewn', 'zewn', 'always', 'auto'], true)) { tymos_log('ERROR', "alarm_notify: nieznana polityka '{$policy}' (cel {$target})"); exit(1); }

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'alarm_notify: DB ' . $db->connect_error); exit(1); }

$r     = $db->query("SELECT value FROM helpers WHERE name='alarm_zazbrojony' LIMIT 1");
$level = ($r && $row = $r->fetch_row()) ? (int)$row[0] : 0;

// DEDUPLIKACJA STANU — cele z 'dedup' (para otwarte/zamkniete dzieli jeden klucz).
// Z2M po restarcie koordynatora republikuje stany urzadzen, a `match_trigger` w tymos.py porownuje
// TYLKO wartosc pusha i nie zna poprzedniej — wiec kontaktron wyslal „Garaż — drzwi zamknięte"
// mimo ze nic sie nie ruszylo (incydent 2026-08-01 03:54:12, 13 s po dongle_reboot all).
// Ostatni znany stan trzymamy w `settings` (przezywa restart demona i Pi, nie smieci w helperach).
// Zapisujemy go ZAWSZE, takze przy rozbrojonym, ktory nic nie wysyla — inaczej po zazbrojeniu
// pierwsze powtorzenie nie mialoby z czym sie porownac.
if (!empty($targets[$target]['dedup'])) {
    $key  = 'alarm_state_' . $targets[$target]['dedup'];
    $r    = $db->query("SELECT v FROM settings WHERE k='" . $db->real_escape_string($key) . "' LIMIT 1");
    $prev = ($r && $row = $r->fetch_row()) ? $row[0] : '';
    if ($prev === $target) exit(0);   // ten sam stan co ostatnio = powtorka, nie zdarzenie
    $db->query("INSERT INTO settings (k, v) VALUES ('" . $db->real_escape_string($key) . "', '"
             . $db->real_escape_string($target) . "') ON DUPLICATE KEY UPDATE v = VALUES(v)");
}

// SLUCHAWKA NA KAMERZE: dzwonek stempluje `settings.doorbell_last_ring`, a api/doorbell_check
// czyta ten stempel i przez 60 s kaze pulsowac zielonej sluchawce w widgecie dzwonka.
// Stempel leci PRZED polityka alarmu, bo ktos dzwoni do drzwi niezaleznie od tego, czy alarm
// jest zazbrojony — a dwa cele dzwonka nie maja 'snd', wiec nie zostawiaja sladu w tymos_sounds.
if (strpos($target, 'dzwonek') === 0) {
    $db->query("INSERT INTO settings (k, v) VALUES ('doorbell_last_ring', '" . time() . "') "
             . "ON DUPLICATE KEY UPDATE v = VALUES(v)");
}

// Polityka -> czy wysylamy i na ktory czat
if ($policy === 'wewn' && $level < 2) exit(0);
if ($policy === 'zewn' && $level < 1) exit(0);
$toAlert = ($policy === 'always') ? true : ($level >= 1);

// 'wazne' => cel ZAWSZE idzie na kanal "Wazne", niezaleznie od poziomu alarmu (dzwonek: ktos stoi
// pod drzwiami teraz i chce, zeby mu otworzyc — to nie moze wpasc do wyciszonego czatu).
// Prefiks "ALARM" go przy tym NIE dotyczy: dzwonek to nie wlamanie, tylko gosc.
$wazne = !empty($targets[$target]['wazne']);
if ($wazne) $toAlert = true;

$prefix = ($toAlert && !$wazne) ? '🚨 ALARM: ' : '';

// telegram_send.php czyta JSON ze stdin. pclose czeka na wyslanie — nieszkodliwe, bo demon
// odpala nas przez Popen bez .wait(), wiec nikt na nas nie czeka.
function tg_send($payload) {
    $h = popen('php ' . __DIR__ . '/telegram_send.php > /dev/null 2>&1', 'w');
    if (!$h) { tymos_log('ERROR', 'alarm_notify: popen telegram_send nie powiodl sie'); exit(1); }
    // Wszystko, co alarm_notify pcha na Wazne, to alarm — ma prawo budzic w ciszy nocnej (telegram_send).
    if (($payload["chat"] ?? "") === "alert") $payload["alarm"] = true;
    fwrite($h, json_encode($payload, JSON_UNESCAPED_UNICODE));
    pclose($h);
}

// Glosowka na tablet PRZED wysylka na Telegram (tg_send czeka na API Telegrama do 30 s,
// a mowa w domu ma zdazyc od razu).
if ($toAlert && $target !== 'text' && !empty($targets[$target]['snd'])) {
    $db->query("INSERT INTO tymos_sounds (sound) VALUES ('" . $db->real_escape_string($targets[$target]['snd']) . "')");
}

if ($target === 'text') {
    $msg = trim(implode(' ', array_slice($argv, 3)));
    if ($msg === '') { tymos_log('ERROR', 'alarm_notify text: brak komunikatu'); exit(1); }
    $payload = ['type' => 'text', 'msg' => $prefix . $msg];
    if ($toAlert) $payload['chat'] = 'alert';
    tg_send($payload);
    exit(0);
}

if (!isset($targets[$target]['src'])) {
    $payload = ['type' => 'text', 'msg' => $prefix . $targets[$target]['txt']];
    if ($toAlert) $payload['chat'] = 'alert';
    tg_send($payload);
    exit(0);
}

// ROZPOZNAWANIE TWARZY (cele z kluczem 'face'): osobny proces w tle — Python + OpenCV,
// utils/face_scan.py. Zbiera przez ~5 s klatki JPEG z go2rtc, wybiera najostrzejsza twarz
// i wysyla WLASNA wiadomosc z wycinkiem oraz imieniem albo "NIEZNANA".
// Odpalane PRZED seria zdjec, zeby mielil klatki w tym samym czasie, gdy leca zwykle fotki.
// `nohup ... &` — nie czekamy na niego, on sam ma flock i nie odpali sie dwa razy naraz.
// Kanal ten sam, ktory wybrala polityka alarmu, zeby twarz nie krzyczala glosniej niz alarm.
if (!empty($targets[$target]['face'])) {
    exec('nohup /opt/tymos/venv-cv/bin/python3 /opt/tymos/utils/face_scan.py '
        . escapeshellarg($targets[$target]['face']) . ' '
        . escapeshellarg($targets[$target]['txt']) . ' '
        . escapeshellarg('--chat=' . ($toAlert ? 'alert' : 'default'))
        . ' > /dev/null 2>&1 &');
}

// SERIA KLATEK przy czynnym alarmie: zamiast jednego zdjecia leci `burst` klatek co BURST_GAP
// sekund — ~pol minuty podgladu, wieksza szansa na twarz i widac kierunek ruchu (skad dokad).
// Przy rozbrojonym ($toAlert=false) zostaje JEDNA klatka, zeby nie zasypywac wyciszonego czatu.
// Kadencja liczona od czasu startu (deadline), zeby czas wysylki na Telegram nie rozjezdzal odstepow.
// Skrypt zyje wtedy ~35 s — nieszkodliwe, bo demon odpala go przez Popen bez wait, a akcje maja
// min_interval 60 s. SWIADOMIE nie sendMediaGroup: album to jedno powiadomienie, ale nic nie
// dotarloby przed zebraniem wszystkich klatek (~30 s ciszy przy alarmie).
$burst = ($toAlert && !empty($targets[$target]['burst'])) ? (int)$targets[$target]['burst'] : 1;
$url   = 'http://localhost:1984/api/frame.jpeg?src=' . $targets[$target]['src'];
$start = microtime(true);

// OSTATNIA WYKRYTA TWARZ (cele z 'last_face'): utils/face_scan.py zostawia w tmpfs
// `face_last.json` ze sciezka do wycinka i wynikiem rozpoznania. Przy dzwonku dokladamy ten
// wycinek jako drugie zdjecie — czesto twarz zostala zlapana chwile WCZESNIEJ, gdy czlowiek
// podchodzil, a w momencie dzwonienia patrzy juz w domofon albo stoi bokiem.
// Wysylane PO zdjeciu z dzwonka, zeby glowny kadr byl pierwszy.
$last_face = null;
if (!empty($targets[$target]['last_face'])) {
    $raw = @file_get_contents('/tmp/tymos/face_last.json');
    $d   = $raw ? json_decode($raw, true) : null;
    if ($d && isset($d['plik'], $d['ts'])) {
        $wiek = time() - (int)$d['ts'];
        if ($wiek >= 0 && $wiek <= LAST_FACE_MAX_AGE && @is_file('/tmp/tymos/' . $d['plik'])) {
            $last_face = $d;
            $last_face['wiek'] = $wiek;
        }
    }
}

for ($i = 1; $i <= $burst; $i++) {
    if ($i > 1) {
        $wait = ($start + ($i - 1) * BURST_GAP) - microtime(true);
        if ($wait > 0) usleep((int)($wait * 1000000));
    }
    $caption = $prefix . $targets[$target]['txt'] . ($burst > 1 ? " ({$i}/{$burst})" : '');
    if ($i === 1 && !empty($targets[$target]['extra'])) {
        // DRUGA KAMERA (cele z 'extra'): pierwsza klatka idzie jako ALBUM z kadrem z drugiej kamery
        // — jedna wiadomosc, dwa zdjecia (user 2026-09-07). Caption tylko na pierwszym, inaczej
        // Telegram nie pokaze podpisu pod albumem. Tekst 'extra.txt' zostaje w konfiguracji jako opis.
        // 'link' = doklejony do podpisu jako <a href> (HTML). Tekst podpisu escapowany, bo od tej
        // chwili Telegram parsuje go jako HTML.
        $cap = htmlspecialchars($caption, ENT_QUOTES, 'UTF-8');
        if (!empty($targets[$target]['link'])) {
            $cap .= "\n" . '<a href="' . htmlspecialchars($targets[$target]['link']['url'], ENT_QUOTES, 'UTF-8') . '">'
                  . htmlspecialchars($targets[$target]['link']['txt'], ENT_QUOTES, 'UTF-8') . '</a>';
        }
        $payload = ['type' => 'album', 'html' => true, 'items' => [
            ['url' => $url, 'caption' => $cap],
            ['url' => 'http://localhost:1984/api/frame.jpeg?src=' . $targets[$target]['extra']['src']],
        ]];
    } else {
        $payload = ['type' => 'photo', 'url' => $url, 'caption' => $caption];
    }
    if ($toAlert) $payload['chat'] = 'alert';
    tg_send($payload);
}

if ($last_face) {
    $kto = $last_face['znany']
         ? $last_face['imie'] . ' — pewnosc ' . number_format((float)$last_face['sim'], 2)
         : 'NIEZNANA';
    $min = (int)round($last_face['wiek'] / 60);
    $kiedy = $last_face['wiek'] < 90 ? $last_face['wiek'] . ' s temu' : $min . ' min temu';
    $payload = [
        'type'    => 'photo',
        'url'     => 'https://127.0.0.1/snap/' . $last_face['plik'],
        'caption' => "TWARZ SPRZED CHWILI: {$kto}\n"
                   . "ZLAPANA: {$kiedy}, kamera {$last_face['stream']}",
    ];
    if ($toAlert) $payload['chat'] = 'alert';
    tg_send($payload);
}
