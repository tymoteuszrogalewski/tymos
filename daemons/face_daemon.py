#!/opt/tymos/venv-cv/bin/python3
"""
face_daemon.py — rezydentny demon rozpoznawania twarzy. Trzyma modele w pamieci i czeka.

PO CO TO JEST:
Do 2026-09-01 kazde zdarzenie odpalalo utils/face_scan.py od zera. Sam start kosztowal
~1-1,5 s (`import cv2` na Pi + wczytanie SFace, 37 MB ONNX) i NIE BYLO tego widac w --bench,
bo import leci przed pomiarem czasu. To byl czysty narzut doliczany do kazdego przyjscia
czlowieka pod drzwi — a przez te sekundy czlowiek wychodzi z kadru.
Demon laduje modele RAZ, przy starcie systemu, i od tego momentu zdarzenie kosztuje tylko
tyle, ile realny skan.

JAK TO DZIALA:
  1. Laduje YuNet + SFace + baze twarzy, tworzy gniazdo uniksowe /tmp/tymos/face.sock (tmpfs).
  2. Czeka na zadanie: jedna linia JSON {"stream": "...", "etykieta": "...", "chat": "..."}.
  3. Odpowiada NATYCHMIAST ('ok' albo 'busy') i skanuje w watku — klient nie czeka na wynik,
     dokladnie tak jak dotad nie czekal na proces w tle (alarm_notify.php: `nohup ... &`).
  4. 'busy' = trwa juz inny skan, zadanie odpuszczone po cichu. To to samo zachowanie, ktore
     dawal nieblokujacy flock w face_scan.py — seria ruchu nie odpala pieciu skanow naraz.

CALY KOD SKANUJACY SIEDZI W utils/face_scan.py (funkcja `skanuj`). Tutaj jest tylko obsluga
gniazda i cyklu zycia. Jedno miejsce na algorytm, zeby tryb lokalny i demon nie rozjechaly sie
po pierwszej poprawce.

BAZA TWARZY jest przeladowywana automatycznie po zmianie mtime embeddings.json — inaczej po
kazdym uruchomieniu face_enroll.py trzeba by restartowac demona, a to sie zapomina.

Uruchomienie: systemd, etc/systemd/system/tymos-face.service.
Awaria demona nic nie psuje — face_scan.py wraca wtedy do trybu lokalnego.
"""

import json
import os
import socket
import sys
import threading
import time

sys.path.insert(0, '/opt/tymos/utils')
import face_scan as fs   # noqa: E402  (sciezka musi byc ustawiona przed importem)

SOCK_FILE  = fs.SOCK_FILE
BACKLOG    = 8
IDLE_CHECK = 5.0   # sekund — timeout accept(), zeby petla mogla sprawdzic baze i zyc dalej


def zaladuj_modele():
    """Modele + baza. Zwraca (det, rec, baza, mtime_bazy)."""
    for plik, opis in ((fs.MODEL_DET, 'detektor YuNet'), (fs.MODEL_REC, 'model SFace')):
        if not os.path.exists(plik):
            fs.log('ERROR', f'face_daemon: brak modelu ({opis}): {plik}')
            sys.exit(1)
    det = fs.cv2.FaceDetectorYN.create(fs.MODEL_DET, '', (320, 320),
                                       score_threshold=fs.DET_SCORE)
    rec = fs.cv2.FaceRecognizerSF.create(fs.MODEL_REC, '')
    baza = fs.wczytaj_baze()
    return det, rec, baza, mtime_bazy()


def mtime_bazy():
    """Znacznik czasu embeddings.json. 0 gdy pliku nie ma (pusta baza to poprawny stan)."""
    try:
        return os.path.getmtime(fs.EMB_FILE)
    except OSError:
        return 0.0


def gniazdo():
    """Swieze gniazdo uniksowe. Stary plik po nieczystym zamknieciu trzeba usunac samemu."""
    try:
        os.unlink(SOCK_FILE)
    except OSError:
        pass
    srv = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    srv.bind(SOCK_FILE)
    # 0666, bo zadanie moze przyjsc i od demona TymOS (root), i z Apache (www-data).
    os.chmod(SOCK_FILE, 0o666)
    srv.listen(BACKLOG)
    srv.settimeout(IDLE_CHECK)
    return srv


def main():
    os.makedirs(fs.TMP_DIR, exist_ok=True)
    fs.zaladuj_cv()

    t0 = time.time()
    det, rec, baza, emb_mtime = zaladuj_modele()
    ile = sum(len(v) for v in baza.values())
    fs.log('INFO', f'face_daemon: start, modele w {time.time() - t0:.1f} s, '
                   f'baza: {len(baza)} osob / {ile} wektorow')

    srv = gniazdo()
    zajety = threading.Lock()

    def obsluz(stream, etykieta, chat):
        try:
            fs.skanuj(det, rec, baza, stream, etykieta, chat, False)
        except Exception as e:
            fs.log('ERROR', f'face_daemon: skan {stream} wywalil sie: {e}')
        finally:
            zajety.release()

    while True:
        # Przeladowanie bazy po face_enroll.py. Sprawdzane miedzy zdarzeniami, nie w trakcie skanu.
        m = mtime_bazy()
        if m != emb_mtime:
            baza.clear()
            baza.update(fs.wczytaj_baze())
            emb_mtime = m
            ile = sum(len(v) for v in baza.values())
            fs.log('INFO', f'face_daemon: baza twarzy przeladowana — {len(baza)} osob / {ile} wektorow')

        try:
            kl, _ = srv.accept()
        except socket.timeout:
            continue
        except OSError as e:
            fs.log('ERROR', f'face_daemon: accept: {e}')
            time.sleep(1)
            continue

        try:
            kl.settimeout(5)
            surowe = kl.recv(4096).decode('utf-8', 'replace').strip()
            zad = json.loads(surowe) if surowe else {}
            stream = zad.get('stream') or ''
            if not stream:
                kl.sendall(b'err\n')
                continue
            if not zajety.acquire(blocking=False):
                kl.sendall(b'busy\n')
                continue
            # Blokade zwalnia watek roboczy. Jesli jednak nie uda sie go wystartowac (albo
            # klient zdazyl zniknac i sendall rzuci), MUSIMY zwolnic ja tutaj — inaczej demon
            # zostalby na zawsze 'busy' i przestal skanowac az do restartu.
            try:
                kl.sendall(b'ok\n')
                threading.Thread(target=obsluz, daemon=True, args=(
                    stream, zad.get('etykieta', ''), zad.get('chat', 'default'))).start()
            except Exception:
                zajety.release()
                raise
        except Exception as e:
            fs.log('ERROR', f'face_daemon: zadanie odrzucone: {e}')
        finally:
            try:
                kl.close()
            except OSError:
                pass


if __name__ == '__main__':
    try:
        main()
    finally:
        try:
            os.unlink(SOCK_FILE)
        except OSError:
            pass
