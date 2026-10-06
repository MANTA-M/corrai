<?php

namespace Corrai\Utils\Image;

use Exception;
use GdImage;
use InvalidArgumentException;

/**
 * Redimentions image bytes so the longest side is at most a given maximum.
 *
 * Create an instance with that maximum dimension and the source image bytes.
 * The instance provides the redimentioned image and the redimention factor
 * (new size / original size, or 1.0 when the image already fits).
 */
class ImageRedimentioner
{
    public readonly string $image;
    public readonly float $factor;

    /**
     * @param int $maxDimension Maximum allowed width or height, in pixels.
     * @param string $bytes Source image bytes.
     */
    public function __construct(int $maxDimension, string $bytes)
    {
        if ($maxDimension <= 0) {
            throw new InvalidArgumentException("Max dimension must be greater than 0, got {$maxDimension}");
        }
        if ($bytes === '') {
            throw new InvalidArgumentException('Image bytes must not be empty');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new InvalidArgumentException('Invalid image data');
        }

        $width = $info[0];
        $height = $info[1];
        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException('Image dimensions must be greater than 0');
        }

        if ($width <= $maxDimension && $height <= $maxDimension) {
            $this->image = $bytes;
            $this->factor = 1.0;
            $this->logFactor($width, $height);
            return;
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new Exception('Failed to decode image data');
        }

        $longSide = max($width, $height);
        $scale = $maxDimension / $longSide;
        if ($width >= $height) {
            $newWidth = $maxDimension;
            $newHeight = max(1, (int) round($height * $scale));
        } else {
            $newHeight = $maxDimension;
            $newWidth = max(1, (int) round($width * $scale));
        }

        try {
            $target = self::resample($source, $width, $height, $newWidth, $newHeight);
            $this->image = self::encode($target, $info[2]);
            $this->factor = ($width >= $height ? $newWidth : $newHeight) / $longSide;
            $this->logFactor($width, $height);
        } finally {
            imagedestroy($source);
        }
    }

    private function logFactor(int $width, int $height): void
    {
        error_log(sprintf(
            '[ImageRedimentioner] scaling factor: %s (source %dx%d)',
            $this->factor,
            $width,
            $height
        ));
    }

    private static function resample(GdImage $source, int $width, int $height, int $newWidth, int $newHeight): GdImage
    {
        if (!imageistruecolor($source)) {
            imagepalettetotruecolor($source);
        }

        $target = imagecreatetruecolor($newWidth, $newHeight);
        if ($target === false) {
            throw new Exception('Failed to create resized image');
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $newWidth, $newHeight, $transparent);

        $copied = imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $width,
            $height
        );
        if (!$copied) {
            imagedestroy($target);
            throw new Exception('Failed to resample image');
        }

        return $target;
    }

    private static function encode(GdImage $image, int $type): string
    {
        $quality = 90;
        $pngCompression = (int) round((100 - $quality) / 10);

        ob_start();
        $encoded = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($image, null, $quality),
            IMAGETYPE_PNG => imagepng($image, null, $pngCompression),
            IMAGETYPE_GIF => imagegif($image),
            IMAGETYPE_WEBP => imagewebp($image, null, $quality),
            IMAGETYPE_BMP => imagebmp($image),
            default => imagejpeg($image, null, $quality),
        };
        $data = ob_get_clean();
        imagedestroy($image);

        if ($encoded === false || $data === false || $data === '') {
            throw new Exception('Failed to encode image data');
        }

        return $data;
    }
}
