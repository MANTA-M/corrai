<?php

namespace Corrai\Utils\Image;

use DateTimeImmutable;
use Exception;
use Imagick;
use ImagickException;
use InvalidArgumentException;

/**
 * Converts HEIC/HEIF image bytes to WebP and keeps the capture timestamp
 * when EXIF DateTimeOriginal (or a close equivalent) is present.
 *
 * The date stays in the WebP EXIF profile, and on the file mtime when the
 * WebP is written to disk.
 */
class HeicToWebp
{
    public readonly string $webp;

    /** EXIF capture datetime, typically "YYYY:MM:DD HH:MM:SS". */
    public readonly ?string $capturedAt;

    public readonly ?int $capturedAtUnix;

    public function __construct(string $bytes)
    {
        if ($bytes === '') {
            throw new InvalidArgumentException('Image bytes must not be empty');
        }
        if (!extension_loaded('imagick')) {
            throw new Exception('Imagick is required to convert HEIC images');
        }
        if (!self::isHeif($bytes)) {
            throw new InvalidArgumentException('The source is not a HEIC/HEIF image');
        }

        try {
            $imagick = new Imagick();
            $imagick->readImageBlob($bytes);
            $imagick->setIteratorIndex(0);
            if (method_exists($imagick, 'autoOrient')) {
                $imagick->autoOrient();
            }
        } catch (ImagickException $exception) {
            throw new InvalidArgumentException('Cannot decode HEIC image', 0, $exception);
        }

        $exif = self::profile($imagick, 'exif');
        $capturedAt = self::dateFromExifProfile($exif)
            ?? self::dateFromImagickProperties($imagick);
        $capturedAtUnix = self::unixFromExifDate($capturedAt);

        $imagick->setImageFormat('webp');
        $imagick->setImageCompressionQuality(85);
        if ($exif !== null) {
            try {
                $imagick->setImageProfile('exif', $exif);
            } catch (ImagickException) {
                // The WebP is still usable without the capture date.
            }
        }
        if ($capturedAt !== null) {
            $imagick->setImageProperty('exif:DateTimeOriginal', $capturedAt);
            $imagick->setImageProperty('exif:DateTime', $capturedAt);
        }

        $webp = $imagick->getImageBlob();
        $imagick->clear();
        if ($webp === '') {
            throw new Exception('Failed to encode WebP');
        }

        $this->webp = $webp;
        $this->capturedAt = $capturedAt;
        $this->capturedAtUnix = $capturedAtUnix;
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("File is missing or unreadable: {$path}");
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException("Cannot read image: {$path}");
        }
        return new self($bytes);
    }

    public function write(string $destination): void
    {
        $dir = dirname($destination);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new Exception("Cannot write WebP to {$destination}");
        }
        if (file_put_contents($destination, $this->webp) === false) {
            throw new Exception("Cannot write WebP to {$destination}");
        }
        if ($this->capturedAtUnix !== null) {
            touch($destination, $this->capturedAtUnix);
        }
    }

    public static function isHeif(string $bytes): bool
    {
        if (strlen($bytes) < 12 || substr($bytes, 4, 4) !== 'ftyp') {
            return false;
        }
        $brands = substr($bytes, 8, min(256, strlen($bytes) - 8));
        if (str_contains($brands, 'avif') || str_contains($brands, 'avis')) {
            return false;
        }
        foreach (['heic', 'heix', 'heif', 'heim', 'heis', 'mif1'] as $brand) {
            if (str_contains($brands, $brand)) {
                return true;
            }
        }
        return false;
    }

    private static function profile(Imagick $imagick, string $name): ?string
    {
        try {
            $profile = $imagick->getImageProfile($name);
        } catch (ImagickException) {
            return null;
        }
        return is_string($profile) && $profile !== '' ? $profile : null;
    }

    private static function dateFromImagickProperties(Imagick $imagick): ?string
    {
        foreach (['exif:DateTimeOriginal', 'exif:DateTimeDigitized', 'exif:DateTime'] as $key) {
            try {
                $value = $imagick->getImageProperty($key);
            } catch (ImagickException) {
                continue;
            }
            if (is_string($value) && self::unixFromExifDate($value) !== null) {
                return $value;
            }
        }
        return null;
    }

    /**
     * @return string|null EXIF datetime "YYYY:MM:DD HH:MM:SS"
     */
    private static function dateFromExifProfile(?string $exif): ?string
    {
        if ($exif === null) {
            return null;
        }
        if (str_starts_with($exif, "Exif\0\0")) {
            $exif = substr($exif, 6);
        }
        if (strlen($exif) < 8) {
            return null;
        }

        $endian = substr($exif, 0, 2);
        $be = $endian === 'MM';
        if (!$be && $endian !== 'II') {
            return null;
        }

        $u16 = static function (int $offset) use ($exif, $be): ?int {
            if ($offset < 0 || $offset + 2 > strlen($exif)) {
                return null;
            }
            $unpacked = unpack($be ? 'n' : 'v', substr($exif, $offset, 2));
            return $unpacked === false ? null : $unpacked[1];
        };
        $u32 = static function (int $offset) use ($exif, $be): ?int {
            if ($offset < 0 || $offset + 4 > strlen($exif)) {
                return null;
            }
            $unpacked = unpack($be ? 'N' : 'V', substr($exif, $offset, 4));
            return $unpacked === false ? null : $unpacked[1];
        };

        $ifd0 = $u32(4);
        if ($ifd0 === null) {
            return null;
        }

        $dates = self::readIfdDates($exif, $ifd0, $u16, $u32);
        $exifIfd = $dates['exifIfd'];
        if ($exifIfd !== null) {
            $dates = array_merge($dates, self::readIfdDates($exif, $exifIfd, $u16, $u32));
        }

        foreach (['original', 'digitized', 'datetime'] as $key) {
            if (isset($dates[$key]) && is_string($dates[$key]) && self::unixFromExifDate($dates[$key]) !== null) {
                return $dates[$key];
            }
        }
        return null;
    }

    /**
     * @param callable(int): ?int $u16
     * @param callable(int): ?int $u32
     * @return array{original:?string,digitized:?string,datetime:?string,exifIfd:?int}
     */
    private static function readIfdDates(string $exif, int $ifd, callable $u16, callable $u32): array
    {
        $result = [
            'original' => null,
            'digitized' => null,
            'datetime' => null,
            'exifIfd' => null,
        ];
        $count = $u16($ifd);
        if ($count === null) {
            return $result;
        }

        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + ($i * 12);
            $tag = $u16($entry);
            $type = $u16($entry + 2);
            $countValues = $u32($entry + 4);
            $valueOffset = $u32($entry + 8);
            if ($tag === null || $type === null || $countValues === null || $valueOffset === null) {
                break;
            }

            if ($tag === 0x8769 && $type === 4) {
                $result['exifIfd'] = $valueOffset;
                continue;
            }

            // ASCII
            if ($type !== 2 || $countValues < 19) {
                continue;
            }
            $dataOffset = $countValues <= 4 ? $entry + 8 : $valueOffset;
            $raw = substr($exif, $dataOffset, $countValues);
            $text = trim(str_replace("\0", '', $raw));
            if ($tag === 0x9003) {
                $result['original'] = $text;
            } elseif ($tag === 0x9004) {
                $result['digitized'] = $text;
            } elseif ($tag === 0x0132) {
                $result['datetime'] = $text;
            }
        }

        return $result;
    }

    private static function unixFromExifDate(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('Y:m:d H:i:s', $value);
        if ($parsed === false) {
            return null;
        }
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }
        return $parsed->getTimestamp();
    }
}
