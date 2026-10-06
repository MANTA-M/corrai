<?php

namespace Corrai\Utils\Image;

use Corrai\Utils\Http\WSException;
use Corrai\Utils\Utils;

/**
 * Raster of the first page of a subject file, as JPEG bytes on disk.
 * Gemini Flash Lite accepts images and rejects a PDF document.
 */
class FirstPageImage
{
    /**
     * @return string Path of a temporary JPEG. The caller deletes it.
     */
    public static function jpegFile(string $sourcePath, string $filename): string
    {
        $bytes = file_get_contents($sourcePath);
        if ($bytes === false || $bytes === '') {
            throw new WSException('Cannot read subject file', 400);
        }

        $mime = strtolower(trim(explode(';', Utils::mimeTypeForFilename($filename))[0]));
        if (str_starts_with($bytes, '%PDF') || $mime === 'application/pdf') {
            return self::pdfFirstPage($sourcePath);
        }

        if (!str_starts_with($mime, 'image/') && @getimagesizefromstring($bytes) === false) {
            throw new WSException('Subject file must be a PDF or an image', 400);
        }

        return self::writeJpeg($bytes);
    }

    private static function pdfFirstPage(string $path): string
    {
        if (extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick();
                $imagick->setResolution(120, 120);
                $imagick->readImage($path . '[0]');
                $imagick->setImageFormat('jpeg');
                $imagick->setImageCompressionQuality(82);
                $blob = $imagick->getImageBlob();
                $imagick->clear();
                if ($blob !== '') {
                    return self::writeJpeg($blob);
                }
            } catch (\Throwable $exception) {
                error_log('Imagick first page failed: ' . $exception->getMessage());
            }
        }

        $binary = self::executable('pdftoppm');
        if ($binary !== null) {
            $base = tempnam(sys_get_temp_dir(), 'subject_pdf_');
            if ($base === false) {
                throw new WSException('Cannot create a temporary file', 500);
            }
            $command = escapeshellarg($binary)
                . ' -jpeg -f 1 -l 1 -singlefile -r 120 '
                . escapeshellarg($path) . ' ' . escapeshellarg($base);
            exec($command, $output, $code);
            $jpeg = $base . '.jpg';
            @unlink($base);
            if ($code === 0 && is_file($jpeg)) {
                return $jpeg;
            }
            @unlink($jpeg);
        }

        throw new WSException('Cannot read the first page of the subject', 400);
    }

    private static function writeJpeg(string $bytes): string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            throw new WSException('Cannot encode the subject page', 500);
        }

        $resized = (new ImageRedimentioner(1568, $bytes))->image;
        $image = @imagecreatefromstring($resized);
        if ($image === false) {
            throw new WSException('Cannot read subject image', 400);
        }

        $path = tempnam(sys_get_temp_dir(), 'subject_page_');
        if ($path === false) {
            imagedestroy($image);
            throw new WSException('Cannot create a temporary file', 500);
        }

        $written = imagejpeg($image, $path, 82);
        imagedestroy($image);
        if ($written !== true) {
            @unlink($path);
            throw new WSException('Cannot encode the subject page', 500);
        }

        return $path;
    }

    private static function executable(string $name): ?string
    {
        $path = getenv('PATH') ?: '';
        foreach (explode(':', $path) as $directory) {
            $candidate = rtrim($directory, '/') . '/' . $name;
            if ($directory !== '' && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
