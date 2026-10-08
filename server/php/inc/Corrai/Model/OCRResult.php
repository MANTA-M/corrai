<?php

declare(strict_types=1);

namespace Corrai\Model;

use Corrai\Utils\Http\JsonUtils;
use Exception;
use InvalidArgumentException;
use JsonSerializable;

/**
 * OCR words in the format shared by Google OCR and PaddleOCR.
 *
 * Coordinates are proportions of the page, not pixels. `left` and `right` are
 * fractions of the page width. `top` and `bottom` are fractions of the page
 * height. Origin is the top-left corner. A corner may fall outside [0, 1]
 * when Google Vision places the first vertex of a turned box off the page.
 *
 * JSON shape:
 *
 * {
 *   "text": "Une makine",
 *   "words": [
 *     {
 *       "text": "Une",
 *       "page": 0,
 *       "left": 0.0435,
 *       "top": 0.0406,
 *       "right": 0.1242,
 *       "bottom": 0.0656
 *     }
 *   ]
 * }
 *
 * `text` is the full transcription. `page` is zero-based. Google's synchronous
 * call has a single page, so its words use page 0.
 */
class OCRResult implements JsonSerializable
{
    /**
     * @var list<OCRWord>
     */
    public array $words;

    public readonly string $text;

    /**
     * Clockwise angle of the page relative to upright text: 0, 90, 180, or 270.
     *
     * The angle is the one shared by the longest lines. Google Vision places
     * the first vertex of a turned box outside the image, so a corner may be
     * negative; the reading order of the words on each line picks the angle.
     */
    public int $rotation = 0;

    /**
     * @param list<OCRWord> $words
     */
    public function __construct(array $words, string $text)
    {
        foreach ($words as $word) {
            if (!$word instanceof OCRWord) {
                throw new InvalidArgumentException('OCR words must be OCRWord instances');
            }
        }
        $this->words = array_values($words);
        $this->text = $text;
    }

    /**
     * Google OCR output, as returned by Vision::process() or GoogleOCRClient::process().
     *
     * Bounding boxes already use fractions of the page: `left`, `top`, `width`,
     * and `height`. They are stored here as corner proportions. Negative
     * `left` or `top` is kept: on a turned page Google Vision puts the first
     * vertex outside the image, and that extent belongs to the line length.
     *
     * @param array<string, mixed> $output
     */
    public static function from_google(array $output): self
    {
        $text = $output['text'] ?? null;
        if (!is_string($text)) {
            throw new InvalidArgumentException('Google OCR response has no text');
        }
        $boxes = $output['bounding_boxes'] ?? null;
        if (!is_array($boxes)) {
            throw new InvalidArgumentException('Google OCR response has no bounding boxes');
        }

        $words = [];
        foreach ($boxes as $box) {
            if (!is_array($box)) {
                throw new InvalidArgumentException('Google OCR bounding box must be an object');
            }
            $wordText = $box['text'] ?? null;
            if (!is_string($wordText)) {
                throw new InvalidArgumentException('Google OCR word text must be a string');
            }
            $left = self::number($box['left'] ?? null, 'left');
            $top = self::number($box['top'] ?? null, 'top');
            $width = self::number($box['width'] ?? null, 'width');
            $height = self::number($box['height'] ?? null, 'height');

            $x1 = $left;
            $x2 = $left + $width;
            $y1 = $top;
            $y2 = $top + $height;

            $words[] = new OCRWord(
                $wordText,
                min($x1, $x2),
                min($y1, $y2),
                max($x1, $x2),
                max($y1, $y2),
                self::page($box['page'] ?? null),
            );
        }

        $result = new self($words, $text);
        if (array_key_exists('rotation', $output)) {
            $result->rotation = self::quarterTurn($output['rotation']);
        }

        return $result;
    }

    /**
     * PaddleOCR word records: `text`, `page`, and `box` as
     * [left, top, right, bottom] in pixels of that page.
     *
     * `$width` and `$height` are the page size in pixels. A page listed in
     * `$page_sizes` uses that size instead, as page index => [width, height].
     *
     * @param list<array<string, mixed>> $words
     * @param array<int, array{0: int, 1: int}> $page_sizes
     */
    public static function from_paddle(array $words, int $width, int $height, array $page_sizes = []): self
    {
        self::assertSize($width, $height);
        foreach ($page_sizes as $page => $size) {
            if (!is_int($page) || $page < 0 || !is_array($size) || !isset($size[0], $size[1])) {
                throw new InvalidArgumentException('Page sizes must map a page index to [width, height]');
            }
            self::assertSize($size[0], $size[1]);
        }

        $ocrWords = [];
        foreach ($words as $word) {
            if (!is_array($word)) {
                throw new InvalidArgumentException('PaddleOCR word must be an object');
            }
            $wordText = $word['text'] ?? null;
            if (!is_string($wordText)) {
                throw new InvalidArgumentException('PaddleOCR word text must be a string');
            }
            $box = $word['box'] ?? null;
            if (!is_array($box) || !array_key_exists(0, $box) || !array_key_exists(1, $box)
                || !array_key_exists(2, $box) || !array_key_exists(3, $box)) {
                throw new InvalidArgumentException('PaddleOCR box must be [left, top, right, bottom]');
            }
            $page = self::page($word['page'] ?? null);
            $pageWidth = $width;
            $pageHeight = $height;
            if (isset($page_sizes[$page])) {
                $pageWidth = $page_sizes[$page][0];
                $pageHeight = $page_sizes[$page][1];
            }
            $ocrWords[] = OCRWord::from_pixels(
                $wordText,
                self::number($box[0], 'left'),
                self::number($box[1], 'top'),
                self::number($box[2], 'right'),
                self::number($box[3], 'bottom'),
                $pageWidth,
                $pageHeight,
                $page,
            );
        }

        return new self($ocrWords, self::joinText($ocrWords));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function from_array(array $data): self
    {
        $wordsData = $data['words'] ?? null;
        if (!is_array($wordsData)) {
            throw new InvalidArgumentException('OCR result is missing words');
        }

        $words = [];
        foreach ($wordsData as $word) {
            if (!is_array($word)) {
                throw new InvalidArgumentException('OCR word must be an object');
            }
            $words[] = OCRWord::from_array($word);
        }

        $text = $data['text'] ?? null;
        if ($text === null) {
            $text = self::joinText($words);
        } elseif (!is_string($text)) {
            throw new InvalidArgumentException('OCR text must be a string');
        }

        $result = new self($words, $text);
        if (array_key_exists('rotation', $data)) {
            $result->rotation = self::quarterTurn($data['rotation']);
        }

        return $result;
    }

    public static function from_json(string $json): self
    {
        try {
            $data = JsonUtils::decodeArray($json);
        } catch (Exception $e) {
            throw new InvalidArgumentException('Invalid OCR result JSON', 0, $e);
        }
        if (!is_array($data)) {
            throw new InvalidArgumentException('OCR result JSON must be an object');
        }

        return self::from_array($data);
    }

    public function to_json(bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $json = json_encode($this, $flags);
        if (!is_string($json)) {
            throw new InvalidArgumentException('Cannot encode the OCR result');
        }

        return $json;
    }

    /**
     * @return array{text: string, words: list<array{text: string, page: int, left: float, top: float, right: float, bottom: float}>}
     */
    public function to_array(): array
    {
        $data = [
            'text' => $this->text,
            'words' => array_map(
                static fn (OCRWord $word): array => $word->to_array(),
                $this->words,
            ),
        ];
        if ($this->rotation !== 0) {
            $data['rotation'] = $this->rotation;
        }

        return $data;
    }

    /**
     * @return array{text: string, words: list<array{text: string, page: int, left: float, top: float, right: float, bottom: float}>}
     */
    public function jsonSerialize(): array
    {
        return $this->to_array();
    }

    /**
     * True when the page is turned, so `$rotation` is 90, 180, or 270.
     */
    public function is_rotated(): bool
    {
        return $this->rotation !== 0;
    }

    /**
     * Rotate every word so the text is upright, then set `$rotation` to 0.
     *
     * The page is turned clockwise by `$rotation`. Each corner moves back by
     * that angle. Proportions stay fractions of the page: a 90° turn swaps
     * the axes, and `1 - x` uses the normalized edge, including corners that
     * were negative.
     */
    public function rotate_upright(): void
    {
        if ($this->rotation === 0) {
            return;
        }
        $words = [];
        foreach ($this->words as $word) {
            $xs = [];
            $ys = [];
            foreach ([[$word->left, $word->top], [$word->right, $word->top], [$word->right, $word->bottom], [$word->left, $word->bottom]] as [$x, $y]) {
                [$nx, $ny] = $this->unrotate($x, $y);
                $xs[] = $nx;
                $ys[] = $ny;
            }
            $words[] = new OCRWord($word->text, min($xs), min($ys), max($xs), max($ys), $word->page);
        }
        $this->words = $words;
        $this->rotation = 0;
    }

    /**
     * Axis-aligned box around every word, expanded by `$margin` on each side.
     *
     * `$margin` uses the same unit as the coordinates: a fraction of the page.
     *
     * @return array{left: float, top: float, right: float, bottom: float}
     */
    public function get_global_box(float $margin): array
    {
        if ($this->words === []) {
            throw new InvalidArgumentException('OCR result has no words');
        }
        if (!is_finite($margin) || $margin < 0.0) {
            throw new InvalidArgumentException('OCR margin must be a positive number or zero');
        }

        $left = INF;
        $top = INF;
        $right = -INF;
        $bottom = -INF;
        foreach ($this->words as $word) {
            $left = min($left, $word->left);
            $top = min($top, $word->top);
            $right = max($right, $word->right);
            $bottom = max($bottom, $word->bottom);
        }
        $left -= $margin;
        $top -= $margin;
        $right += $margin;
        $bottom += $margin;
        if ($right <= $left || $bottom <= $top) {
            throw new InvalidArgumentException('OCR global box has no area');
        }

        return [
            'left' => $left,
            'top' => $top,
            'right' => $right,
            'bottom' => $bottom,
        ];
    }

    /**
     * Remap every word into the global box, so that box becomes the page.
     *
     * @param array{left: float, top: float, right: float, bottom: float} $box
     */
    public function resize_to_global(array $box): void
    {
        $width = $box['right'] - $box['left'];
        $height = $box['bottom'] - $box['top'];
        $words = [];
        foreach ($this->words as $word) {
            $words[] = new OCRWord(
                $word->text,
                ($word->left - $box['left']) / $width,
                ($word->top - $box['top']) / $height,
                ($word->right - $box['left']) / $width,
                ($word->bottom - $box['top']) / $height,
                $word->page,
            );
        }
        $this->words = $words;
    }

    /**
     * Set `$rotation` to the clockwise angle of the page: 0, 90, 180, or 270.
     */
    public function detectRotation(): int
    {
        $scores = [0 => 0.0, 90 => 0.0, 180 => 0.0, 270 => 0.0];
        $horizontal = 0.0;
        $vertical = 0.0;
        foreach ($this->lines() as $line) {
            if ($line['vertical']) {
                $vertical += $line['length'];
            } else {
                $horizontal += $line['length'];
            }
            if ($line['angle'] !== null) {
                $scores[$line['angle']] += $line['length'];
            }
        }

        $best = 0;
        $bestScore = 0.0;
        foreach ($scores as $angle => $score) {
            if ($score > $bestScore) {
                $best = $angle;
                $bestScore = $score;
            }
        }
        if ($bestScore <= 0.0 && $vertical > $horizontal) {
            $this->rotation = 90;

            return 90;
        }
        $this->rotation = $best;

        return $best;
    }

    /**
     * @return list<array{vertical: bool, length: float, angle: int|null}>
     */
    private function lines(): array
    {
        $byPage = [];
        foreach ($this->words as $word) {
            $byPage[$word->page][] = $word;
        }

        $lines = [];
        foreach ($byPage as $words) {
            foreach (self::linesOf($words) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param list<OCRWord> $words
     * @return list<array{vertical: bool, length: float, angle: int|null}>
     */
    private static function linesOf(array $words): array
    {
        /** @var list<array{vertical: bool, left: float, top: float, right: float, bottom: float, words: list<OCRWord>}> $lines */
        $lines = [];
        foreach ($words as $word) {
            $width = $word->right - $word->left;
            $height = $word->bottom - $word->top;
            if ($width <= 0.0 && $height <= 0.0) {
                continue;
            }
            $vertical = $height > $width;
            $index = self::matchingLine($lines, $word, $vertical);
            if ($index === null) {
                $lines[] = [
                    'vertical' => $vertical,
                    'left' => $word->left,
                    'top' => $word->top,
                    'right' => $word->right,
                    'bottom' => $word->bottom,
                    'words' => [$word],
                ];
                continue;
            }
            $lines[$index]['left'] = min($lines[$index]['left'], $word->left);
            $lines[$index]['top'] = min($lines[$index]['top'], $word->top);
            $lines[$index]['right'] = max($lines[$index]['right'], $word->right);
            $lines[$index]['bottom'] = max($lines[$index]['bottom'], $word->bottom);
            $lines[$index]['words'][] = $word;
        }

        $result = [];
        foreach ($lines as $line) {
            $length = $line['vertical']
                ? $line['bottom'] - $line['top']
                : $line['right'] - $line['left'];
            if ($length <= 0.0) {
                continue;
            }
            $result[] = [
                'vertical' => $line['vertical'],
                'length' => $length,
                'angle' => self::lineAngle($line['words'], $line['vertical']),
            ];
        }

        return $result;
    }

    /**
     * @param list<OCRWord> $words
     */
    private static function lineAngle(array $words, bool $vertical): ?int
    {
        if (count($words) < 2) {
            return null;
        }
        $first = $words[0];
        $last = $words[count($words) - 1];
        if ($vertical) {
            $delta = (($last->top + $last->bottom) - ($first->top + $first->bottom)) / 2;
            if ($delta > 0.0) {
                return 90;
            }
            if ($delta < 0.0) {
                return 270;
            }

            return null;
        }
        $delta = (($last->left + $last->right) - ($first->left + $first->right)) / 2;
        if ($delta > 0.0) {
            return 0;
        }
        if ($delta < 0.0) {
            return 180;
        }

        return null;
    }

    /**
     * Undo a clockwise page rotation. y grows downward.
     *
     * @return array{0: float, 1: float}
     */
    private function unrotate(float $x, float $y): array
    {
        return match ($this->rotation) {
            0 => [$x, $y],
            90 => [$y, 1.0 - $x],
            180 => [1.0 - $x, 1.0 - $y],
            270 => [1.0 - $y, $x],
            default => throw new InvalidArgumentException('OCR rotation must be 0, 90, 180, or 270'),
        };
    }

    private static function quarterTurn(mixed $value): int
    {
        if (is_int($value) && in_array($value, [0, 90, 180, 270], true)) {
            return $value;
        }
        throw new InvalidArgumentException('OCR rotation must be 0, 90, 180, or 270');
    }

    /**
     * @param list<array{vertical: bool, left: float, top: float, right: float, bottom: float, words: list<OCRWord>}> $lines
     */
    private static function matchingLine(array $lines, OCRWord $word, bool $vertical): ?int
    {
        foreach ($lines as $index => $line) {
            if ($line['vertical'] !== $vertical) {
                continue;
            }
            if ($vertical) {
                $overlap = min($line['right'], $word->right) - max($line['left'], $word->left);
                $limit = min($line['right'] - $line['left'], $word->right - $word->left);
            } else {
                $overlap = min($line['bottom'], $word->bottom) - max($line['top'], $word->top);
                $limit = min($line['bottom'] - $line['top'], $word->bottom - $word->top);
            }
            if ($limit > 0.0 && $overlap >= $limit * 0.5) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<OCRWord> $words
     */
    private static function joinText(array $words): string
    {
        return implode(' ', array_map(static fn (OCRWord $word): string => $word->text, $words));
    }

    private static function assertSize(mixed $width, mixed $height): void
    {
        if (!is_int($width) || !is_int($height) || $width < 1 || $height < 1) {
            throw new InvalidArgumentException('Image width and height must be positive');
        }
    }

    private static function number(mixed $value, string $name): float
    {
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_float($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        throw new InvalidArgumentException("OCR {$name} must be a number");
    }

    private static function page(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }
        throw new InvalidArgumentException('OCR page must be a non-negative integer');
    }
}
