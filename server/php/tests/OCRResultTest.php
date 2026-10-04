<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\OCRResult;
use Corrai\Model\OCRWord;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OCRResultTest extends TestCase
{
    public function testJsonRoundTrip(): void
    {
        $result = new OCRResult(
            [
                new OCRWord('Une', 0.0435, 0.0406, 0.1242, 0.0656, 0),
                new OCRWord('makine', 0.13, 0.04, 0.28, 0.07, 0),
            ],
            'Une makine',
        );

        $restored = OCRResult::from_json($result->to_json());

        $this->assertSame('Une makine', $restored->text);
        $this->assertCount(2, $restored->words);
        $this->assertSame('makine', $restored->words[1]->text);
        $this->assertSame(0, $restored->words[1]->page);
        $this->assertEqualsWithDelta(0.13, $restored->words[1]->left, 1e-12);
        $this->assertEqualsWithDelta(0.04, $restored->words[1]->top, 1e-12);
        $this->assertEqualsWithDelta(0.28, $restored->words[1]->right, 1e-12);
        $this->assertEqualsWithDelta(0.07, $restored->words[1]->bottom, 1e-12);
        $this->assertSame($result->to_array(), $restored->to_array());
    }

    public function testMissingTextIsJoinedFromWords(): void
    {
        $result = OCRResult::from_array([
            'words' => [
                ['text' => 'Une', 'left' => 0.1, 'top' => 0.2, 'right' => 0.3, 'bottom' => 0.4],
                ['text' => 'makine', 'left' => 0.3, 'top' => 0.2, 'right' => 0.5, 'bottom' => 0.4],
            ],
        ]);

        $this->assertSame('Une makine', $result->text);
        $this->assertSame(0, $result->words[0]->page);
    }

    public function testGoogleBoxesStayProportions(): void
    {
        $result = OCRResult::from_google([
            'text' => 'Une makine',
            'bounding_boxes' => [
                [
                    'text' => 'Une',
                    'left' => 0.0435244161358811,
                    'top' => 0.040625,
                    'width' => 0.08067940552016985,
                    'height' => 0.025,
                ],
            ],
            'usage' => null,
        ]);

        $word = $result->words[0];
        $this->assertSame('Une makine', $result->text);
        $this->assertSame(0, $word->page);
        $this->assertEqualsWithDelta(0.0435244161358811, $word->left, 1e-12);
        $this->assertEqualsWithDelta(0.040625, $word->top, 1e-12);
        $this->assertEqualsWithDelta(0.0435244161358811 + 0.08067940552016985, $word->right, 1e-12);
        $this->assertEqualsWithDelta(0.040625 + 0.025, $word->bottom, 1e-12);
    }

    public function testPaddlePixelsBecomeProportionsOfTheImage(): void
    {
        $result = OCRResult::from_paddle(
            [
                ['text' => 'Une', 'page' => 0, 'box' => [40, 80, 140, 120]],
                ['text' => 'page', 'page' => 1, 'box' => [10, 20, 30, 40]],
            ],
            1000,
            2000,
            [1 => [100, 400]],
        );

        $this->assertSame('Une page', $result->text);
        $this->assertEqualsWithDelta(0.04, $result->words[0]->left, 1e-12);
        $this->assertEqualsWithDelta(0.04, $result->words[0]->top, 1e-12);
        $this->assertEqualsWithDelta(0.14, $result->words[0]->right, 1e-12);
        $this->assertEqualsWithDelta(0.06, $result->words[0]->bottom, 1e-12);
        $this->assertEqualsWithDelta(0.1, $result->words[1]->left, 1e-12);
        $this->assertEqualsWithDelta(0.05, $result->words[1]->top, 1e-12);
        $this->assertEqualsWithDelta(0.3, $result->words[1]->right, 1e-12);
        $this->assertEqualsWithDelta(0.1, $result->words[1]->bottom, 1e-12);
        $this->assertSame(1, $result->words[1]->page);
    }

    public function testInvalidJsonIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OCRResult::from_json('{');
    }

    public function testInvertedBoxIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OCRWord('Une', 0.5, 0.1, 0.2, 0.3);
    }
}
