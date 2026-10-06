<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Utils\Image\CenterLuminosityThreshold;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CenterLuminosityThresholdTest extends TestCase
{
    public function testHalfBlackHalfWhiteSplitsAtTheMiddle(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $level = new CenterLuminosityThreshold($this->splitBytes(100, 100, 0, 255));

        $this->assertEqualsWithDelta(50.0, $level->level, 0.0001);
    }

    public function testGapBetweenPaperAndInkUsesTheMiddleOfTheGap(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        // Luma 20 and luma 250. Every cut through the empty gap is equally
        // good, so the shared level is the middle: 135 / 255 * 100.
        $level = new CenterLuminosityThreshold($this->splitBytes(100, 100, 20, 250));

        $this->assertEqualsWithDelta(135 / 255 * 100, $level->level, 0.0001);
    }

    public function testOnlyTheCenterZoneIsSampled(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $width = 300;
        $height = 200;
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $gray = imagecolorallocate($image, 128, 128, 128);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $gray);

        $x0 = intdiv($width - 100, 2);
        $y0 = intdiv($height - 100, 2);
        $black = imagecolorallocate($image, 0, 0, 0);
        $white = imagecolorallocate($image, 255, 255, 255);
        for ($y = $y0; $y < $y0 + 100; $y++) {
            for ($x = $x0; $x < $x0 + 100; $x++) {
                imagesetpixel($image, $x, $y, $x < $x0 + 50 ? $black : $white);
            }
        }

        $level = new CenterLuminosityThreshold($this->pngBytes($image));

        $this->assertEqualsWithDelta(50.0, $level->level, 0.0001);
    }

    public function testZoneIsClippedWhenTheImageIsSmallerThanTheSample(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $level = new CenterLuminosityThreshold($this->splitBytes(40, 60, 0, 255));

        $this->assertEqualsWithDelta(50.0, $level->level, 0.0001);
    }

    public function testUniformImageReturnsItsOwnLuminosity(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $image = imagecreatetruecolor(100, 100);
        $this->assertNotFalse($image);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 99, 99, $white);

        $level = new CenterLuminosityThreshold($this->pngBytes($image));

        $this->assertEqualsWithDelta(100.0, $level->level, 0.0001);
    }

    public function testInvalidBytesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CenterLuminosityThreshold('not-an-image');
    }

    private function splitBytes(int $width, int $height, int $dark, int $light): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $darkColor = imagecolorallocate($image, $dark, $dark, $dark);
        $lightColor = imagecolorallocate($image, $light, $light, $light);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                imagesetpixel($image, $x, $y, $x < intdiv($width, 2) ? $darkColor : $lightColor);
            }
        }

        return $this->pngBytes($image);
    }

    private function pngBytes(\GdImage $image): string
    {
        ob_start();
        imagepng($image);
        imagedestroy($image);
        $bytes = ob_get_clean();
        $this->assertIsString($bytes);
        return $bytes;
    }
}
