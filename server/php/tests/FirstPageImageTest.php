<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Utils\FirstPageImage;
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
}
