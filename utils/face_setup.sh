#!/bin/bash
# face_setup.sh — jednorazowa instalacja rozpoznawania twarzy na Pi.
# Idempotentny: mozna puscic ponownie, nic nie zepsuje.
#
#   bash /opt/tymos/utils/face_setup.sh
#
# DLACZEGO VENV, A NIE `apt install python3-opencv`:
# apt ciagnie **121 pakietow** — Qt5, Wayland, VTK, OpenMPI, UCX — na bezglowy serwer,
# ktory nie ma nawet ekranu. `opencv-python-headless` w venv to jeden plik wheel, ~99 MB
# razem z venv, bez ani jednej biblioteki GUI. Odinstalowanie = skasowanie katalogu.
# Venv robiony z `--system-site-packages`, zeby korzystal z systemowego numpy zamiast
# sciagac drugi.
#
# Modele leza na karcie SD, ale sa czytane raz przy starcie skryptu — zero zapisow w trakcie pracy.

set -e

VENV=/opt/tymos/venv-cv
FACES=/opt/tymos/faces
ZOO=https://github.com/opencv/opencv_zoo/raw/main/models

echo "=== 1/3 venv + opencv-python-headless ==="
if [ ! -x "$VENV/bin/python3" ]; then
    python3 -m venv --system-site-packages "$VENV"
fi
# --only-binary: gdyby zabraklo gotowego wheela, ma sie WYWALIC, a nie kompilowac godzinami
"$VENV/bin/pip" install --no-cache-dir --only-binary=:all: opencv-python-headless

echo "=== 2/3 katalog $FACES ==="
mkdir -p "$FACES"
chmod 777 "$FACES"

echo "=== 3/3 modele ==="
[ -s "$FACES/face_detection_yunet_2023mar.onnx" ] || \
    curl -L -o "$FACES/face_detection_yunet_2023mar.onnx" \
         "$ZOO/face_detection_yunet/face_detection_yunet_2023mar.onnx"
[ -s "$FACES/face_recognition_sface_2021dec.onnx" ] || \
    curl -L -o "$FACES/face_recognition_sface_2021dec.onnx" \
         "$ZOO/face_recognition_sface/face_recognition_sface_2021dec.onnx"
chmod 777 "$FACES"/*.onnx

echo
echo "Sprawdzenie:"
"$VENV/bin/python3" -c "import cv2; print('cv2', cv2.__version__, '| YuNet', hasattr(cv2,'FaceDetectorYN'), '| SFace', hasattr(cv2,'FaceRecognizerSF'))"
ls -la "$FACES"
echo
echo "Nastepnie:"
echo "  1. wrzuc zdjecia do $FACES/<Imie>/*.jpg"
echo "  2. /opt/tymos/utils/face_enroll.py"
echo "  3. /opt/tymos/utils/face_scan.py parking_hd --bench"
