<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Utils\Image\ReferenceChanger;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ReferenceChangerTest extends TestCase
{
    public function testPointsAAndBMapToTheDestinationFrame(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $changer = new ReferenceChanger([[10, 20], [30, 50]], [[100, 200], [140, 260]]);

        $this->assertSame([100, 200], $changer->transform(10, 20));
        $this->assertSame([140, 260], $changer->transform(30, 50));
        $this->assertSame([80, 160], $changer->transform(0, 0));
    }

    public function testIndependentAxisScales(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $changer = new ReferenceChanger([[0, 0], [10, 20]], [[5, 7], [25, 47]]);

        $this->assertSame([5, 7], $changer->transform(0, 0));
        $this->assertSame([25, 47], $changer->transform(10, 20));
        $this->assertSame([15, 27], $changer->transform(5, 10));
    }

    public function testCoincidentSourceAxesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReferenceChanger([[1, 2], [1, 8]], [[0, 0], [4, 4]]);
    }
}
