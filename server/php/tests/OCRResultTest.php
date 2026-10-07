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

    public function testGoogleBoxesWithNegativeWidthOrHeightAreNormalized(): void
    {
        $result = OCRResult::from_google([
            'text' => 'Examen',
            'bounding_boxes' => [
                [
                    'text' => 'Examen',
                    'left' => 0.936,
                    'top' => 0.346,
                    'width' => -0.006,
                    'height' => 0.059,
                ],
            ],
            'usage' => null,
        ]);

        $word = $result->words[0];
        $this->assertSame('Examen', $word->text);
        $this->assertEqualsWithDelta(0.930, $word->left, 1e-4);
        $this->assertEqualsWithDelta(0.936, $word->right, 1e-4);
        $this->assertTrue($word->right >= $word->left);
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

    public function testUprightLinesAreNotRotated(): void
    {
        $result = OCRResult::from_google([
            'text' => 'Une makine sur la page',
            'bounding_boxes' => [
                ['text' => 'Une', 'left' => 0.04, 'top' => 0.04, 'width' => 0.10, 'height' => 0.02],
                ['text' => 'makine', 'left' => 0.15, 'top' => 0.04, 'width' => 0.16, 'height' => 0.02],
                ['text' => 'sur', 'left' => 0.32, 'top' => 0.04, 'width' => 0.08, 'height' => 0.02],
                ['text' => 'la', 'left' => 0.04, 'top' => 0.08, 'width' => 0.06, 'height' => 0.02],
                ['text' => 'page', 'left' => 0.11, 'top' => 0.08, 'width' => 0.12, 'height' => 0.02],
            ],
        ]);

        $result->detectRotation();
        $this->assertSame(0, $result->rotation);
        $this->assertFalse($result->is_rotated());
    }

    public function testNegativeFirstVerticesMarkATurnedPage(): void
    {
        $result = OCRResult::from_google([
            'text' => 'Une makine',
            'bounding_boxes' => [
                ['text' => 'Une', 'left' => 0.10, 'top' => -0.08, 'width' => 0.02, 'height' => 0.18],
                ['text' => 'makine', 'left' => 0.10, 'top' => 0.12, 'width' => 0.025, 'height' => 0.30],
                ['text' => 'I', 'left' => 0.40, 'top' => 0.20, 'width' => 0.04, 'height' => 0.02],
            ],
        ]);

        $word = $result->words[0];
        $result->detectRotation();
        $this->assertLessThan(0.0, $word->top);
        $this->assertSame(90, $result->rotation);
        $this->assertTrue($result->is_rotated());
    }

    public function testNegativeOriginOnAHorizontalLineIsNotRotated(): void
    {
        $result = OCRResult::from_google([
            'text' => 'Une makine',
            'bounding_boxes' => [
                ['text' => 'Une', 'left' => -0.04, 'top' => 0.10, 'width' => 0.12, 'height' => 0.02],
                ['text' => 'makine', 'left' => 0.10, 'top' => 0.10, 'width' => 0.20, 'height' => 0.02],
            ],
        ]);

        $result->detectRotation();
        $this->assertLessThan(0.0, $result->words[0]->left);
        $this->assertSame(0, $result->rotation);
        $this->assertFalse($result->is_rotated());
    }

    public function testASingleVerticalWordSetsRotation(): void
    {
        $result = OCRResult::from_google([
            'text' => 'I',
            'bounding_boxes' => [
                ['text' => 'I', 'left' => 0.40, 'top' => 0.10, 'width' => 0.02, 'height' => 0.40],
            ],
        ]);

        $result->detectRotation();

        $this->assertSame(90, $result->rotation);
        $this->assertTrue($result->is_rotated());
    }

    public function testRotateUprightRestoresEachQuarterTurn(): void
    {
        $upright = [
            new OCRWord('Une', 0.10, 0.10, 0.28, 0.15),
            new OCRWord('makine', 0.32, 0.10, 0.58, 0.15),
            new OCRWord('page', 0.10, 0.22, 0.30, 0.27),
        ];
        foreach ([0, 90, 180, 270] as $angle) {
            $turned = array_map(fn (OCRWord $word): OCRWord => $this->turnWord($word, $angle), $upright);
            $result = new OCRResult($turned, 'Une makine page');

            $result->detectRotation();
            $this->assertSame($angle, $result->rotation);
            $result->rotate_upright();
            $this->assertSame(0, $result->rotation);
            $this->assertFalse($result->is_rotated());
            foreach ($upright as $index => $word) {
                $this->assertEqualsWithDelta($word->left, $result->words[$index]->left, 1e-9);
                $this->assertEqualsWithDelta($word->top, $result->words[$index]->top, 1e-9);
                $this->assertEqualsWithDelta($word->right, $result->words[$index]->right, 1e-9);
                $this->assertEqualsWithDelta($word->bottom, $result->words[$index]->bottom, 1e-9);
            }
        }
    }

    public function testGlobalBoxGrowsByTheMargin(): void
    {
        $result = new OCRResult(
            [
                new OCRWord('Une', 0.20, 0.30, 0.50, 0.60),
                new OCRWord('makine', 0.40, 0.10, 0.45, 0.20),
            ],
            'Une makine',
        );

        $box = $result->get_global_box(0.05);

        $this->assertEqualsWithDelta(0.15, $box['left'], 1e-12);
        $this->assertEqualsWithDelta(0.05, $box['top'], 1e-12);
        $this->assertEqualsWithDelta(0.55, $box['right'], 1e-12);
        $this->assertEqualsWithDelta(0.65, $box['bottom'], 1e-12);
    }

    public function testResizeToGlobalFitsTheTextInThePage(): void
    {
        $result = new OCRResult(
            [new OCRWord('Une', 0.25, 0.25, 0.75, 0.75)],
            'Une',
        );

        $result->resize_to_global($result->get_global_box(0.25));

        $word = $result->words[0];
        $this->assertEqualsWithDelta(0.25, $word->left, 1e-12);
        $this->assertEqualsWithDelta(0.25, $word->top, 1e-12);
        $this->assertEqualsWithDelta(0.75, $word->right, 1e-12);
        $this->assertEqualsWithDelta(0.75, $word->bottom, 1e-12);
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

    private function turnWord(OCRWord $word, int $angle): OCRWord
    {
        $xs = [];
        $ys = [];
        foreach ([[$word->left, $word->top], [$word->right, $word->top], [$word->right, $word->bottom], [$word->left, $word->bottom]] as [$x, $y]) {
            [$nx, $ny] = match ($angle) {
                0 => [$x, $y],
                90 => [1.0 - $y, $x],
                180 => [1.0 - $x, 1.0 - $y],
                270 => [$y, 1.0 - $x],
                default => throw new InvalidArgumentException('Unexpected angle'),
            };
            $xs[] = $nx;
            $ys[] = $ny;
        }

        return new OCRWord($word->text, min($xs), min($ys), max($xs), max($ys), $word->page);
    }
}
