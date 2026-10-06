#!/opt/tymos/venv-cv/bin/python3
"""
face_enroll.py — buduje baze twarzy z Twoich zdjec.

Uzycie:
    face_enroll.py            # przelicz wszystko od nowa
    face_enroll.py --lista    # tylko pokaz, co jest w bazie

Katalog zdjec (jeden podkatalog = jedna osoba, nazwa katalogu = imie w powiadomieniu):

    /opt/tymos/faces/
        Osoba1/
            1.jpg
            2.jpg
            ...
        Osoba2/
            1.jpg

Wynik: /opt/tymos/faces/embeddings.json — tylko wektory liczbowe, bez zdjec.
Po dodaniu nowych zdjec uruchom ponownie.

ILE ZDJEC I JAKICH:
  - 5-10 na osobe wystarczy, wiecej nie szkodzi
  - RAZNE warunki: dzien i wieczor, z okularami i bez, czapka, zarost
  - twarz ma byc wyrazna i w miare na wprost, minimum ~100 px szerokosci
  - NAJLEPSZE sa kadry z TEJ SAMEJ kamery, ktora bedzie rozpoznawac — ten sam obiektyw,
    ta sama wysokosc, to samo oswietlenie. Zdjecia z telefonu tez zadzialaja, ale slabiej.
  - jedna twarz na zdjeciu; jesli jest wiecej, brana jest najwieksza
"""

import json
import os
import sys

import numpy as np
import cv2

FACES_DIR = '/opt/tymos/faces'
EMB_FILE  = FACES_DIR + '/embeddings.json'
MODEL_DET = FACES_DIR + '/face_detection_yunet_2023mar.onnx'
MODEL_REC = FACES_DIR + '/face_recognition_sface_2021dec.onnx'

MIN_FACE  = 80    # px — mniejsza twarz na zdjeciu wzorcowym to smieciowy wektor
DET_SCORE = 0.7   # nizej niz w face_scan: zdjecia wzorcowe sa spokojne, warto byc czulszym


def lista():
    if not os.path.exists(EMB_FILE):
        print('Baza pusta — nie ma jeszcze', EMB_FILE)
        return 0
    with open(EMB_FILE) as f:
        baza = json.load(f)
    print(f'{EMB_FILE}:')
    for imie, wektory in sorted(baza.items()):
        print(f'  {imie:20s} {len(wektory)} wzorcow')
    return 0


def main():
    if '--lista' in sys.argv:
        return lista()

    for plik in (MODEL_DET, MODEL_REC):
        if not os.path.exists(plik):
            print('BRAK MODELU:', plik)
            print('Pobierz je przez utils/face_setup.sh')
            return 1

    det = cv2.FaceDetectorYN.create(MODEL_DET, '', (320, 320), score_threshold=DET_SCORE)
    rec = cv2.FaceRecognizerSF.create(MODEL_REC, '')

    baza = {}
    odrzucone = []

    for imie in sorted(os.listdir(FACES_DIR)):
        katalog = os.path.join(FACES_DIR, imie)
        if not os.path.isdir(katalog):
            continue

        wektory = []
        for nazwa in sorted(os.listdir(katalog)):
            if not nazwa.lower().endswith(('.jpg', '.jpeg', '.png')):
                continue
            sciezka = os.path.join(katalog, nazwa)
            obraz = cv2.imread(sciezka)
            if obraz is None:
                odrzucone.append(f'{imie}/{nazwa}: nie da sie wczytac')
                continue

            h, w = obraz.shape[:2]
            det.setInputSize((w, h))
            _, twarze = det.detect(obraz)
            if twarze is None or len(twarze) == 0:
                odrzucone.append(f'{imie}/{nazwa}: nie znaleziono twarzy')
                continue

            # Najwieksza twarz na zdjeciu — na wzorcach zwykle jest tylko jedna,
            # a jak sa dwie, to ta z tylu i tak jest nieprzydatna.
            twarz = max(twarze, key=lambda t: t[2])
            if twarz[2] < MIN_FACE:
                odrzucone.append(f'{imie}/{nazwa}: twarz za mala ({int(twarz[2])} px)')
                continue

            aligned = rec.alignCrop(obraz, twarz)
            wektor = rec.feature(aligned)
            wektory.append(wektor.flatten().tolist())
            print(f'  + {imie}/{nazwa}  (twarz {int(twarz[2])} px)')

        if wektory:
            baza[imie] = wektory

    if not baza:
        print('Nic nie zbudowano — brak uzytecznych zdjec w', FACES_DIR)
        if odrzucone:
            print('Odrzucone:')
            for o in odrzucone:
                print('  -', o)
        return 1

    with open(EMB_FILE, 'w') as f:
        json.dump(baza, f)
    os.chmod(EMB_FILE, 0o777)

    print()
    print('Zapisano', EMB_FILE)
    for imie, wektory in sorted(baza.items()):
        print(f'  {imie:20s} {len(wektory)} wzorcow')
    if odrzucone:
        print('Odrzucone zdjecia:')
        for o in odrzucone:
            print('  -', o)
    return 0


if __name__ == '__main__':
    sys.exit(main())
