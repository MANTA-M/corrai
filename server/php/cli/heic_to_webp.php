<?php

declare(strict_types=1);

/**
 * Usage: php heic_to_webp.php <source.heic> [destination.webp]
 *
 * Convertit un fichier HEIC/HEIF en WebP. La date et l'heure de prise de vue
 * (EXIF DateTimeOriginal) sont recopiées dans le WebP et sur l'horodatage
 * du fichier de sortie.
 */

$root = dirname(__DIR__);
require_once $root . '/inc/autoload.php';
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}

use Corrai\Utils\CmdUtils;
use Corrai\Utils\Image\HeicToWebp;

if (PHP_SAPI !== 'cli') {
    exit("Ce script doit être exécuté en ligne de commande.\n");
}

if ($argc < 2 || $argc > 3) {
    CmdUtils::print_error("Usage: php {$argv[0]} <source.heic> [destination.webp]");
    exit(1);
}

$source = $argv[1];
if ($argc === 3) {
    $destination = $argv[2];
} else {
    $info = pathinfo($source);
    $dir = $info['dirname'] ?? '.';
    $destination = $dir . DIRECTORY_SEPARATOR . ($info['filename'] ?? 'image') . '.webp';
}

try {
    $converted = HeicToWebp::fromFile($source);
    $converted->write($destination);
} catch (Throwable $exception) {
    CmdUtils::print_error($exception->getMessage());
    exit(1);
}

if ($converted->capturedAt !== null) {
    CmdUtils::print_info("WebP écrit : {$destination} (prise de vue {$converted->capturedAt})");
} else {
    CmdUtils::print_warn("WebP écrit : {$destination} (aucune date de prise de vue dans le HEIC)");
}
