<?php

namespace Corrai\Task;

use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\WSException;
use Exception;
use GdImage;
use Imagick;
use ImagickException;
use InvalidArgumentException;

/**
 * Writes a JPEG thumbnail annex next to a stored image.
 *
 * JPG and PNG are decoded by PHP GD. TIFF is rasterized first because GD
 * cannot read TIFF, then the miniature is produced with GD.
 */
class Thumbnail extends PathQueueItemTask
{
    public const MAX_SIDE = 256;
    public const JPEG_QUALITY = 82;

    protected function process(object $queue_item_data, string $s3_path): void
    {
        $store = ObjectStore::getInstance();
        $bytes = $store->getContents($s3_path);
        if ($bytes === '') {
            error_log('Empty image for thumbnail: ' . $s3_path);
            return;
        }

        try {
            $jpeg = self::jpegBytes($bytes);
        } catch (InvalidArgumentException $e) {
            error_log('Skipping thumbnail for ' . $s3_path . ': ' . $e->getMessage());
            return;
        }

        $file = $this->loadFile($s3_path);
        $store->putContents($file->thumbnailKey(), $jpeg, 'image/jpeg');
        $file->appendEvent('Thumbnail created');
    }

    /**
     * JPEG bytes whose longest side is at most $maxSide.
     */
    public static function jpegBytes(string $bytes, int $maxSide = self::MAX_SIDE): string
    {
        if ($maxSide <= 0) {
            throw new InvalidArgumentException("Max side must be greater than 0, got {$maxSide}");
        }
        if ($bytes === '') {
            throw new InvalidArgumentException('Image bytes must not be empty');
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            throw new WSException('PHP GD is not available', 500);
        }

        $source = self::decode($bytes);
        try {
            $width = imagesx($source);
            $height = imagesy($source);
            if ($width <= 0 || $height <= 0) {
                throw new InvalidArgumentException('Image dimensions must be greater than 0');
            }

            $longSide = max($width, $height);
            if ($longSide <= $maxSide) {
                $newWidth = $width;
                $newHeight = $height;
            } elseif ($width >= $height) {
                $newWidth = $maxSide;
                $newHeight = max(1, (int) round($height * ($maxSide / $longSide)));
            } else {
                $newHeight = $maxSide;
                $newWidth = max(1, (int) round($width * ($maxSide / $longSide)));
            }

            $target = self::resampleToJpegCanvas($source, $width, $height, $newWidth, $newHeight);
            return self::encodeJpeg($target);
        } finally {
            imagedestroy($source);
        }
    }

    private static function decode(string $bytes): GdImage
    {
        $image = @imagecreatefromstring($bytes);
        if ($image instanceof GdImage) {
            return $image;
        }

        if (!self::isTiff($bytes)) {
            throw new InvalidArgumentException('The source file is not an image GD can read');
        }

        return self::decodeTiff($bytes);
    }

    private static function isTiff(string $bytes): bool
    {
        if (strlen($bytes) < 4) {
            return false;
        }
        $magic = substr($bytes, 0, 4);
        if ($magic === "II*\0" || $magic === "MM\0*") {
            return true;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return false;
        }
        $type = $info[2] ?? 0;
        return $type === IMAGETYPE_TIFF_II || $type === IMAGETYPE_TIFF_MM;
    }

    /**
     * GD has no TIFF loader. Imagick rasterizes the first page to PNG, then GD
     * builds the thumbnail.
     */
    private static function decodeTiff(string $bytes): GdImage
    {
        if (!extension_loaded('imagick')) {
            throw new InvalidArgumentException('TIFF thumbnails require Imagick to decode the source');
        }

        try {
            $imagick = new Imagick();
            $imagick->readImageBlob($bytes);
            $imagick->setIteratorIndex(0);
            $imagick->setImageFormat('png');
            $png = $imagick->getImageBlob();
            $imagick->clear();
        } catch (ImagickException $e) {
            throw new InvalidArgumentException('Cannot decode TIFF image', 0, $e);
        }

        $image = @imagecreatefromstring($png);
        if (!$image instanceof GdImage) {
            throw new InvalidArgumentException('GD could not read the rasterized TIFF');
        }
        return $image;
    }

    private static function resampleToJpegCanvas(
        GdImage $source,
        int $width,
        int $height,
        int $newWidth,
        int $newHeight
    ): GdImage {
        if (!imageistruecolor($source)) {
            imagepalettetotruecolor($source);
        }

        $target = imagecreatetruecolor($newWidth, $newHeight);
        if ($target === false) {
            throw new Exception('Failed to create thumbnail image');
        }

        $white = imagecolorallocate($target, 255, 255, 255);
        imagefilledrectangle($target, 0, 0, $newWidth, $newHeight, $white);
        imagealphablending($target, true);

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
            throw new Exception('Failed to resample thumbnail');
        }

        return $target;
    }

    private static function encodeJpeg(GdImage $image): string
    {
        ob_start();
        $encoded = imagejpeg($image, null, self::JPEG_QUALITY);
        $data = ob_get_clean();
        imagedestroy($image);

        if ($encoded === false || $data === false || $data === '') {
            throw new Exception('Failed to encode thumbnail JPEG');
        }

        return $data;
    }
}
