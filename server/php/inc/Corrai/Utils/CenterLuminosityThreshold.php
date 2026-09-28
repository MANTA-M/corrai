<?php

namespace Corrai\Utils;

use Exception;
use GdImage;
use InvalidArgumentException;

/**
 * Luminosity that best separates white paper from ink in the center of an image.
 *
 * The sample is a 100×100 pixel square at the center, clipped to the image
 * when it is smaller. Luminosity is Rec. 601 luma on a scale of 0 (black) to
 * 100 (white). The level is the Otsu threshold between those two populations:
 * pixels darker than it are writing, pixels lighter than it are background.
 */
class CenterLuminosityThreshold
{
    public const ZONE_SIZE = 100;

    /** Boundary luminosity, from 0 (black) to 100 (white). */
    public readonly float $level;

    /**
     * @param string $bytes Encoded image bytes (JPEG, PNG, GIF, WebP, or BMP).
     */
    public function __construct(string $bytes)
    {
        if ($bytes === '') {
            throw new InvalidArgumentException('Image bytes must not be empty');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new InvalidArgumentException('Invalid image data');
        }

        $width = $info[0];
        $height = $info[1];
        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException('Image dimensions must be greater than 0');
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new Exception('Failed to decode image data');
        }

        try {
            if (!imageistruecolor($source)) {
                imagepalettetotruecolor($source);
            }

            $zoneWidth = min(self::ZONE_SIZE, $width);
            $zoneHeight = min(self::ZONE_SIZE, $height);
            $zone = imagecrop($source, [
                'x' => intdiv($width - $zoneWidth, 2),
                'y' => intdiv($height - $zoneHeight, 2),
                'width' => $zoneWidth,
                'height' => $zoneHeight,
            ]);
            if ($zone === false) {
                throw new Exception('Failed to crop the center zone');
            }

            try {
                $this->level = self::threshold($zone);
            } finally {
                imagedestroy($zone);
            }
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * Otsu boundary on Rec. 601 luma, scaled to 0–100.
     *
     * When several thresholds separate the two classes equally (a gap with no
     * pixels), the boundary is the middle of that range.
     */
    private static function threshold(GdImage $zone): float
    {
        $width = imagesx($zone);
        $height = imagesy($zone);
        $histogram = array_fill(0, 256, 0);
        $lumaSum = 0.0;
        $count = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($zone, $x, $y);
                $luma = self::luma(
                    ($rgba >> 16) & 0xFF,
                    ($rgba >> 8) & 0xFF,
                    $rgba & 0xFF
                );
                $histogram[$luma]++;
                $lumaSum += $luma;
                $count++;
            }
        }

        if ($count === 0) {
            throw new InvalidArgumentException('Center zone has no pixels');
        }

        $boundary = self::otsuBoundary($histogram, $count);
        if ($boundary === null) {
            return ($lumaSum / $count) / 255 * 100;
        }

        return $boundary / 255 * 100;
    }

    private static function luma(int $r, int $g, int $b): int
    {
        return (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b);
    }

    /**
     * @param array<int, int> $histogram
     * @return float|null Boundary in 0–255 luma, or null when every pixel has the same luma.
     */
    private static function otsuBoundary(array $histogram, int $total): ?float
    {
        $sum = 0.0;
        for ($i = 0; $i < 256; $i++) {
            $sum += $i * $histogram[$i];
        }

        $sumDark = 0.0;
        $weightDark = 0;
        $maxVariance = -1.0;
        $best = [];

        for ($t = 0; $t < 256; $t++) {
            $weightDark += $histogram[$t];
            $sumDark += $t * $histogram[$t];
            $weightLight = $total - $weightDark;
            if ($weightDark === 0 || $weightLight === 0) {
                continue;
            }

            $meanDark = $sumDark / $weightDark;
            $meanLight = ($sum - $sumDark) / $weightLight;
            $variance = $weightDark * $weightLight * ($meanDark - $meanLight) ** 2;

            if ($variance > $maxVariance + 1e-6) {
                $maxVariance = $variance;
                $best = [$t];
            } elseif ($best !== [] && $variance >= $maxVariance - 1e-6) {
                $best[] = $t;
            }
        }

        if ($best === []) {
            return null;
        }

        $lowest = $best[0];
        $highest = $best[array_key_last($best)];

        return ($lowest + $highest) / 2 + 0.5;
    }
}
