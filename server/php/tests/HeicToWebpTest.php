<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Utils\Image\HeicToWebp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class HeicToWebpTest extends TestCase
{
    public function testHeicWithCaptureDateBecomesWebpAndKeepsTheTimestamp(): void
    {
        $this->requireHeicSupport();

        $converted = HeicToWebp::fromFile($this->fixture('capture.heic'));
        $size = @getimagesizefromstring($converted->webp);

        $this->assertNotFalse($size);
        $this->assertSame(16, $size[0]);
        $this->assertSame(10, $size[1]);
        $this->assertSame(IMAGETYPE_WEBP, $size[2]);
        $this->assertSame('2024:03:15 09:41:07', $converted->capturedAt);
        $this->assertNotNull($converted->capturedAtUnix);
        $this->assertStringContainsString('2024:03:15 09:41:07', $converted->webp);

        $path = tempnam(sys_get_temp_dir(), 'heic_webp_');
        $this->assertNotFalse($path);
        $webpPath = $path . '.webp';
        @unlink($path);
        try {
            $converted->write($webpPath);
            $this->assertSame($converted->capturedAtUnix, filemtime($webpPath));
        } finally {
            @unlink($webpPath);
        }
    }

    public function testHeicWithoutExifStillConverts(): void
    {
        $this->requireHeicSupport();

        $converted = HeicToWebp::fromFile($this->fixture('no_exif.heic'));
        $size = @getimagesizefromstring($converted->webp);

        $this->assertNotFalse($size);
        $this->assertSame(IMAGETYPE_WEBP, $size[2]);
        $this->assertNull($converted->capturedAt);
        $this->assertNull($converted->capturedAtUnix);
    }

    public function testNonHeicBytesAreRejected(): void
    {
        $image = imagecreatetruecolor(2, 2);
        $this->assertNotFalse($image);
        ob_start();
        imagepng($image);
        imagedestroy($image);
        $png = ob_get_clean();
        $this->assertNotFalse($png);

        $this->expectException(InvalidArgumentException::class);
        new HeicToWebp($png);
    }

    private function requireHeicSupport(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required');
        }
        $formats = array_map('strtoupper', (new \Imagick())->queryFormats('HEI*'));
        if (!in_array('HEIC', $formats, true) && !in_array('HEIF', $formats, true)) {
            $this->markTestSkipped('Imagick has no HEIC decoder');
        }
        $webp = array_map('strtoupper', (new \Imagick())->queryFormats('WEBP'));
        if (!in_array('WEBP', $webp, true)) {
            $this->markTestSkipped('Imagick has no WebP encoder');
        }
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/fixtures/' . $name;
    }
}
