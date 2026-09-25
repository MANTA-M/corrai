<?php

/**
 * Usage: php remove_color.php <image>
 *
 * Crée <nom>_retouche.<ext> à côté de l'image source. Chaque pixel est
 * converti en HSL et passé en blanc s'il est rouge (teinte rouge et
 * saturation > 50 %) ou clair (luminosité > 80 %). Les autres pixels sont
 * conservés.
 */

// Écart maximal, en degrés, entre la teinte d'un pixel et 0° (rouge pur).
const RED_HUE_TOLERANCE = 30;
const MIN_RED_SATURATION = 15;
const MIN_WHITE_LIGHTNESS = 80;

/**
 * @return array{float, float, float} [teinte en degrés 0-360, saturation 0-100, luminosité 0-100]
 */
function rgbToHsl(int $r, int $g, int $b): array
{
    $r /= 255;
    $g /= 255;
    $b /= 255;

    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $delta = $max - $min;
    $l = ($max + $min) / 2;

    if ($delta == 0) {
        return [0.0, 0.0, $l * 100];
    }

    $s = $delta / (1 - abs(2 * $l - 1));

    $h = match ($max) {
        $r => fmod(($g - $b) / $delta + 6, 6),
        $g => ($b - $r) / $delta + 2,
        default => ($r - $g) / $delta + 4,
    };

    return [$h * 60, $s * 100, $l * 100];
}

if (PHP_SAPI !== 'cli') {
    exit("Ce script doit être exécuté en ligne de commande.\n");
}

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php {$argv[0]} <image>\n");
    exit(1);
}

if (!extension_loaded('gd')) {
    fwrite(STDERR, "L'extension GD est requise.\n");
    exit(1);
}

$source = $argv[1];

if (!is_file($source) || !is_readable($source)) {
    fwrite(STDERR, "Fichier introuvable ou illisible : $source\n");
    exit(1);
}

$info = @getimagesize($source);
if ($info === false) {
    fwrite(STDERR, "Le fichier n'est pas une image valide : $source\n");
    exit(1);
}

$type = $info[2];
$image = match ($type) {
    IMAGETYPE_PNG  => imagecreatefrompng($source),
    IMAGETYPE_JPEG => imagecreatefromjpeg($source),
    IMAGETYPE_GIF  => imagecreatefromgif($source),
    IMAGETYPE_WEBP => imagecreatefromwebp($source),
    IMAGETYPE_BMP  => imagecreatefrombmp($source),
    default        => false,
};

if ($image === false) {
    fwrite(STDERR, "Format d'image non supporté : " . image_type_to_mime_type($type) . "\n");
    exit(1);
}

if (!imageistruecolor($image)) {
    imagepalettetotruecolor($image);
}
imagealphablending($image, false);
imagesavealpha($image, true);

$width = imagesx($image);
$height = imagesy($image);

for ($y = 0; $y < $height; $y++) {
    for ($x = 0; $x < $width; $x++) {
        // Format truecolor GD : 0xAARRGGBB.
        $rgba = imagecolorat($image, $x, $y);
        $r = ($rgba >> 16) & 0xFF;
        $g = ($rgba >> 8) & 0xFF;
        $b = $rgba & 0xFF;

        [$h, $s, $l] = rgbToHsl($r, $g, $b);
        $isRed = min($h, 360 - $h) <= RED_HUE_TOLERANCE && $s > MIN_RED_SATURATION;

        if ($isRed || $l > MIN_WHITE_LIGHTNESS) {
            imagesetpixel($image, $x, $y, ($rgba & 0x7F000000) | 0xFFFFFF);
        }
    }
}

$pathInfo = pathinfo($source);
$extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
$destination = $pathInfo['dirname'] . DIRECTORY_SEPARATOR . $pathInfo['filename'] . '_retouche' . $extension;

$saved = match ($type) {
    IMAGETYPE_PNG  => imagepng($image, $destination),
    IMAGETYPE_JPEG => imagejpeg($image, $destination, 95),
    IMAGETYPE_GIF  => imagegif($image, $destination),
    IMAGETYPE_WEBP => imagewebp($image, $destination, 95),
    IMAGETYPE_BMP  => imagebmp($image, $destination),
};

imagedestroy($image);

if (!$saved) {
    fwrite(STDERR, "Impossible d'écrire le fichier : $destination\n");
    exit(1);
}

echo "Image retouchée : $destination\n";
