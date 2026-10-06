<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Utils\Image\FirstPageImage;
use PHPUnit\Framework\TestCase;

class FirstPageImageTest extends TestCase
{
    public function testPngBecomesAJpeg(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $image = imagecreatetruecolor(20, 10);
        $path = tempnam(sys_get_temp_dir(), 'subject_src_');
        $this->assertNotFalse($path);
        imagepng($image, $path);
        imagedestroy($image);

        $jpeg = FirstPageImage::jpegFile($path, 'sujet.png');
        $info = @getimagesize($jpeg);

        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame(20, $info[0]);
        $this->assertSame(10, $info[1]);

        @unlink($path);
        @unlink($jpeg);
    }

    public function testWebpBecomesAJpeg(): void
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            $this->markTestSkipped('GD extension with WebP required');
        }

        $image = imagecreatetruecolor(24, 12);
        $path = tempnam(sys_get_temp_dir(), 'subject_src_');
        $this->assertNotFalse($path);
        imagewebp($image, $path);
        imagedestroy($image);

        $jpeg = FirstPageImage::jpegFile($path, 'sujet.webp');
        $info = @getimagesize($jpeg);

        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame(24, $info[0]);
        $this->assertSame(12, $info[1]);

        @unlink($path);
        @unlink($jpeg);
    }

    public function testHeicBecomesAJpegViaWebp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required');
        }
        $formats = array_map('strtoupper', (new \Imagick())->queryFormats('HEI*'));
        if (!in_array('HEIC', $formats, true) && !in_array('HEIF', $formats, true)) {
            $this->markTestSkipped('Imagick has no HEIC decoder');
        }

        $path = __DIR__ . '/fixtures/capture.heic';
        $jpeg = FirstPageImage::jpegFile($path, 'photo.heic');
        $info = @getimagesize($jpeg);

        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame(16, $info[0]);
        $this->assertSame(10, $info[1]);

        @unlink($jpeg);
    }
}
