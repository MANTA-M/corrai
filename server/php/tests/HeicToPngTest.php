<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Utils\Image\HeicToPng;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class HeicToPngTest extends TestCase
{
    public function testHeicWithCaptureDateBecomesPngAndKeepsTheTimestamp(): void
    {
        $this->requireHeicSupport();

        $converted = HeicToPng::fromFile($this->fixture('capture.heic'));
        $size = @getimagesizefromstring($converted->png);

        $this->assertNotFalse($size);
        $this->assertSame(16, $size[0]);
        $this->assertSame(10, $size[1]);
        $this->assertSame(IMAGETYPE_PNG, $size[2]);
        $this->assertSame('2024:03:15 09:41:07', $converted->capturedAt);
        $this->assertNotNull($converted->capturedAtUnix);
        $this->assertStringContainsString("exif:DateTimeOriginal\0" . '2024:03:15 09:41:07', $converted->png);
        $this->assertNotFalse(strpos($converted->png, 'eXIf'));
        $this->assertSame(
            $converted->capturedAtUnix,
            $this->pngTimeUnix($converted->png)
        );

        $path = tempnam(sys_get_temp_dir(), 'heic_png_');
        $this->assertNotFalse($path);
        $pngPath = $path . '.png';
        @unlink($path);
        try {
            $converted->write($pngPath);
            $this->assertSame($converted->capturedAtUnix, filemtime($pngPath));
        } finally {
            @unlink($pngPath);
        }
    }

    public function testHeicWithoutExifStillConverts(): void
    {
        $this->requireHeicSupport();

        $converted = HeicToPng::fromFile($this->fixture('no_exif.heic'));
        $size = @getimagesizefromstring($converted->png);

        $this->assertNotFalse($size);
        $this->assertSame(IMAGETYPE_PNG, $size[2]);
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
        new HeicToPng($png);
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
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/fixtures/' . $name;
    }

    private function pngTimeUnix(string $png): ?int
    {
        $offset = 8;
        $length = strlen($png);
        while ($offset + 12 <= $length) {
            $size = unpack('N', substr($png, $offset, 4));
            if ($size === false) {
                return null;
            }
            $chunkLength = $size[1];
            $type = substr($png, $offset + 4, 4);
            if ($type === 'tIME' && $chunkLength === 7) {
                $payload = substr($png, $offset + 8, 7);
                $parts = unpack('nyear/Cmonth/Cday/Chour/Cminute/Csecond', $payload);
                if ($parts === false) {
                    return null;
                }
                return (new \DateTimeImmutable(sprintf(
                    '%04d-%02d-%02d %02d:%02d:%02d',
                    $parts['year'],
                    $parts['month'],
                    $parts['day'],
                    $parts['hour'],
                    $parts['minute'],
                    $parts['second']
                )))->getTimestamp();
            }
            $offset += 12 + $chunkLength;
        }
        return null;
    }
}
