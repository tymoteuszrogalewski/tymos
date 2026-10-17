#!/opt/tymos/venv-cv/bin/python3
"""
plate_scan.py — tablice rejestracyjne z kamery parkingu: obce auto = "Mamy gosci" na Telegram.

Uzycie:
    plate_scan.py <stream>                 # normalna praca (akcja przez actions/plate_scan.sh)
    plate_scan.py <stream> --bench         # zbiera i czyta, wypisuje wynik, NIC nie wysyla
    plate_scan.py --test <plik.jpg> [...]  # odczyt z zapisanych klatek, NIC nie wysyla

PO CO: kamera parkingu zglasza `vehicle=1` (Tapo TPSmartEvent), ale nie wie, CZYJE to auto.
Swoje auta nie maja budzic nikogo, obce — zawsze (dzien/noc, alarm czy nie): kanal "Wazne"
z flaga `alarm` (przechodzi przez cisze nocna) + glosowka na iPadzie.

JAK TO DZIALA:
  1. Nie musi byc szybko — auto wjezdza i parkuje kilkanascie sekund. Zbieramy GRAB_SECONDS
     sekund klatek HD co GRAB_GAP s. Trzymamy SUROWE JPEG-i (~0,6 MB), nie zdekodowane klatki
     (12 MB kazda), i liczymy dopiero po zebraniu — spokojnie, NUM_THREADS watkami (termika Pi).
  2. Detekcja tekstu PP-OCRv3 (DB) na klatce w PELNEJ rozdzielczosci, tylko pas od ZONE_Y_MIN
     w dol (u gory datownik kamery i ulica). Tablica z kilku metrow ma ~130 px szerokosci,
     po zmniejszeniu do 1344 px detektor jej nie widzi (sprawdzone 2026-10-16).
  3. Detektor dzieli tablice na kawalki ("XXA" | "12345") — sklejamy ramki lezace w jednej linii
     obok siebie, PROSTUJEMY (tablica bywa pod katem, `wyprostuj`) i czytamy CALOSC
     rozpoznawaniem CRNN (36 znakow 0-9a-z), w kilku wariantach wycinka (`warianty`) —
     najlepiej czyta samo biale pole bez paska UE (pasek wykrywany po kolorze).
  4. Srodek tablicy musi lezec w masce strefy (parking_strefa.png: biale = nasz teren) —
     auta na ulicy nas nie obchodza.
  5. Glosowanie po klatkach: odczyt "tablicowy" (2-3 litery + 4-5 znakow) grupowany z tolerancja
     1 znaku. Slonce/IR potrafi przepalic czesc tablicy, wiec pojedyncza klatka to za malo.
  6. Odczyt zgodny z PLATES_OWN (config.inc.php) z tolerancja 2 (podobne znaki tanio, bez 1./ostatniego znaku) = nasze auto, cisza.
     Obca tablica widziana w >= MIN_HITS klatkach = wiadomosc ze zdjeciem auta + glosowka.
     Ta sama obca tablica najwyzej raz na REPEAT_SEC.

CZEGO NIE ROBI: auto z nieczytelna tablica (noc, brud, bokiem) nie daje powiadomienia — tylko
wpis INFO w logu. Inaczej kazde nasze auto z przepalona tablica byloby "gosciem".

Modele (/opt/tymos/faces/): text_detection_en_ppocrv3_2023may.onnx,
text_recognition_CRNN_EN_2021sep.onnx, charset_36_EN.txt (patrz utils/face_setup.sh).
"""

import fcntl
import json
import os
import subprocess
import sys
import time
import urllib.request

import numpy as np
import cv2

# --- konfiguracja ---------------------------------------------------------

GO2RTC     = 'http://127.0.0.1:1984/api/frame.jpeg?src='
TMP_DIR    = '/tmp/tymos'
FACES_DIR  = '/opt/tymos/faces'
MODEL_DET  = FACES_DIR + '/text_detection_en_ppocrv3_2023may.onnx'
MODEL_REC  = FACES_DIR + '/text_recognition_CRNN_EN_2021sep.onnx'
CHARSET    = FACES_DIR + '/charset_36_EN.txt'
MASK_FILE  = FACES_DIR + '/parking_strefa.png'
CONFIG     = '/opt/tymos/tymos/config.inc.php'
TG_SEND    = '/opt/tymos/actions/telegram_send.php'
SND_DIR    = '/opt/tymos/tymos/snd'
SEEN_FILE  = TMP_DIR + '/plate_seen.json'

GRAB_SECONDS = 30      # ile sekund zbierac klatki po wyzwoleniu (auto wjezdza i parkuje)
GRAB_GAP     = 2.0     # co ile sekund klatka -> 15 klatek
ZONE_Y_MIN   = 200     # px — wyzej datownik kamery i ulica, szkoda czasu detektora
NUM_THREADS  = 2       # z 4 rdzeni; skan i tak nie jest pilny, a Pi grzeje sie przy pelnym obciazeniu
TEMP_PAUSE   = 75.0    # st. C — powyzej czekamy przed nastepna klatka (throttling Pi 5 = 80)
MIN_HITS     = 2       # w ilu klatkach musi pojawic sie obca tablica, zeby uznac ja za prawdziwa
REPEAT_SEC   = 6 * 3600   # ta sama obca tablica — najwyzej jedna wiadomosc na tyle sekund
SOUND        = 'goscie'   # snd/goscie.mp3; brak pliku = syntetyczny 'doorbell'
SOUND_ON     = False      # glosowka na iPadzie wylaczona do czasu nagrania goscie.mp3
PHOTO_MIN_W  = 900     # px — minimalna szerokosc kadru auta na Telegram


# --- pomocnicze -----------------------------------------------------------

def temp_c():
    try:
        with open('/sys/class/thermal/thermal_zone0/temp') as f:
            return int(f.read().strip()) / 1000.0
    except Exception:
        return None


def log(poziom, msg):
    """Log do tabeli `log` (php -r BEZ tagu <?php — patrz face_scan.py)."""
    kod = ('require_once "/opt/tymos/actions/lib/_log.inc.php"; '
           'tymos_log($argv[1], $argv[2]);')
    try:
        subprocess.run(['php', '-r', kod, poziom, msg], timeout=10,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except Exception:
        pass


def telegram(payload):
    try:
        p = subprocess.Popen(['php', TG_SEND], stdin=subprocess.PIPE,
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        p.communicate(json.dumps(payload, ensure_ascii=False).encode('utf-8'), timeout=60)
    except Exception:
        pass


def dzwiek():
    """Glosowka na iPadzie (tabela tymos_sounds). Bez nagrania goscie.mp3 gra syntetyczny dzwonek."""
    nazwa = SOUND if os.path.exists(f'{SND_DIR}/{SOUND}.mp3') else 'doorbell'
    kod = ('require_once "/opt/tymos/tymos/config.inc.php"; '
           '$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT); '
           '$st = $db->prepare("INSERT INTO tymos_sounds (sound) VALUES (?)"); '
           '$st->bind_param("s", $argv[1]); $st->execute();')
    try:
        subprocess.run(['php', '-r', kod, nazwa], timeout=10,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except Exception:
        pass


def nasze_tablice():
    """PLATES_OWN z config.inc.php (prywatne dane tylko tam): 'AB12345,XYZ9876'."""
    kod = f'require_once "{CONFIG}"; echo defined("PLATES_OWN") ? PLATES_OWN : "";'
    try:
        out = subprocess.run(['php', '-r', kod], capture_output=True, text=True, timeout=10).stdout
    except Exception:
        out = ''
    return [normalizuj(p) for p in out.split(',') if normalizuj(p)]


def normalizuj(txt):
    return ''.join(c for c in txt.upper() if c.isalnum())


def lev(a, b):
    """Odleglosc Levenshteina — ile znakow trzeba zmienic/dodac/usunac."""
    prev = list(range(len(b) + 1))
    for i, ca in enumerate(a, 1):
        cur = [i]
        for j, cb in enumerate(b, 1):
            cur.append(min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (ca != cb)))
        prev = cur
    return prev[-1]


def tablicowy(txt):
    """Odczyt -> kandydat na polska tablice albo None.

    Pasek UE po lewej bywa czytany jako dodatkowy znak ("E", "I"), wiec przy za dlugim
    odczycie probujemy tez bez pierwszego znaku. Wzor: 2-3 litery wyroznika + 4-5 znakow.
    """
    for t in (txt, txt[1:]):
        if not 7 <= len(t) <= 8:
            continue
        lit = len(t) - len(t.lstrip('ABCDEFGHIJKLMNOPQRSTUVWXYZ'))
        if 2 <= lit <= 3 and any(c.isdigit() for c in t[lit:]):
            return t
    return None


# Znaki, ktore OCR myli ze soba na tablicy (zamiana kosztuje 0.3 zamiast 1)
PODOBNE = {frozenset(p) for p in ('0O', '0D', 'OD', '1I', '1L', 'IL', '2Z', '7Z', '5S',
                                  '8B', '3B', '38', '6G', 'CG', 'QO', '7T')}


def lev_w(a, b):
    """Levenshtein, w ktorym zamiana podobnych znakow (8/B/3, 7/Z, C/G...) kosztuje 0.3."""
    prev = [float(j) for j in range(len(b) + 1)]
    for i, ca in enumerate(a, 1):
        cur = [float(i)]
        for j, cb in enumerate(b, 1):
            zm = 0 if ca == cb else (0.3 if frozenset((ca, cb)) in PODOBNE else 1)
            cur.append(min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + zm))
        prev = cur
    return prev[-1]


def blisko_naszej(txt, n):
    """Odczyt pasuje do naszej tablicy n. Auto w ruchu daje smieci: myslnik czytany jako litera
    ("GDE7P488"), zgubiona pierwsza litera, znak z ramki na koncu ("DE7P4838") — stad
    warianty bez pierwszego/ostatniego znaku i tolerancja 2 (z tanimi podobnymi znakami)."""
    return any(lev_w(t, n) <= 2 for t in (txt, txt[1:], txt[:-1]))


def czy_nasza(tablica, nasze):
    return any(blisko_naszej(tablica, n) for n in nasze)


# --- detekcja i odczyt ----------------------------------------------------

def modele():
    det = cv2.dnn_TextDetectionModel_DB(MODEL_DET)
    det.setBinaryThreshold(0.3).setPolygonThreshold(0.5).setMaxCandidates(200).setUnclipRatio(2.0)
    rec = cv2.dnn_TextRecognitionModel(MODEL_REC)
    rec.setDecodeType('CTC-greedy')
    with open(CHARSET) as f:
        rec.setVocabulary([l for l in f.read().split('\n') if l][:36])
    rec.setInputParams(1 / 127.5, (100, 32), (127.5, 127.5, 127.5))
    return det, rec


def sklej_ramki(ramki):
    """Kawalki tekstu w jednej linii, blisko siebie -> jedna ramka (x1, y1, x2, y2, punkty).

    Detektor tnie tablice na "XXA" i "12345" (przerwa po wyrozniku), a CRNN najlepiej
    czyta cala tablice naraz. Punkty naroznikow wszystkich kawalkow ida dalej — z nich
    `wyprostuj` liczy obrocony prostokat (tablica pod katem).
    """
    ramki = sorted(ramki, key=lambda r: r[:4])
    wynik = []
    for r in ramki:
        x1, y1, x2, y2, pkt = r
        if wynik:
            a1, b1, a2, b2, pa = wynik[-1]
            h = max(y2 - y1, b2 - b1)
            sr_a, sr_r = (b1 + b2) / 2, (y1 + y2) / 2
            if abs(sr_a - sr_r) < h * 0.5 and x1 - a2 < h * 1.5 and abs((y2 - y1) - (b2 - b1)) < h * 0.5:
                wynik[-1] = (min(a1, x1), min(b1, y1), max(a2, x2), max(b2, y2),
                             np.vstack([pa, pkt]))
                continue
        wynik.append(r)
    return wynik


def wyprostuj(klatka, pkt):
    """Obrocony prostokat wokol punktow tablicy -> poziomy wycinek KOLOROWY z zapasem.

    Tablica na kamerze bywa przekrzywiona (auto stoi skosem, skreca, kamera z gory/z boku).
    minAreaRect daje kat, a przeksztalcenie perspektywiczne prostuje ja do poziomu,
    zanim cokolwiek przycinamy — inaczej obciecie paska UE "z lewej" scina litery.
    Zakladamy obrot ponizej 45 st. (tablica nie stoi pionowo).
    """
    box = cv2.boxPoints(cv2.minAreaRect(pkt.astype(np.float32)))
    sm, df = box.sum(axis=1), np.diff(box, axis=1).ravel()
    tl, br, tr, bl = box[np.argmin(sm)], box[np.argmax(sm)], box[np.argmin(df)], box[np.argmax(df)]
    szer = float(np.linalg.norm(tr - tl))
    wys = float(np.linalg.norm(bl - tl))
    if szer < 10 or wys < 8:
        return None
    u, v = (tr - tl) / szer, (bl - tl) / wys
    mx, my = wys * 0.4, wys * 0.3          # zapas: CRNN gubi skrajne znaki przy ciasnym kadrze
    zr = np.array([tl - u * mx - v * my, tr + u * mx - v * my,
                   br + u * mx + v * my, bl - u * mx + v * my], dtype=np.float32)
    W, H = int(szer + 2 * mx), int(wys + 2 * my)
    cel = np.array([[0, 0], [W - 1, 0], [W - 1, H - 1], [0, H - 1]], dtype=np.float32)
    return cv2.warpPerspective(klatka, cv2.getPerspectiveTransform(zr, cel), (W, H))


def czytaj(det, rec, klatka, maska):
    """Jedna klatka -> lista (tekst_znormalizowany, ramka_w_klatce)."""
    h, w = klatka.shape[:2]
    pas = klatka[ZONE_Y_MIN:, :]
    ph, pw = pas.shape[:2]
    # wejscie detektora musi byc wielokrotnoscia 32
    det.setInputParams(1.0 / 255.0, (pw // 32 * 32, ph // 32 * 32),
                       (122.67891434, 116.66876762, 104.00698793))
    quads, _ = det.detect(pas)
    ramki = []
    for q in quads:
        q = np.array(q, dtype=np.int32)
        x, y, bw, bh = cv2.boundingRect(q)
        if bh < 8 or bh > 120:
            continue
        pkt = q.reshape(-1, 2) + np.array([0, ZONE_Y_MIN])
        ramki.append((x, y + ZONE_Y_MIN, x + bw, y + bh + ZONE_Y_MIN, pkt))

    odczyty = []
    for x1, y1, x2, y2, pkt in sklej_ramki(ramki):
        bw, bh = x2 - x1, y2 - y1
        if not 1.5 <= bw / float(bh) <= 8.0:
            continue
        cx, cy = (x1 + x2) // 2, (y1 + y2) // 2
        if maska is not None and maska[min(cy, h - 1), min(cx, w - 1)] < 128:
            continue
        prosta = wyprostuj(klatka, pkt)
        if prosta is None:
            continue
        for wyc in warianty(prosta):
            txt = normalizuj(rec.recognize(wyc))
            if txt:
                odczyty.append((txt, (x1, y1, x2, y2)))
    return odczyty


def pasek_ue(kolor):
    """Prawa krawedz niebieskiego paska UE (kolumna) albo None, gdy paska nie widac.

    Szukamy po KOLORZE, nie stalym procentem szerokosci: staly odcinek 10% scinal "G"
    z XXA12345, bo pasek bywa wezszy (pomiar 2026-10-16). Kryterium = NASYCENIE kolumny:
    pasek S >= 110, litery i ciemny zderzak ~40-85 (oba maja odcien niebieskiego, wiec sam
    odcien H nie wystarcza). Liczy sie pierwszy ciagly pas w lewych 30% wycinka.
    W nocy (IR, obraz szary) nasycenia nie ma — None i ten wariant odpada.
    """
    s = cv2.cvtColor(kolor, cv2.COLOR_BGR2HSV)[..., 1].mean(axis=0)
    kol = np.where(s >= 110)[0]
    kol = kol[kol < kolor.shape[1] * 0.3]
    if not kol.size:
        return None
    koniec = kol[0]
    for k in kol[1:]:
        if k != koniec + 1:
            break
        koniec = k
    return int(koniec) + 1


def warianty(prosta):
    """Kilka wycinkow WYPROSTOWANEJ tablicy do CRNN — kazdy myli sie inaczej, glosowanie wybiera.

    Pomiar 2026-10-16 na czytelnej tablicy: ramka z zapasem -> "hcdaisysaa" (smieci: pasek UE,
    czarna obwodka i myslnik scisniete do 100x32), SAMO BIALE POLE bez paska UE -> "xxa12345".
    Biale pole wyznacza Otsu + najwiekszy kontur, pasek UE — kolor (`pasek_ue`).
    """
    szary = cv2.cvtColor(prosta, cv2.COLOR_BGR2GRAY)
    wyc = [szary]
    _, th = cv2.threshold(szary, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    kont, _ = cv2.findContours(th, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    if kont:
        bx, by, bw, bh = cv2.boundingRect(max(kont, key=cv2.contourArea))
        # tylko gdy wyglada na pole tablicy: szerokie i nie za plaskie
        if bw >= szary.shape[1] * 0.5 and bh >= 12 and bw / float(bh) >= 2.5:
            wyc.append(szary[by + 2:by + bh - 2, bx + 3:bx + bw - 2])
            # pasek szukany od lewej krawedzi wycinka do konca bialego pola
            ue = pasek_ue(prosta[by:by + bh, :bx + bw])
            if ue is not None and ue < bx + bw * 0.3:
                wyc.append(szary[by + 2:by + bh - 2, max(ue, bx):bx + bw - 2])
    return [x for x in wyc if x.size]


def kadr_auta(klatka, ramka):
    """Kadr wokol tablicy, wiekszy w gore (auto stoi nad tablica)."""
    h, w = klatka.shape[:2]
    x1, y1, x2, y2 = ramka
    bw = x2 - x1
    szer = max(PHOTO_MIN_W, bw * 8)
    cx = (x1 + x2) // 2
    a1 = max(0, cx - szer // 2)
    a2 = min(w, cx + szer // 2)
    wys = int(szer * 0.66)
    b2 = min(h, y2 + wys // 4)
    b1 = max(0, b2 - wys)
    return klatka[b1:b2, a1:a2].copy()


# --- glowna czesc ---------------------------------------------------------

def zbierz(stream):
    """Surowe JPEG-i z go2rtc przez GRAB_SECONDS. Kadencja od deadline, jak w face_scan."""
    jpegi = []
    t_start = time.time()
    for i in range(int(GRAB_SECONDS / GRAB_GAP)):
        czekaj = t_start + i * GRAB_GAP - time.time()
        if czekaj > 0:
            time.sleep(czekaj)
        try:
            with urllib.request.urlopen(GO2RTC + stream, timeout=8) as r:
                raw = r.read()
            if raw and len(raw) > 1000:
                jpegi.append(raw)
        except Exception:
            pass
    return jpegi


def analizuj(jpegi, nasze, wypisz=False):
    """Wszystkie klatki -> {tablica: {'hits', 'klatka_nr', 'ramka'}} + licznik surowych odczytow."""
    cv2.setNumThreads(NUM_THREADS)
    det, rec = modele()
    maska = cv2.imread(MASK_FILE, cv2.IMREAD_GRAYSCALE) if os.path.exists(MASK_FILE) else None
    tablice = {}
    surowych = 0
    for nr, raw in enumerate(jpegi):
        t = temp_c()
        stop = time.time() + 60
        while t is not None and t > TEMP_PAUSE and time.time() < stop:
            time.sleep(3)
            t = temp_c()
        klatka = cv2.imdecode(np.frombuffer(raw, dtype=np.uint8), cv2.IMREAD_COLOR)
        if klatka is None:
            continue
        if maska is not None and maska.shape[:2] != klatka.shape[:2]:
            maska = cv2.resize(maska, (klatka.shape[1], klatka.shape[0]),
                               interpolation=cv2.INTER_NEAREST)
        w_klatce = set()   # grupy trafione w TEJ klatce — kilka wariantow wycinka liczy sie raz
        for txt, ramka in czytaj(det, rec, klatka, maska):
            surowych += 1
            # Najpierw nasze auta, na SUROWYM odczycie: myslnik bywa czytany jako "S"
            # ("XXAS2345"), a wtedy filtr wzoru uznaje X za smiec z paska UE i robi "XAS2345".
            tab = next((n for n in nasze if blisko_naszej(txt, n)), None) \
                or tablicowy(txt)
            if wypisz:
                print(f'klatka {nr + 1}: {txt!r:12} -> {tab or "-"}  ramka {ramka}')
            if not tab:
                continue
            # grupowanie z tolerancja 1 znaku — przepalona litera nie robi drugiego auta
            klucz = next((k for k in tablice if lev(k, tab) <= 1), tab)
            g = tablice.setdefault(klucz, {'hits': 0, 'warianty': {}, 'nr': nr, 'ramka': ramka,
                                           'pole': 0})
            if klucz not in w_klatce:
                g['hits'] += 1
                w_klatce.add(klucz)
            g['warianty'][tab] = g['warianty'].get(tab, 0) + 1
            pole = (ramka[2] - ramka[0]) * (ramka[3] - ramka[1])
            if pole > g['pole']:     # zdjecie z klatki, w ktorej tablica byla najwieksza
                g.update({'nr': nr, 'ramka': ramka, 'pole': pole})
    for g in tablice.values():
        # Wariant z najwiekszym poparciem (wlasne odczyty + rozniace sie o 1 znak), przy remisie
        # DLUZSZY: wycinek bez paska UE czasem ucina pierwsza litere ("XA12345" vs "XXA12345").
        war = g['warianty']
        g['tablica'] = max(war, key=lambda v: (sum(c for u, c in war.items() if lev(u, v) <= 1),
                                               len(v), war[v]))
        g['nasza'] = czy_nasza(g['tablica'], nasze)
    return tablice, surowych


def juz_zgloszona(tablica):
    """True gdy ta tablica poszla w ciagu REPEAT_SEC. Zapis w tmpfs (restart Pi = od zera, OK)."""
    try:
        with open(SEEN_FILE) as f:
            seen = json.load(f)
    except Exception:
        seen = {}
    teraz = time.time()
    seen = {k: v for k, v in seen.items() if teraz - v < REPEAT_SEC}
    stara = any(lev(k, tablica) <= 1 for k in seen)
    if not stara:
        seen[tablica] = teraz
    try:
        with open(SEEN_FILE, 'w') as f:
            json.dump(seen, f)
        os.chmod(SEEN_FILE, 0o666)
    except Exception:
        pass
    return stara


def main():
    args = sys.argv[1:]
    if not args:
        print(__doc__)
        return 1
    nasze = nasze_tablice()

    if args[0] == '--test':
        jpegi = [open(p, 'rb').read() for p in args[1:]]
        t0 = time.time()
        tablice, surowych = analizuj(jpegi, nasze, wypisz=True)
        for g in tablice.values():
            print(f"TABLICA {g['tablica']} hits={g['hits']} nasza={g['nasza']} warianty={g['warianty']}")
        print(f'{len(jpegi)} klatek, {surowych} odczytow, {time.time() - t0:.1f} s, temp {temp_c()}')
        return 0

    stream = args[0]
    bench = '--bench' in args[1:]

    blokada = open(os.path.join(TMP_DIR, 'plate_scan.lock'), 'w')
    try:
        fcntl.flock(blokada, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        return 0   # trwa skan poprzedniego zdarzenia

    for plik in (MODEL_DET, MODEL_REC, CHARSET):
        if not os.path.exists(plik):
            log('ERROR', f'plate_scan: brak modelu {plik}')
            return 1
    if not nasze and not bench:
        log('ERROR', 'plate_scan: brak PLATES_OWN w config.inc.php — kazde auto byloby obce')
        return 1

    zdarzenie = f'{stream}_{int(time.time())}'
    t0 = time.time()
    jpegi = zbierz(stream)
    t_zb = time.time() - t0
    tablice, surowych = analizuj(jpegi, nasze, wypisz=bench)
    czas = time.time() - t0

    opis = ', '.join(f"{g['tablica']}x{g['hits']}{'(nasza)' if g['nasza'] else ''}"
                     for g in tablice.values()) or 'brak'
    log('INFO', f'plate_scan {zdarzenie}: {len(jpegi)} klatek, {surowych} odczytow, tablice: {opis} '
                f'(zbieranie {t_zb:.0f} s, razem {czas:.0f} s)')
    if bench:
        print(f'tablice: {opis}\nczas {czas:.1f} s, temp {temp_c()}')
        return 0

    obce = [g for g in tablice.values() if not g['nasza'] and g['hits'] >= MIN_HITS]
    # Blad odczytu tego samego auta (rozni sie o <= 2 znaki od mocniejszej grupy) to nie drugi gosc.
    obce = [g for g in obce if not any(lev_w(g['tablica'], o['tablica']) <= 2 and o['hits'] > g['hits']
                                       for o in tablice.values())]
    obce = [g for g in obce if not juz_zgloszona(g['tablica'])]
    if not obce:
        return 0

    zglos(obce, jpegi, zdarzenie)
    return 0


def zglos(obce, jpegi, zdarzenie, naglowek='Mamy gości! Obce auto na parkingu.'):
    """Jedna wiadomosc na kanal Wazne (zdjecie auta z najwiekszym odczytem) + glosowka."""
    obce.sort(key=lambda g: -g['hits'])
    g = obce[0]
    klatka = cv2.imdecode(np.frombuffer(jpegi[g['nr']], dtype=np.uint8), cv2.IMREAD_COLOR)
    plik = f'plate_{zdarzenie}.jpg'
    cv2.imwrite(os.path.join(TMP_DIR, plik), kadr_auta(klatka, g['ramka']),
                [cv2.IMWRITE_JPEG_QUALITY, 90])
    linie = [naglowek]
    for o in obce:
        linie.append(f"Nr rej.: {o['tablica']} (odczyt w {o['hits']}/{len(jpegi)} klatkach)")
    # alarm=True: przechodzi przez cisze nocna — obce auto ma budzic niezaleznie od pory i alarmu
    telegram({'type': 'photo', 'url': f'https://127.0.0.1/snap/{plik}',
              'caption': '\n'.join(linie), 'chat': 'alert', 'alarm': True, 'nodelay': True})
    if SOUND_ON:
        dzwiek()
    log('INFO', f'plate_scan {zdarzenie}: GOSCIE ' + ', '.join(o['tablica'] for o in obce))


if __name__ == '__main__':
    sys.exit(main())
