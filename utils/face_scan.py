#!/opt/tymos/venv-cv/bin/python3
"""
face_scan.py — rozpoznawanie twarzy z kamery, w pamieci, BEZ zapisu na karte SD.

Uzycie:
    face_scan.py <stream> [etykieta] [--chat=alert]   # normalna praca, wysyla na Telegram
    face_scan.py <stream> --bench                     # pomiar obciazenia, NIC nie wysyla

DWA TRYBY PRACY (od 2026-09-01):
  * Z DEMONEM (domyslnie) — jesli istnieje gniazdo /tmp/tymos/face.sock, ten skrypt jest tylko
    cienkim klientem: wysyla zadanie i konczy. Skanuje daemons/face_daemon.py, ktory trzyma
    YuNet, SFace i baze twarzy w pamieci. Oszczedza ~1-1,5 s na kazdym zdarzeniu
    (`import cv2` + wczytanie 37 MB SFace), czyli czas, w ktorym czlowiek wychodzi z kadru.
  * LOKALNIE (awaryjnie) — brak gniazda albo martwy demon: skrypt laduje modele sam i skanuje,
    dokladnie jak przed wprowadzeniem demona. Dzieki temu awaria demona nic nie psuje,
    a alarm_notify.php nie wymagal zadnej zmiany. `--bench` zawsze idzie ta sciezka.

    <stream>  = nazwa streamu go2rtc, np. doorbell_hd, parking_hd, basen_hd
    etykieta  = tekst doklejany do podpisu, np. "Dzwonek"
    --chat    = alert (kanal "Wazne") albo default (czat wyciszony). Bez tego: default.
                alarm_notify.php podaje to samo, co wybral dla swojego zdjecia.

JAK TO DZIALA (schemat ustalony z userem):
  0. ZIMNY START HD: streamy `_hd` NIE sa w autostart go2rtc, wiec pierwsza klatka wymaga
     otwarcia sesji RTSP i doczekania na klatke kluczowa — realnie 1-3 s. Przez ten czas czlowiek
     moze wyjsc z kadru. Dlatego skrypt od pierwszej sekundy ciagnie z `_sd` (ten JEST w autostart,
     bo leci na iPada, wiec oddaje klatke natychmiast), a rownolegle w tle rozgrzewa `_hd`
     i przelacza sie na niego, gdy tylko odpowie. Do rankingu ida klatki z OBU zrodel.
  1. Klatki JPEG z go2rtc (`/api/frame.jpeg`) przez GRAB_SECONDS sekund, GRAB_FPS na sekunde,
     pobierane we WLASNYM WATKU (producent) — patrz `skanuj`. Zadnego dekodowania RTSP po naszej
     stronie: go2rtc i tak trzyma te streamy zywe dla podgladu na iPada.
  2. Kazda klatke przerabia OD RAZU i wyrzuca z pamieci — nie trzymamy bufora wideo.
     Zostaja tylko wyciete twarze 112x112, czyli kilkadziesiat kB zamiast setek MB.
  3. Detekcja leci na POMNIEJSZONEJ klatce (DET_WIDTH), a wycinek twarzy bierzemy
     z ORYGINALU w pelnej rozdzielczosci — tanio i bez utraty jakosci twarzy.
  4. Rozpoznawanie leci na KAZDEJ wykrytej twarzy (19 ms/szt., pomiar 2026-08-28), a twarze
     grupuja sie po podobienstwie wektorow w OSOBY — dziesiec klatek jednego czlowieka to jeden
     rekord, a dwoch obcych to dwa. Stad wiadomo, ilu ludzi bylo w kadrze.
  5. Wynik: kadry wszystkich osob sklejone w jedno zdjecie w /tmp/tymos (tmpfs = RAM)
     + JEDNA, najwyzej DWIE wiadomosci na Telegram (szybka po trafie, koncowa tylko gdy
     zmienia wniosek). Szczegoly reguly w docstringu `skanuj`.

CZEGO NIE ROBI: nie ma bufora wyprzedzajacego (klatek SPRZED zdarzenia) — wymagalby demona
mielacego strumien caly czas, a to staly koszt CPU. Do dolozenia, gdyby okazalo sie potrzebne.

WYMAGANIA (instalacja: utils/face_setup.sh):
    /opt/tymos/venv-cv  — venv z opencv-python-headless (SWIADOMIE nie python3-opencv z apt:
                          apt ciagnie 121 pakietow, w tym Qt5, VTK i OpenMPI, na bezglowy serwer)
    modele YuNet + SFace w /opt/tymos/faces/

Baza twarzy: /opt/tymos/faces/embeddings.json (buduje ja utils/face_enroll.py).
Brak bazy = skrypt dziala dalej, tylko zamiast imienia pisze "NIEZNANA".
"""

import fcntl
import json
import queue
import socket
import threading
import os
import subprocess
import sys
import time
import urllib.request

# OpenCV i numpy ladowane LENIWIE. `import cv2` na Pi kosztuje ~1 s, a gdy dziala
# face_daemon.py ten skrypt jest tylko cienkim klientem — wysyla zadanie na gniazdo
# i konczy, wiec nie potrzebuje ich wcale.
np = None
cv2 = None


def zaladuj_cv():
    """Doladowuje numpy i cv2 do zmiennych modulu. Wolane raz, w demonie albo w trybie lokalnym."""
    global np, cv2
    if cv2 is None:
        import numpy as _np
        import cv2 as _cv2
        np, cv2 = _np, _cv2

# --- konfiguracja ---------------------------------------------------------

GO2RTC     = 'http://127.0.0.1:1984/api/frame.jpeg?src='
TMP_DIR    = '/tmp/tymos'
FACES_DIR  = '/opt/tymos/faces'
EMB_FILE   = FACES_DIR + '/embeddings.json'
MODEL_DET  = FACES_DIR + '/face_detection_yunet_2023mar.onnx'
MODEL_REC  = FACES_DIR + '/face_recognition_sface_2021dec.onnx'
TG_SEND    = '/opt/tymos/actions/telegram_send.php'
SOCK_FILE  = TMP_DIR + '/face.sock'   # gniazdo demona; brak = tryb lokalny

GRAB_SECONDS  = 5      # ile sekund zbierac klatki po wyzwoleniu
GRAB_FPS      = 2      # klatek na sekunde (2 = 10 klatek na 5 s)
DET_WIDTH     = 960    # do tej szerokosci skalujemy klatke na czas DETEKCJI
MIN_FACE      = 50     # px szerokosci twarzy W ORYGINALE. Bylo 70 — za duzo na parking: pierwszy
                       # test na zywo (2026-08-28) zlapal osobe, ale ZERO twarzy, bo z kilku metrow
                       # w kadrze 2688 px twarz ma realnie 40-60 px.
DET_SCORE     = 0.8    # prog pewnosci detektora twarzy
# POTOKOWANIE I WYSYLKA (2026-09-01). TOP_CROPS znikl: istnial, bo rozpoznawanie uwazalismy za
# drogie, a pomiar pokazal 19 ms na twarz. Teraz liczymy KAZDA wykryta twarz — przy okazji
# zalatwia to kilka osob w kadrze, czego wybieranie 3 najlepszych wycinkow nie obslugiwalo.
EARLY_SIM     = 0.50   # dopasowanie, przy ktorym wysylamy PIERWSZA wiadomosc nie czekajac na koniec.
                       # Wyraznie nad COS_THRESHOLD (0.363), bo za wczesna pomylka w imieniu jest
                       # gorsza niz sekunda zwloki — a druga wiadomosc i tak by ja poprawiala.
POPRAWA_SIM   = 0.05   # o tyle musi urosnac dopasowanie, zeby DRUGA wiadomosc miala sens
QUEUE_MAX     = 4      # klatek w kolejce. Konsument (54 ms) jest ~8x szybszy od producenta (450 ms),
                       # wiec kolejka stoi pusta; to tylko zawor bezpieczenstwa, zeby przy zadyszce
                       # nie trzymac dziesieciu klatek 2K w RAM. Pelna kolejka = gubimy klatke,
                       # NIE opozniamy producenta — o to cala ta przebudowa.
MONTAZ_MAX    = 4      # ile twarzy najwyzej sklejamy w jedno zdjecie
MONTAZ_H      = 420    # px — wysokosc kazdego kadru w montazu

# KLIP WIDEO doklejany do OSTATNIEJ wiadomosci o zdarzeniu (2026-09-01).
CLIP_SECONDS  = 6      # dlugosc nagrania; startuje razem ze skanem
CLIP_RTSP     = 'rtsp://127.0.0.1:8554/'
CLIP_MIN_KB   = 40     # mniejszy plik = ffmpeg nic sensownego nie zlapal, nie wysylamy
THUMB_H       = 320    # px — miniatura klipu (Telegram: JPEG do 200 kB, do 320 px)
# Nagrywamy z PRZEKODOWANIEM (libx264 ultrafast), nie `-c copy`. Przy kopiowaniu plik zaczyna
# sie od pierwszej klatki kluczowej kamery, a przy GOP 2-4 s delikwent zdazy wyjsc z kadru.
# Przekodowanie na SD to ulamek rdzenia, a pierwsza klatka wyjscia jest ZAWSZE kluczowa.
COS_THRESHOLD = 0.363  # podobienstwo cosinusowe SFace: >= to ta sama osoba. Wartosc zalecana przez
                       # OpenCV. Mialem tu 0.40 „na wszelki wypadek", ale pomiar bazy pokazal, ze
                       # nawet zdjecia TEJ SAMEJ osoby z tego samego zrodla schodza do 0.45 —
                       # kadr z kamery bedzie nizej, wiec zapas byl w zla strone.
KEEP_FILES    = 3600   # sekund — starsze face_*.jpg z tmpfs kasujemy przy starcie

# KADROWANIE WYCINKA NA TELEGRAM (nie ma wplywu na rozpoznawanie — ono i tak leci na 112x112).
# Ciasny kadr na sama twarz jest bezuzyteczny dla czlowieka: przy twarzy 65 px dostaje sie
# rozpikselowany kwadracik. Bierzemy wiec spory zapas dookola, a w dol WYRAZNIE wiecej —
# przy osobie nierozpoznanej sylwetka i ubranie mowia wiecej niz sama twarz.
# Mnozniki liczone od rozmiaru twarzy, wiec kadr skaluje sie z odlegloscia.
MARGIN_X   = 2.8   # x szerokosc twarzy — w lewo i w prawo
MARGIN_TOP = 1.7   # x wysokosc twarzy — w gore
MARGIN_BOT = 4.5   # x wysokosc twarzy — w dol (tulow, ubranie, co niesie)
MIN_CROP_W = 620   # px — ponizej tego kadr rozszerzamy; w kadrze 2688 px jest z czego brac

# --- pomocnicze -----------------------------------------------------------

def temp_c():
    """Temperatura SoC w stopniach. Zwraca None, gdy sie nie da odczytac."""
    try:
        with open('/sys/class/thermal/thermal_zone0/temp') as f:
            return int(f.read().strip()) / 1000.0
    except Exception:
        return None


def throttled():
    """Slowo throttled z vcgencmd (0x0 = czysto). Zwraca '?' gdy sie nie da."""
    try:
        out = subprocess.run(['vcgencmd', 'get_throttled'], capture_output=True, text=True, timeout=5)
        return out.stdout.strip().split('=')[-1]
    except Exception:
        return '?'


def pobierz_klatke(stream, timeout=5):
    """Jedna klatka JPEG z go2rtc -> obraz BGR. None gdy sie nie udalo."""
    try:
        with urllib.request.urlopen(GO2RTC + stream, timeout=timeout) as r:
            raw = r.read()
    except Exception:
        return None
    if not raw or len(raw) < 1000:
        return None
    buf = np.frombuffer(raw, dtype=np.uint8)
    return cv2.imdecode(buf, cv2.IMREAD_COLOR)


def ostrosc(img):
    """Wariancja Laplace'a — im wiecej, tym ostrzejszy obraz. Rozmyte klatki maja male wartosci."""
    szary = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    return float(cv2.Laplacian(szary, cv2.CV_64F).var())


def wczytaj_baze():
    """Baza wektorow twarzy: {'imie': [[128 floatow], ...]}. Brak pliku = pusta baza."""
    if not os.path.exists(EMB_FILE):
        return {}
    try:
        with open(EMB_FILE) as f:
            surowe = json.load(f)
    except Exception:
        return {}
    baza = {}
    for imie, wektory in surowe.items():
        baza[imie] = [np.array(w, dtype=np.float32).reshape(1, -1) for w in wektory]
    return baza


def dopasuj(rec, wektor, baza):
    """Najlepsze dopasowanie do bazy. Zwraca (imie, podobienstwo) albo (None, 0.0)."""
    najlepsze_imie, najlepsze = None, 0.0
    for imie, wektory in baza.items():
        for w in wektory:
            sim = rec.match(wektor, w, cv2.FaceRecognizerSF_FR_COSINE)
            if sim > najlepsze:
                najlepsze_imie, najlepsze = imie, sim
    return najlepsze_imie, najlepsze


def sprzataj_tmpfs():
    """Kasuje stare wycinki twarzy z tmpfs — to RAM, nie ma sensu ich zbierac."""
    teraz = time.time()
    try:
        for nazwa in os.listdir(TMP_DIR):
            # Klipy kasuje usun_klip() od razu po wysylce; tutaj tylko zamiatanie po awarii,
            # zeby nieudane nagranie nie zostalo w RAM na zawsze.
            if not nazwa.startswith(('face_', 'clip_', 'thumb_')):
                continue
            if not nazwa.endswith(('.jpg', '.mp4')):
                continue
            sciezka = os.path.join(TMP_DIR, nazwa)
            if teraz - os.path.getmtime(sciezka) > KEEP_FILES:
                os.unlink(sciezka)
    except Exception:
        pass


def telegram(payload):
    """Wysylka przez telegram_send.php — token i wybor kanalu zostaja w jednym miejscu."""
    # Kanal alert dostajemy tylko od alarm_notify (czynny alarm) — to alarm, ma prawo budzic w ciszy nocnej.
    if payload.get('chat') == 'alert':
        payload['alarm'] = True
    try:
        p = subprocess.Popen(['php', TG_SEND], stdin=subprocess.PIPE,
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        p.communicate(json.dumps(payload, ensure_ascii=False).encode('utf-8'), timeout=60)
    except Exception:
        pass


def log(poziom, msg):
    """Log do tabeli `log` w TymOS przez ten sam mechanizm co reszta akcji."""
    # UWAGA: `php -r` przyjmuje KOD BEZ tagu <?php — z tagiem leci parse error i log przepada
    # po cichu (zlapane 2026-08-28 przy pierwszym tescie na zywo).
    kod = ('require_once "/opt/tymos/actions/lib/_log.inc.php"; '
           'tymos_log($argv[1], $argv[2]);')
    try:
        subprocess.run(['php', '-r', kod, poziom, msg], timeout=10,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except Exception:
        pass


# --- glowna czesc ---------------------------------------------------------
def przez_demona(stream, etykieta, chat):
    """Oddaje zadanie demonowi face_daemon.py. True = przyjal (albo byl zajety), koniec roboty.

    Zysk: klient nie importuje cv2 ani nie czyta 37 MB SFace — te ~1-1,5 s narzutu na KAZDE
    zdarzenie siedzialo dotad w kazdym wywolaniu i nie bylo nawet widoczne w --bench,
    bo `import cv2` leci przed pomiarem czasu.
    Brak gniazda albo martwy demon = False, wtedy dzialamy po staremu, lokalnie.
    """
    if not os.path.exists(SOCK_FILE):
        return False
    try:
        s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        s.settimeout(5)
        s.connect(SOCK_FILE)
        s.sendall(json.dumps({'stream': stream, 'etykieta': etykieta,
                              'chat': chat}, ensure_ascii=False).encode('utf-8') + b'\n')
        odp = s.recv(64).decode('utf-8', 'replace').strip()
        s.close()
    except Exception:
        return False
    # 'busy' = trwa inny skan. Odpuszczamy po cichu, dokladnie jak robil flock w trybie lokalnym.
    return odp in ('ok', 'busy')


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        return 1

    stream   = sys.argv[1]
    bench    = '--bench' in sys.argv[2:]
    etykieta = ''
    chat     = 'default'
    for a in sys.argv[2:]:
        if a == '--bench':
            continue
        if a.startswith('--chat='):
            chat = a.split('=', 1)[1]
            continue
        etykieta = a

    # SZYBKA SCIEZKA: jesli chodzi demon, oddaj mu robote i wyjdz. --bench zawsze lokalnie,
    # bo ma mierzyc pelny koszt jednorazowego uruchomienia, razem z ladowaniem modeli.
    if not bench and przez_demona(stream, etykieta, chat):
        return 0

    zaladuj_cv()

    # Jeden skan naraz. Kolejne wyzwolenie w trakcie trwajacego skanu odpuszcza po cichu —
    # inaczej seria ruchu odpalilaby kilka skanow rownolegle i zajechala procesor.
    blokada = open(os.path.join(TMP_DIR, 'face_scan.lock'), 'w')
    try:
        fcntl.flock(blokada, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        return 0

    for plik, opis in ((MODEL_DET, 'detektor YuNet'), (MODEL_REC, 'model SFace')):
        if not os.path.exists(plik):
            log('ERROR', f'face_scan: brak modelu ({opis}): {plik}')
            return 1

    det = cv2.FaceDetectorYN.create(MODEL_DET, '', (320, 320),
                                    score_threshold=DET_SCORE)
    rec = cv2.FaceRecognizerSF.create(MODEL_REC, '')
    baza = wczytaj_baze()
    return skanuj(det, rec, baza, stream, etykieta, chat, bench)


def dopasuj_osobe(rec, wektor, osoby):
    """Do ktorego JUZ WIDZIANEGO czlowieka nalezy ten wektor? Zwraca id albo None.

    Grupujemy wszystkie twarze — i rozpoznane, i nieznane — po wzajemnym podobienstwie.
    Dwa efekty za jedna cene: te same 10 klatek jednej osoby zlewa sie w jeden rekord,
    a dwie rozne obce osoby zostaja dwiema, co pozwala policzyc, ILU ludzi bylo w kadrze.
    Bez tego nie da sie spelnic reguly „druga wiadomosc, gdy osob jest wiecej niz w pierwszej".
    """
    for oid, o in osoby.items():
        if rec.match(wektor, o['wektor'], cv2.FaceRecognizerSF_FR_COSINE) >= COS_THRESHOLD:
            return oid
    return None


def nagrywaj_klip(stream_sd, plik):
    """Startuje ffmpeg zapisujacy CLIP_SECONDS sekund substreamu. Zwraca proces albo None.

    Zrodlem jest go2rtc (`rtsp://127.0.0.1:8554/...`), nie kamera — go2rtc i tak trzyma ten
    substream zywy dla podgladu na iPada i multipleksuje go, wiec drugi odbiorca nie obciaza
    kamery. `-nostdin` jest konieczne, bo w demonie proces nie ma terminala.
    """
    # Rozdzielczosc ZRODLOWA (parking_sd to i tak 720p) + crf 30. Pomiar na 6 s materialu:
    # domyslne ustawienia 4,2 MB / 1,84 s CPU, crf 30 → 2,4 MB / 1,70 s, crf 30 + skala do 480p
    # → 1,1 MB / 1,30 s. Przez chwile bylo 480p, ale user chcial ostrzejszy obraz (2026-09-01),
    # wiec zostaje 720p przy crf 30: 2,4 MB i ~28% jednego rdzenia na te 6 sekund.
    # BRAK filtra scale jest tu celowy — skalowanie tylko dodaloby pracy.
    cmd = ['ffmpeg', '-nostdin', '-loglevel', 'error', '-rtsp_transport', 'tcp',
           '-i', CLIP_RTSP + stream_sd, '-t', str(CLIP_SECONDS), '-an',
           '-c:v', 'libx264', '-preset', 'ultrafast', '-tune', 'zerolatency',
           '-crf', '30', '-g', '30', '-pix_fmt', 'yuv420p',
           '-movflags', '+faststart', '-y', plik]
    try:
        return subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    except Exception as e:
        log('WARN', f'face_scan: ffmpeg nie wystartowal: {e}')
        return None


def domknij_klip(proc, plik, zdarzenie):
    """Czeka na ffmpeg i zwraca sciezke do gotowego klipu albo None."""
    if proc is None:
        return None
    try:
        _, err = proc.communicate(timeout=CLIP_SECONDS + 8)
    except subprocess.TimeoutExpired:
        proc.kill()
        log('WARN', f'face_scan {zdarzenie}: ffmpeg nie skonczyl w czasie, ubity')
        return None
    if proc.returncode != 0:
        log('WARN', f'face_scan {zdarzenie}: ffmpeg wyszedl z {proc.returncode}: '
                    f'{(err or b"").decode("utf-8", "replace")[:200]}')
        return None
    try:
        if os.path.getsize(plik) < CLIP_MIN_KB * 1024:
            return None
    except OSError:
        return None
    return plik


def usun_klip(klip):
    """Klip leci RAZ i od razu ginie (decyzja usera 2026-09-01).

    Telegram ma go u siebie, a tmpfs to RAM — nie ma po co trzymac megabajtow.
    `telegram()` czeka na zakonczenie telegram_send.php, wiec w tym miejscu plik jest
    juz wgrany i mozna go bezpiecznie skasowac.
    TODO (daleka przyszlosc): archiwizacja takich klipow, max 7 dni, plus przegladanie
    historii ludzi i samochodow w kiosku.
    """
    if not klip:
        return
    try:
        os.unlink(klip)
    except OSError:
        pass


def montaz(rekordy):
    """Kadry kilku osob sklejone w JEDNO zdjecie, wyrownane do wspolnej wysokosci.

    Po co: Telegram na kazde zdjecie robi osobne powiadomienie. Przy trzech osobach w kadrze
    trzy zdjecia to trzy dzwonki w telefonie, a umowa brzmi „maksymalnie dwie wiadomosci".
    """
    kadry = []
    for r in sorted(rekordy, key=lambda r: -r['wynik'])[:MONTAZ_MAX]:
        k = r['podglad']
        skala = MONTAZ_H / float(k.shape[0])
        kadry.append(cv2.resize(k, (max(1, int(k.shape[1] * skala)), MONTAZ_H)))
    return kadry[0] if len(kadry) == 1 else cv2.hconcat(kadry)


def opis_osoby(r):
    """Jedna linia o czlowieku: imie z pewnoscia albo jawne 'nierozpoznany'."""
    if r['imie'] and r['sim'] >= COS_THRESHOLD:
        return f"{r['imie']} — pewnosc {r['sim']:.2f}"
    if r['imie']:
        return f"NIEROZPOZNANY (najblizej: {r['imie']} {r['sim']:.2f})"
    return 'NIEROZPOZNANY (baza twarzy pusta)'


def wyslij(osoby, stream, etykieta, klatek, czas, zdarzenie, faza, chat, klip=None):
    """Buduje i wysyla JEDNA wiadomosc o wszystkich osobach. Zwraca nazwe zapisanego kadru.

    Z klipem leci sendVideo, a kadr twarzy idzie jako MINIATURA — dzieki temu w watku widac,
    kogo dotyczy nagranie, jeszcze przed odtworzeniem, i nadal jest to JEDNA wiadomosc.
    """
    rekordy = list(osoby.values())
    obraz = montaz(rekordy)
    plik = f'face_{zdarzenie}_{faza}.jpg'
    cv2.imwrite(os.path.join(TMP_DIR, plik), obraz, [cv2.IMWRITE_JPEG_QUALITY, 90])

    ile = len(rekordy)
    naglowek = 'TWARZ' if ile == 1 else f'TWARZE ({ile} osoby)'
    czesci = [f'{naglowek}: ' + ' | '.join(opis_osoby(r) for r in
                                           sorted(rekordy, key=lambda r: -r['sim']))]
    najlepszy = max(rekordy, key=lambda r: r['wynik'])
    czesci.append(f"KAMERA: {etykieta or stream} — klatka {najlepszy['nr']}/{klatek}, "
                  f"twarz {najlepszy['fw']} px, ostrosc {najlepszy['ostr']:.0f}")
    # Faza jest w wiadomosci swiadomie: przy dwoch wiadomosciach o tym samym zdarzeniu
    # musi byc widoczne, ktora jest pozniejsza i wiazaca.
    czesci.append(f'CZAS: {czas:.1f} s' + ('' if faza == 'szybka' else ' (pelny skan)'))
    if klip:
        # Miniatura: ten sam montaz, przeskalowany pod limit Telegrama.
        thumb = f'thumb_{zdarzenie}_{faza}.jpg'
        sk = THUMB_H / float(obraz.shape[0])
        cv2.imwrite(os.path.join(TMP_DIR, thumb),
                    cv2.resize(obraz, (max(1, int(obraz.shape[1] * sk)), THUMB_H)),
                    [cv2.IMWRITE_JPEG_QUALITY, 80])
        telegram({
            'type':    'video',
            'url':     f'https://127.0.0.1/snap/{os.path.basename(klip)}',
            'thumb':   f'https://127.0.0.1/snap/{thumb}',
            'caption': '\n'.join(czesci),
            'chat':    chat,
        })
    else:
        telegram({
            'type':    'photo',
            'url':     f'https://127.0.0.1/snap/{plik}',
            'caption': '\n'.join(czesci),
            'chat':    chat,
            # Bez sekundy zwloki z telegram_send.php: kadr jest juz wybrany z calej serii,
            # a ta wiadomosc ma dotrzec jak najszybciej.
            'nodelay': True,
        })
    return plik


def skanuj(det, rec, baza, stream, etykieta, chat, bench):
    """Jeden skan. Wywolywane i z CLI (tryb lokalny), i z demona — jeden kod, jedno zachowanie.

    det/rec/baza przychodza z zewnatrz, bo demon trzyma je w pamieci miedzy zdarzeniami.

    POTOKOWO (od 2026-09-01): pobieranie klatek chodzi we WLASNYM watku, a detekcja
    i rozpoznawanie mieli to, co juz przyszlo. Powod: pobranie klatki HD z go2rtc trwa 0,45 s
    i jest to niemal wylacznie CZEKANIE NA GNIAZDO — procesor stal bezczynnie, a detekcja
    (54 ms) i rozpoznanie (19 ms) czekaly na koniec zbierania. Watki dzialaja tu realnie,
    bez pulapki GIL: urllib, imdecode, detect i feature zwalniaja GIL na czas pracy w C++.

    WYSYLKA — MAKSYMALNIE DWIE WIADOMOSCI NA ZDARZENIE (ustalone z userem):
      1. PIERWSZA, natychmiast po sensownym trafie: ktos rozpoznany z pewnoscia >= EARLY_SIM.
         Idzie ze WSZYSTKIMI osobami widzianymi do tej chwili, sklejonymi w jedno zdjecie.
         Dla samych obcych NIE wysylamy nic wczesniej — imienia i tak nie bedzie, a zdjecie
         zdarzenia wysyla juz alarm_notify.php. Zwloka nic tu nie kosztuje.
      2. DRUGA, po zamknieciu calej fazy zbierania i TYLKO gdy zmienia wniosek:
         doszla nowa osoba albo czyjes dopasowanie uroslo o POPRAWA_SIM (badz obcy dostal imie).
         Samo ostrzejsze zdjecie tej samej osoby NIE jest powodem — to byloby trzecie
         powiadomienie za tresc, ktora user juz zna.
      Gdy pierwsza nie poszla, ta koncowa jest jedyna i leci zawsze.

    KLIP WIDEO (2026-09-01): rownolegle ze skanem ffmpeg nagrywa CLIP_SECONDS sekund substreamu
    i klip jedzie z OSTATNIA wiadomoscia zdarzenia — jako sendVideo, z kadrem twarzy w miniaturze.
    Gotowy klip jest SAMODZIELNYM powodem wiadomosci koncowej (szybka poszla bez wideo, bo
    nagranie jeszcze trwalo), wiec bilans to nadal jedna albo dwie wiadomosci. Po wyslaniu
    klip jest kasowany.
    TODO: bufor ostatnich minut w RAM, zeby klip zaczynal sie od -2 s, czyli PRZED wyzwoleniem.
    Dzis nagranie startuje razem ze skanem, wiec moment podejscia czlowieka juz przepadl.
    """
    sprzataj_tmpfs()

    t_start   = time.time()
    temp_pre  = temp_c()
    czasy     = {'pobranie': 0.0, 'detekcja': 0.0, 'rozpoznanie': 0.0}
    # Identyfikator zdarzenia — wchodzi w nazwy plikow i w logi, zeby obie wiadomosci
    # o tym samym przyjsciu czlowieka dalo sie potem skleic w jedna historie.
    zdarzenie = f'{stream}_{int(t_start)}'

    klatek    = GRAB_SECONDS * GRAB_FPS
    odstep    = 1.0 / GRAB_FPS
    stream_sd = stream.replace('_hd', '_sd')

    # Klip startuje w TEJ SAMEJ chwili co skan i nagrywa sie obok, bez udzialu tej petli.
    klip_plik = os.path.join(TMP_DIR, f'clip_{zdarzenie}.mp4')
    klip_proc = None if bench else nagrywaj_klip(stream_sd, klip_plik)
    hd_gotowy = threading.Event()
    kolejka   = queue.Queue(maxsize=QUEUE_MAX)
    licznik   = {'pobranych': 0, 'z_hd': 0}

    # ROZGRZEWANIE HD W TLE. Pierwsze zapytanie o stream spoza autostartu blokuje na czas
    # otwarcia RTSP i czekania na klatke kluczowa (1-3 s) — gdybysmy zrobili to w petli,
    # stracilibysmy najlepsze sekundy. Watek robi to obok, a producent tymczasem bierze z `_sd`.
    def rozgrzej():
        if pobierz_klatke(stream, timeout=8) is not None:
            hd_gotowy.set()

    def producent():
        """Zbiera klatki i wrzuca do kolejki. Nic nie liczy — ma nadawac tempo i nie czekac.

        Znacznik konca leci w `finally`: gdyby cokolwiek tu wybuchlo, konsument wisialby
        na `get()` w nieskonczonosc, a w demonie oznaczaloby to usluge na zawsze 'busy'.
        """
        try:
            producent_petla()
        except Exception as e:
            log('ERROR', f'face_scan {zdarzenie}: producent klatek padl: {e}')
        finally:
            kolejka.put(None)

    def producent_petla():
        for i in range(klatek):
            deadline = t_start + i * odstep
            czekaj = deadline - time.time()
            if czekaj > 0:
                time.sleep(czekaj)
            t0 = time.time()
            if hd_gotowy.is_set():
                klatka = pobierz_klatke(stream)
                if klatka is None:
                    klatka = pobierz_klatke(stream_sd)
                else:
                    licznik['z_hd'] += 1
            else:
                klatka = pobierz_klatke(stream_sd)
            czasy['pobranie'] += time.time() - t0
            if klatka is None:
                continue
            licznik['pobranych'] += 1
            try:
                kolejka.put((i + 1, klatka), timeout=0.2)
            except queue.Full:
                pass   # lepiej zgubic klatke niz zwolnic zbieranie

    threading.Thread(target=rozgrzej, daemon=True).start()
    threading.Thread(target=producent, daemon=True).start()

    osoby       = {}     # id -> rekord o jednym czlowieku
    nastepny_id = 1
    twarzy      = 0
    wyslane     = None   # snapshot {id: sim} z pierwszej wiadomosci; None = jeszcze nie poszla

    limit = GRAB_SECONDS + 20   # sekund na jedna klatke — z zapasem na zimny start HD (1-3 s)
    while True:
        try:
            poz = kolejka.get(timeout=limit)
        except queue.Empty:
            log('ERROR', f'face_scan {zdarzenie}: brak klatek od producenta przez {limit}s, koncze')
            break
        if poz is None:
            break
        nr, klatka = poz

        # Detekcja na pomniejszonej kopii — pelna rozdzielczosc to strata czasu,
        # twarz przy drzwiach ma i tak setki pikseli po zmniejszeniu.
        t0 = time.time()
        h, w = klatka.shape[:2]
        skala = DET_WIDTH / float(w) if w > DET_WIDTH else 1.0
        mala = cv2.resize(klatka, (int(w * skala), int(h * skala))) if skala < 1.0 else klatka
        det.setInputSize((mala.shape[1], mala.shape[0]))
        _, twarze = det.detect(mala)
        czasy['detekcja'] += time.time() - t0
        if twarze is None:
            del klatka
            continue

        for t in twarze:
            # t = [x, y, w, h, 5 par punktow (oczy, nos, kaciki ust), score] — 15 liczb.
            # Skalujemy WSZYSTKIE wspolrzedne z powrotem do oryginalu, bo alignCrop
            # potrzebuje punktow charakterystycznych w ukladzie pelnej klatki.
            orig = t.copy()
            orig[:14] = orig[:14] / skala
            fx, fy, fw, fh = orig[0], orig[1], orig[2], orig[3]
            if fw < MIN_FACE:
                continue
            twarzy += 1

            aligned = rec.alignCrop(klatka, orig)          # 112x112, wyprostowana twarz
            ostr = ostrosc(aligned)
            wynik = ostr * (fw ** 0.5)                      # ostrosc wazniejsza, ale wielkosc tez liczy

            t0 = time.time()
            wektor = rec.feature(aligned)
            imie, sim = dopasuj(rec, wektor, baza) if baza else (None, 0.0)
            oid = dopasuj_osobe(rec, wektor, osoby)
            czasy['rozpoznanie'] += time.time() - t0

            # Podglad dla czlowieka: twarz z DUZYM zapasem, asymetrycznie — wiecej w dol.
            x1 = int(fx - fw * MARGIN_X)
            x2 = int(fx + fw + fw * MARGIN_X)
            y1 = int(fy - fh * MARGIN_TOP)
            y2 = int(fy + fh + fh * MARGIN_BOT)
            # Przy malej twarzy nawet z marginesami wychodzi znaczek — dobieramy do MIN_CROP_W,
            # rozpychajac symetrycznie wokol srodka.
            if (x2 - x1) < MIN_CROP_W:
                sx = (MIN_CROP_W - (x2 - x1)) // 2
                x1, x2 = x1 - sx, x2 + sx
                y1, y2 = y1 - sx, y2 + sx
            x1, y1 = max(0, x1), max(0, y1)
            x2, y2 = min(w, x2), min(h, y2)
            podglad = klatka[y1:y2, x1:x2].copy()

            if oid is None:
                osoby[nastepny_id] = {'imie': imie, 'sim': sim, 'wektor': wektor,
                                      'podglad': podglad, 'nr': nr, 'ostr': ostr,
                                      'fw': int(fw), 'wynik': wynik}
                nastepny_id += 1
            else:
                r = osoby[oid]
                # Tozsamosc bierzemy z NAJLEPSZEGO dopasowania, a zdjecie z NAJLEPSZEJ klatki.
                # To dwie rozne rzeczy: ostry profil moze byc nierozpoznawalny, a rozmyty
                # kadr en face dopasowac sie dobrze.
                if sim > r['sim']:
                    r['imie'], r['sim'], r['wektor'] = imie, sim, wektor
                if wynik > r['wynik']:
                    r.update({'podglad': podglad, 'nr': nr, 'ostr': ostr,
                              'fw': int(fw), 'wynik': wynik})

        del klatka   # klatka nie jest juz potrzebna — nie trzymamy wideo w pamieci

        # SENSOWNY TRAF -> pierwsza wiadomosc, nie czekajac na reszte klatek.
        if wyslane is None and not bench:
            if any(o['imie'] and o['sim'] >= EARLY_SIM for o in osoby.values()):
                wyslij(osoby, stream, etykieta, klatek, time.time() - t_start,
                       zdarzenie, 'szybka', chat)
                wyslane = {oid: o['sim'] for oid, o in osoby.items()}
                log('INFO', f'face_scan {zdarzenie}: wiadomosc szybka, {len(osoby)} os., '
                            f'{time.time() - t_start:.1f}s')

    klip = domknij_klip(klip_proc, klip_plik, zdarzenie)
    czas_calk = time.time() - t_start
    najlepszy = max(osoby.values(), key=lambda r: r['wynik']) if osoby else None

    if bench:
        raport_bench(stream, licznik['pobranych'], twarzy, czasy, czas_calk, temp_pre,
                     imie=(najlepszy['imie'] if najlepszy else None),
                     sim=(najlepszy['sim'] if najlepszy else 0.0),
                     fw=(najlepszy['fw'] if najlepszy else 0),
                     ostr=(najlepszy['ostr'] if najlepszy else 0.0),
                     z_hd=licznik['z_hd'], osob=len(osoby))
        return 0

    if not osoby:
        # Cisza bylaby dwuznaczna — nie wiadomo, czy skan nie zadzialal, czy nikt nie pokazal
        # twarzy. Dlatego leci krotki komunikat tekstowy (bez zdjecia, zeby nie dublowac klatek,
        # ktore alarm_notify i tak wysyla).
        log('INFO', f'face_scan {zdarzenie}: brak twarzy w {licznik["pobranych"]} klatkach '
                    f'({czas_calk:.1f}s)' + (', z klipem' if klip else ''))
        opis = (f'TWARZ: nie wykryto\n'
                f'KAMERA: {etykieta or stream} — {licznik["pobranych"]} klatek '
                f'w {czas_calk:.1f} s')
        # Wlasnie ten przypadek najbardziej zyskuje na klipie: dotad przychodzilo samo
        # "nie wykryto" i nie bylo jak sprawdzic, co tam sie ruszalo.
        if klip:
            telegram({'type': 'video', 'url': f'https://127.0.0.1/snap/{os.path.basename(klip)}',
                      'caption': opis, 'chat': chat})
        else:
            telegram({'type': 'text', 'msg': opis, 'chat': chat})
        usun_klip(klip)
        return 0

    # DRUGA WIADOMOSC — tylko gdy zmienia wniosek. Warunki rozlaczne, wystarczy jeden.
    if wyslane is None:
        powod = 'jedyna'
    elif len(osoby) > len(wyslane):
        powod = f'doszlo osob: {len(osoby) - len(wyslane)}'
    else:
        lepsze = [oid for oid, o in osoby.items()
                  if o['sim'] - wyslane.get(oid, 0.0) >= POPRAWA_SIM
                  and o['imie'] and o['sim'] >= COS_THRESHOLD]
        # Sam gotowy klip TEZ jest powodem — szybka wiadomosc poszla bez wideo, bo nagranie
        # jeszcze trwalo. To nadal maksymalnie dwie wiadomosci na zdarzenie.
        powod = 'lepsze dopasowanie' if lepsze else ('klip' if klip else None)

    plik = None
    if powod:
        plik = wyslij(osoby, stream, etykieta, klatek, czas_calk, zdarzenie, 'pelna', chat,
                      klip=klip)
        log('INFO', f'face_scan {zdarzenie}: wiadomosc koncowa ({powod}), {len(osoby)} os., '
                    f'{czas_calk:.1f}s')
    else:
        log('INFO', f'face_scan {zdarzenie}: koniec bez drugiej wiadomosci — nic nowego '
                    f'({len(osoby)} os., {czas_calk:.1f}s)')
    usun_klip(klip)

    # SLAD DLA DZWONKA: alarm_notify.php dokleja ostatnia wykryta twarz do powiadomienia
    # "ktos dzwoni do drzwi", zeby od razu bylo widac, KTO stoi pod drzwiami — nawet jesli
    # sam dzwonek nie zdazyl zlapac twarzy. Zapis w tmpfs, obok wycinka.
    if plik is None:
        plik = f'face_{zdarzenie}_last.jpg'
        cv2.imwrite(os.path.join(TMP_DIR, plik), montaz(list(osoby.values())),
                    [cv2.IMWRITE_JPEG_QUALITY, 90])
    try:
        with open(os.path.join(TMP_DIR, 'face_last.json'), 'w') as f:
            json.dump({'plik': plik, 'imie': najlepszy['imie'],
                       'sim': round(float(najlepszy['sim']), 3),
                       'znany': bool(najlepszy['imie'] and najlepszy['sim'] >= COS_THRESHOLD),
                       'osob': len(osoby),
                       'stream': stream, 'ts': int(time.time())}, f)
        os.chmod(os.path.join(TMP_DIR, 'face_last.json'), 0o666)
    except Exception:
        pass
    return 0

def raport_bench(stream, pobranych, twarzy, czasy, czas, temp_pre, imie=None, sim=0.0, fw=0, ostr=0.0, z_hd=0, osob=0):
    """Wydruk pomiaru obciazenia — do oceny, czy Pi to udzwignie bez chlodzenia."""
    temp_post = temp_c()
    print(f'--- face_scan --bench: {stream} ---')
    print(f'klatek pobranych   : {pobranych}  (z tego HD: {z_hd}, reszta z substreamu)')
    print(f'twarzy znalezionych: {twarzy}')
    print(f'osob rozroznionych : {osob}  (twarze zgrupowane po podobienstwie wektorow)')
    print(f'czas pobierania    : {czasy["pobranie"]:.2f} s  (w tym czekanie na kadencje)')
    print(f'czas detekcji      : {czasy["detekcja"]:.2f} s')
    print(f'czas rozpoznawania : {czasy["rozpoznanie"]:.2f} s')
    print(f'czas calkowity     : {czas:.2f} s')
    if imie is not None or sim:
        print(f'najlepsze dopasow. : {imie} {sim:.3f}  (twarz {fw} px, ostrosc {ostr:.0f})')
    print(f'temperatura        : {temp_pre:.1f} -> {temp_post:.1f} st. C')
    print(f'throttled          : {throttled()}')
    try:
        with open('/proc/loadavg') as f:
            print(f'loadavg            : {f.read().split()[0]}  (4 rdzenie, wiec 4.0 = pelne obciazenie)')
    except Exception:
        pass


if __name__ == '__main__':
    sys.exit(main())
