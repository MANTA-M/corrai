<?php

declare(strict_types=1);

namespace Corrai\Model;

use Corrai\Utils\JsonUtils;
use Exception;
use InvalidArgumentException;
use JsonSerializable;

/**
 * OCR words in the format shared by Google OCR and PaddleOCR.
 *
 * Coordinates are proportions of the page, not pixels. `left` and `right` are
 * fractions of the page width. `top` and `bottom` are fractions of the page
 * height. Origin is the top-left corner.
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
    public readonly array $words;

    public readonly string $text;

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
     * Google OCR output, as returned by GoogleOCRClient::process().
     *
     * Bounding boxes already use fractions of the page: `left`, `top`, `width`,
     * and `height`. They are stored here as corner proportions.
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
            $words[] = new OCRWord(
                $wordText,
                $left,
                $top,
                $left + $width,
                $top + $height,
                self::page($box['page'] ?? null),
            );
        }

        return new self($words, $text);
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

        return new self($words, $text);
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
        return [
            'text' => $this->text,
            'words' => array_map(
                static fn (OCRWord $word): array => $word->to_array(),
                $this->words,
            ),
        ];
    }

    /**
     * @return array{text: string, words: list<array{text: string, page: int, left: float, top: float, right: float, bottom: float}>}
     */
    public function jsonSerialize(): array
    {
        return $this->to_array();
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
