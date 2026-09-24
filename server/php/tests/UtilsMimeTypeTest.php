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
        $this->assertSame('application/pdf', Utils::mimeTypeForFilename('paper.pdf'));
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
