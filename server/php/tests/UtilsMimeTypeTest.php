<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Utils\Utils;
use PHPUnit\Framework\TestCase;

class UtilsMimeTypeTest extends TestCase
{
    public function testMimeTypeFromKnownExtension(): void
    {
        $this->assertSame('image/png', Utils::mimeTypeForFilename('scan.PNG', 'application/octet-stream'));
        $this->assertSame('image/jpeg', Utils::mimeTypeForFilename('photo.jpg'));
        $this->assertSame('image/webp', Utils::mimeTypeForFilename('image.webp'));
        $this->assertSame('image/webp', Utils::mimeTypeForFilename('PHOTO.WEBP'));
        $this->assertSame('application/pdf', Utils::mimeTypeForFilename('paper.pdf'));
        $this->assertSame('image/heic', Utils::mimeTypeForFilename('photo.HEIC'));
        $this->assertSame('image/heif', Utils::mimeTypeForFilename('scan.heif'));
    }

    public function testMimeTypeFallsBackToStoredType(): void
    {
        $this->assertSame(
            'application/x-custom',
            Utils::mimeTypeForFilename('file.bin', 'application/x-custom')
        );
        $this->assertSame('application/octet-stream', Utils::mimeTypeForFilename('file.bin'));
    }
}
