<?php

declare(strict_types=1);

namespace Corrai\Model;

use InvalidArgumentException;
use JsonSerializable;

/**
 * One recognized word and its box.
 *
 * `left` and `right` are fractions of the page width.
 * `top` and `bottom` are fractions of the page height.
 * Origin is the top-left corner. The box is axis-aligned.
 */
class OCRWord implements JsonSerializable
{
    public function __construct(
        public readonly string $text,
        public readonly float $left,
        public readonly float $top,
        public readonly float $right,
        public readonly float $bottom,
        public readonly int $page = 0,
    ) {
        self::assertProportion($left, 'left');
        self::assertProportion($top, 'top');
        self::assertProportion($right, 'right');
        self::assertProportion($bottom, 'bottom');
        if ($right < $left) {
            throw new InvalidArgumentException('OCR right must be greater than or equal to left');
        }
        if ($bottom < $top) {
            throw new InvalidArgumentException('OCR bottom must be greater than or equal to top');
        }
        if ($page < 0) {
            throw new InvalidArgumentException('OCR page must be a non-negative integer');
        }
    }

    /**
     * Build a word from a pixel box [left, top, right, bottom].
     *
     * Each edge is divided by the length of its dimension: horizontal edges by
     * `$width`, vertical edges by `$height`.
     */
    public static function from_pixels(
        string $text,
        float $left,
        float $top,
        float $right,
        float $bottom,
        int $width,
        int $height,
        int $page = 0,
    ): self {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Image width and height must be positive');
        }

        return new self(
            $text,
            $left / $width,
            $top / $height,
            $right / $width,
            $bottom / $height,
            $page,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function from_array(array $data): self
    {
        $text = $data['text'] ?? null;
        if (!is_string($text)) {
            throw new InvalidArgumentException('OCR word text must be a string');
        }

        return new self(
            $text,
            self::number($data['left'] ?? null, 'left'),
            self::number($data['top'] ?? null, 'top'),
            self::number($data['right'] ?? null, 'right'),
            self::number($data['bottom'] ?? null, 'bottom'),
            self::page($data['page'] ?? null),
        );
    }

    /**
     * @return array{text: string, page: int, left: float, top: float, right: float, bottom: float}
     */
    public function to_array(): array
    {
        return [
            'text' => $this->text,
            'page' => $this->page,
            'left' => $this->left,
            'top' => $this->top,
            'right' => $this->right,
            'bottom' => $this->bottom,
        ];
    }

    /**
     * @return array{text: string, page: int, left: float, top: float, right: float, bottom: float}
     */
    public function jsonSerialize(): array
    {
        return $this->to_array();
    }

    private static function assertProportion(float $value, string $name): void
    {
        if (!is_finite($value)) {
            throw new InvalidArgumentException("OCR {$name} must be finite");
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
