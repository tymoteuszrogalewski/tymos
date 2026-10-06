<?php // POST — upload miniaturki modelu urzadzenia, resize 512x512 PNG, zapis do img/
$model = preg_replace('/[^a-zA-Z0-9._\-]/', '', $_POST['model'] ?? '');
if (!$model || empty($_FILES['file'])) { echo json_encode(['ok' => false, 'error' => 'Brak modelu lub pliku']); exit; }
$tmp = $_FILES['file']['tmp_name'];
$mime = mime_content_type($tmp);
$img = null;
if (strpos($mime, 'png') !== false) $img = @imagecreatefrompng($tmp);
elseif (strpos($mime, 'jpeg') !== false || strpos($mime, 'jpg') !== false) $img = @imagecreatefromjpeg($tmp);
elseif (strpos($mime, 'gif') !== false) $img = @imagecreatefromgif($tmp);
elseif (strpos($mime, 'webp') !== false) $img = @imagecreatefromwebp($tmp);
if (!$img) { echo json_encode(['ok' => false, 'error' => 'Nieobslugiwany format (PNG/JPG/GIF/WebP)']); exit; }
$dst = imagecreatetruecolor(512, 512);
imagesavealpha($dst, true);
$trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
imagefill($dst, 0, 0, $trans);
$sw = imagesx($img); $sh = imagesy($img);
$scale = min(512 / $sw, 512 / $sh);
$nw = (int)($sw * $scale); $nh = (int)($sh * $scale);
$ox = (int)((512 - $nw) / 2); $oy = (int)((512 - $nh) / 2);
imagecopyresampled($dst, $img, $ox, $oy, 0, 0, $nw, $nh, $sw, $sh);
imagedestroy($img);
$path = __DIR__ . "/../img/{$model}.png";
imagepng($dst, $path);
imagedestroy($dst);
echo json_encode(['ok' => true]);
