<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Utils\GridDetector;
use Exception;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GridDetectorTest extends TestCase
{
    public function testThickLinesAndVerticalStepOnASeyesGrid(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $grid = new GridDetector($this->seyesBytes());

        $this->assertSame(40, $grid->firstVerticalX);
        $this->assertSame(64, $grid->verticalStep);
        $this->assertSame([40, 104, 168, 232, 296, 360], $grid->verticalLineXs);
        $this->assertSame(24, $grid->firstHorizontalY);
        $this->assertSame(64, $grid->horizontalStep);
        $this->assertSame([24, 88, 152, 216, 280], $grid->thickLineYs);
    }

    public function testUniformRulingUsesEveryLine(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $width = 220;
        $height = 220;
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $white = imagecolorallocate($image, 255, 255, 255);
        $ink = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $white);
        for ($y = 30; $y <= 190; $y += 20) {
            imageline($image, 0, $y, $width - 1, $y, $ink);
        }
        for ($x = 20; $x <= 180; $x += 40) {
            imageline($image, $x, 0, $x, $height - 1, $ink);
        }

        $grid = new GridDetector($this->pngBytes($image));

        $this->assertSame(30, $grid->firstHorizontalY);
        $this->assertSame(20, $grid->horizontalStep);
        $this->assertSame(20, $grid->firstVerticalX);
        $this->assertSame(40, $grid->verticalStep);
    }

    public function testDictationPageRuling(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $path = dirname(__DIR__, 3) . '/doc/dictee_fee/dictée_micha.jpg';
        $bytes = file_get_contents($path);
        $this->assertIsString($bytes);

        $grid = new GridDetector($bytes);

        $this->assertSame(729, $grid->firstVerticalX);
        $this->assertSame(73, $grid->verticalStep);
        $this->assertSame(1077, $grid->firstHorizontalY);
        $this->assertSame(73, $grid->horizontalStep);
        $this->assertSame($grid->verticalLineXs[0], $grid->firstVerticalX);
        $this->assertSame($grid->thickLineYs[0], $grid->firstHorizontalY);
        $this->assertSame(
            [729, 802, 874, 947, 1020, 1092, 1164, 1237, 1308, 1381, 1455, 1527, 1601, 1674, 1746, 1822, 1894, 1965],
            $grid->verticalLineXs
        );
        $this->assertSame(
            [1077, 1147, 1220, 1291, 1364, 1437, 1510, 1583, 1658, 1733, 1809, 1886],
            $grid->thickLineYs
        );
    }

    public function testBlankPageIsRejected(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $image = imagecreatetruecolor(80, 80);
        $this->assertNotFalse($image);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 79, 79, $white);

        $this->expectException(Exception::class);
        new GridDetector($this->pngBytes($image));
    }

    public function testInvalidBytesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GridDetector('not-an-image');
    }

    private function seyesBytes(): string
    {
        $width = 400;
        $height = 320;
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $white = imagecolorallocate($image, 255, 255, 255);
        $ink = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $white);
        for ($y = 24; $y <= 280; $y += 16) {
            $thick = ($y - 24) % 64 === 0;
            imagefilledrectangle($image, 0, $thick ? $y - 1 : $y, $width - 1, $thick ? $y + 1 : $y, $ink);
        }
        for ($x = 40; $x <= 360; $x += 64) {
            imageline($image, $x, 0, $x, $height - 1, $ink);
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
