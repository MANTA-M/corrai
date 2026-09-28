<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Utils\ImageRedimentioner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ImageRedimentionerTest extends TestCase
{
    public function testImageWithinTheMaximumIsUnchanged(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $bytes = $this->pngBytes(400, 300);
        $redimentioned = new ImageRedimentioner(1568, $bytes);

        $this->assertSame($bytes, $redimentioned->image);
        $this->assertSame(1.0, $redimentioned->factor);
    }

    public function testLongestSideIsReducedAndFactorIsTheScale(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $redimentioned = new ImageRedimentioner(1568, $this->pngBytes(2000, 800));
        $size = @getimagesizefromstring($redimentioned->image);

        $this->assertNotFalse($size);
        $this->assertSame(1568, $size[0]);
        $this->assertSame(627, $size[1]);
        $this->assertEqualsWithDelta(1568 / 2000, $redimentioned->factor, 0.000001);
    }

    public function testPortraitImageUsesTheHeightAsTheLongSide(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $redimentioned = new ImageRedimentioner(100, $this->pngBytes(50, 400));
        $size = @getimagesizefromstring($redimentioned->image);

        $this->assertNotFalse($size);
        $this->assertSame(13, $size[0]);
        $this->assertSame(100, $size[1]);
        $this->assertEqualsWithDelta(100 / 400, $redimentioned->factor, 0.000001);
    }

    public function testInvalidBytesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ImageRedimentioner(100, 'not-an-image');
    }

    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        ob_start();
        imagepng($image);
        imagedestroy($image);
        $bytes = ob_get_clean();
        $this->assertNotFalse($bytes);
        return $bytes;
    }
}
