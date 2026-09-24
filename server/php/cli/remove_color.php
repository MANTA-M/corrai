<?php

/**
 * Usage: php remove_color.php <image>
 *
 * Crée <nom>_retouche.<ext> à côté de l'image source :
 *  - les pixels rouges (rouge dominant) sont blanchis ;
 *  - pour les autres pixels foncés (luminance < 50 %), la composante rouge
 *    est supprimée ;
 *  - les pixels clairs sont conservés.
 */

// Écart minimal entre le rouge et max(vert, bleu) pour qu'un pixel soit considéré rouge.
const RED_DOMINANCE = 50;

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

        if ($r - max($g, $b) > RED_DOMINANCE) {
            imagesetpixel($image, $x, $y, ($rgba & 0x7F000000) | 0xFFFFFF);
        } elseif (0.299 * $r + 0.587 * $g + 0.114 * $b < 127.5) {
            // Luminance perçue (Rec. 601) < 50 % : pixel foncé.
            imagesetpixel($image, $x, $y, $rgba & 0x7F00FFFF);
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
