<?php

namespace Corrai\Task;

use Corrai\Model\InputFile;
use Corrai\Model\SubjectFile;
use Corrai\Model\OCRResult;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Subject\SubjectPages;
use Corrai\Utils\Image\HeicToWebp;
use Corrai\Utils\Store\ObjectStore;
use Exception;
use GdImage;
use Imagick;
use ImagickException;
use ImagickPixel;
use InvalidArgumentException;
use Throwable;

/**
 * Turns an OCR image upright and crops it to the text.
 *
 * Google OCR stores word boxes as proportions of the page. This task rotates
 * those boxes and the pixels by the same angle, then crops to the text with a
 * small margin and remaps the boxes onto that crop.
 */
class TaskRotateAndCrop extends PathQueueItemTask
{
    public const MARGIN = 0.01;

    public const CROP_EVENT = 'Cropped to the text';

    protected function process(object $queue_item_data, string $s3_path): void
    {
        try {
            $file = $this->loadFile($s3_path);
        } catch (Throwable $e) {
            error_log(sprintf('[TaskRotateAndCrop] Failed to load file from path %s: %s', $s3_path, $e->getMessage()));
            return;
        }

        $store = ObjectStore::getInstance();
        $ocrKey = $file->ocrResultKey();
        if (!$store->exists($ocrKey)) {
            error_log(sprintf('[TaskRotateAndCrop] OCR result is missing at %s for file %s (%s)', $ocrKey, (string) $file->id, $s3_path));
            $this->complete($file);
            return;
        }

        try {
            $ocrContents = $store->getContents($ocrKey);
            $result = OCRResult::from_json($ocrContents);
        } catch (Throwable $e) {
            error_log(sprintf('[TaskRotateAndCrop] Failed to decode OCR result JSON for file %s (%s): %s', (string) $file->id, $s3_path, $e->getMessage()));
            $this->complete($file);
            return;
        }
        if ($result->words === []) {
            error_log(sprintf('[TaskRotateAndCrop] OCR result has no words for file %s (%s), skipping rotate and crop', (string) $file->id, $s3_path));
            $this->complete($file);
            return;
        }

        try {
            $bytes = $store->getContents($file->contentKey());
        } catch (Throwable $e) {
            error_log(sprintf('[TaskRotateAndCrop] Failed to read image content for file %s (%s): %s', (string) $file->id, $s3_path, $e->getMessage()));
            $this->complete($file);
            return;
        }
        if ($bytes === '') {
            error_log(sprintf('[TaskRotateAndCrop] Empty image content for file %s (%s)', (string) $file->id, $s3_path));
            $this->complete($file);
            return;
        }

        $type = self::outputType($bytes);
        try {
            [$image, $decodedByGd] = self::decode($bytes);
        } catch (Throwable $e) {
            error_log(sprintf('[TaskRotateAndCrop] Failed to decode image for file %s (%s): %s', (string) $file->id, $s3_path, $e->getMessage()));
            $this->complete($file);
            return;
        }

        try {
            if ($result->rotation === 0) {
                $result->detectRotation();
            }
            $angle = $result->rotation;
            if ($angle !== 0) {
                try {
                    $image = self::rotate($image, $angle, $bytes, $decodedByGd);
                } catch (Throwable $e) {
                    error_log(sprintf('[TaskRotateAndCrop] Failed to rotate image by %d degrees for file %s (%s): %s', $angle, (string) $file->id, $s3_path, $e->getMessage()));
                    throw $e;
                }

                $result->rotate_upright();

                try {
                    self::storeImage($file, $image, $type);
                    $store->putContents($ocrKey, $result->to_json(true), 'application/json');
                } catch (Throwable $e) {
                    error_log(sprintf('[TaskRotateAndCrop] Failed to persist rotated image and OCR coordinates for file %s (%s): %s', (string) $file->id, $s3_path, $e->getMessage()));
                    throw $e;
                }

                try {
                    $file->appendEvent(self::rotationEvent($angle));
                } catch (Throwable $e) {
                    error_log(sprintf('[TaskRotateAndCrop] Failed to append rotation event for file %s (%s): %s', (string) $file->id, $s3_path, $e->getMessage()));
                }
            }

            try {
                $box = $result->get_global_box(self::MARGIN);
            } catch (Throwable $e) {
                error_log(sprintf('[TaskRotateAndCrop] Failed to compute global bounding box for file %s (%s): %s', (string) $file->id, $s3_path, $e->getMessage()));
                $this->complete($file);
                return;
            }

            try {
                $image = self::crop($image, $box);
            } catch (Throwable $e) {
                error_log(sprintf('[TaskRotateAndCrop] Failed to crop image to bounding box for file %s (%s): %s', (string) $file->id, $s3_path, $e->getMessage()));
                throw $e;
            }

            $result->resize_to_global($box);

            try {
                self::storeImage($file, $image, $type);
                $store->putContents($ocrKey, $result->to_json(true), 'application/json');
            } catch (Throwable $e) {
                error_log(sprintf('[TaskRotateAndCrop] Failed to persist cropped image and OCR coordinates for file %s (%s): %s', (string) $file->id, $s3_path, $e->getMessage()));
                throw $e;
            }

            try {
                $file->appendEvent(self::CROP_EVENT);
            } catch (Throwable $e) {
                error_log(sprintf('[TaskRotateAndCrop] Failed to append crop event for file %s (%s): %s', (string) $file->id, $s3_path, $e->getMessage()));
            }

            $this->complete($file);
        } catch (Throwable $e) {
            error_log(sprintf('[TaskRotateAndCrop] Unhandled error during rotate and crop processing for file %s (%s): %s', (string) $file->id, $s3_path, $e->getMessage()));
            $this->complete($file);
        } finally {
            if ($image instanceof GdImage) {
                imagedestroy($image);
            }
        }
    }

    /**
     * Called after rotate and crop, including when that work is skipped.
     *
     * A subject page in OCR refine is marked done. Subclasses enqueue the next step.
     */
    protected function complete(InputFile $file): void
    {
        if ($file instanceof SubjectFile && $file->status === SubjectPages::STATUS_REFINE) {
            SubjectPages::finish($file);
        }
    }

    public static function rotationEvent(int $angle): string
    {
        return 'Rotated ' . $angle . ' degrees';
    }

    /**
     * GD when it can read the file. Imagick otherwise, including HEIC and TIFF.
     *
     * @return array{0: GdImage, 1: bool} Image, and whether GD decoded it.
     */
    public static function decode(string $bytes): array
    {
        $image = @imagecreatefromstring($bytes);
        if ($image instanceof GdImage) {
            return [$image, true];
        }

        if (HeicToWebp::isHeif($bytes)) {
            try {
                $webp = (new HeicToWebp($bytes))->webp;
                $image = @imagecreatefromstring($webp);
                if ($image instanceof GdImage) {
                    return [$image, true];
                }
            } catch (Throwable $e) {
                error_log(sprintf('[TaskRotateAndCrop] HEIC to WebP conversion failed: %s', $e->getMessage()));
            }
        }

        return [self::imagickToGd($bytes, 0), false];
    }

    /**
     * Undo a clockwise page rotation. GD rotates counter-clockwise.
     * Imagick rotates clockwise, so a file GD cannot read is turned by -$angle.
     */
    public static function rotate(GdImage $image, int $angle, string $sourceBytes, bool $decodedByGd): GdImage
    {
        if ($decodedByGd) {
            return self::rotateGd($image, $angle);
        }

        $rotated = self::imagickToGd($sourceBytes, $angle);
        imagedestroy($image);
        return $rotated;
    }

    public static function rotateGd(GdImage $image, int $angle): GdImage
    {
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        $white = imagecolorallocate($image, 255, 255, 255);
        $rotated = imagerotate($image, $angle, $white === false ? 0 : $white);
        imagedestroy($image);
        if (!$rotated instanceof GdImage) {
            throw new Exception(sprintf('Failed to rotate GD image by %d degrees', $angle));
        }
        return $rotated;
    }

    public static function imagickToGd(string $bytes, int $angle): GdImage
    {
        if (!extension_loaded('imagick')) {
            throw new InvalidArgumentException('The source file is not an image GD or Imagick can read');
        }

        try {
            $imagick = new Imagick();
            $imagick->readImageBlob($bytes);
            $imagick->setIteratorIndex(0);
            if ($angle !== 0) {
                $imagick->setImageBackgroundColor(new ImagickPixel('white'));
                $imagick->rotateImage(new ImagickPixel('white'), -$angle);
            }
            $imagick->setImageFormat('png');
            $png = $imagick->getImageBlob();
            $imagick->clear();
        } catch (ImagickException $e) {
            throw new InvalidArgumentException('Cannot decode image', 0, $e);
        }

        $image = @imagecreatefromstring($png);
        if (!$image instanceof GdImage) {
            throw new InvalidArgumentException(sprintf('Failed to create GD image from rasterized PNG (bytes=%d)', strlen($png)));
        }
        return $image;
    }

    /**
     * Canvas of the global box. Pixels outside the page stay white so the
     * crop matches the proportions passed to resize_to_global().
     *
     * @param array{left: float, top: float, right: float, bottom: float} $box
     */
    public static function crop(GdImage $image, array $box): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $x0 = (int) round($box['left'] * $width);
        $y0 = (int) round($box['top'] * $height);
        $x1 = (int) round($box['right'] * $width);
        $y1 = (int) round($box['bottom'] * $height);
        $outWidth = max(1, $x1 - $x0);
        $outHeight = max(1, $y1 - $y0);

        $canvas = imagecreatetruecolor($outWidth, $outHeight);
        if ($canvas === false) {
            throw new Exception(sprintf('Failed to create cropped image canvas (%dx%d)', $outWidth, $outHeight));
        }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $outWidth, $outHeight, $white === false ? 0 : $white);

        $srcX = max(0, $x0);
        $srcY = max(0, $y0);
        $srcRight = min($width, $x1);
        $srcBottom = min($height, $y1);
        $copyWidth = $srcRight - $srcX;
        $copyHeight = $srcBottom - $srcY;
        if ($copyWidth > 0 && $copyHeight > 0) {
            imagecopy($canvas, $image, $srcX - $x0, $srcY - $y0, $srcX, $srcY, $copyWidth, $copyHeight);
        }
        imagedestroy($image);
        return $canvas;
    }

    public static function storeImage(InputFile $file, GdImage $image, int $type): void
    {
        if ($type === IMAGETYPE_WEBP && !function_exists('imagewebp')) {
            $type = IMAGETYPE_PNG;
        }
        $bytes = self::encode($image, $type);
        $contentType = self::contentType($type);
        ObjectStore::getInstance()->putContents($file->contentKey(), $bytes, $contentType);
        $file->size = strlen($bytes);
        $file->content_type = $contentType;
        $file->saveAttributes();
    }

    public static function outputType(string $bytes): int
    {
        $info = @getimagesizefromstring($bytes);
        $type = is_array($info) ? (int) ($info[2] ?? 0) : 0;
        return match ($type) {
            IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP => $type,
            default => IMAGETYPE_PNG,
        };
    }

    public static function contentType(int $type): string
    {
        return match ($type) {
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_GIF => 'image/gif',
            IMAGETYPE_WEBP => 'image/webp',
            default => 'image/png',
        };
    }

    public static function encode(GdImage $image, int $type): string
    {
        ob_start();
        $encoded = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($image, null, 90),
            IMAGETYPE_GIF => imagegif($image),
            IMAGETYPE_WEBP => imagewebp($image, null, 90),
            default => imagepng($image),
        };
        $data = ob_get_clean();
        if ($encoded === false || !is_string($data) || $data === '') {
            throw new Exception(sprintf('Failed to encode image to type %d', $type));
        }
        return $data;
    }
}
