<?php

namespace Corrai\Utils;

use Exception;
use GdImage;
use InvalidArgumentException;

/**
 * Ruling of a page: thick horizontal lines, and every vertical line.
 *
 * One hundred columns spread across the center are read from top to bottom,
 * and one hundred rows spread across the center are read from left to right.
 * A pixel belongs to the ruling when its Rec. 601 luminosity, on the same
 * 0–100 scale as CenterLuminosityThreshold, is below that white level.
 * Vertical lines must also be darker than the paper just beside them, so a
 * horizontal line is not counted as a vertical one.
 *
 * Pages mix thick and thin horizontals. The thick ones are the phase of the
 * grid whose lines are widest. The result is the first line of each series
 * and the step, in pixels, measured through the center of the image.
 */
class GridDetector
{
    public const SAMPLE_COUNT = 100;

    /** White level from CenterLuminosityThreshold, from 0 (black) to 100 (white). */
    public readonly float $whiteLevel;

    /** @var list<int> Y of each thick horizontal line, top to bottom. */
    public readonly array $thickLineYs;

    /** @var list<int> X of each vertical line, left to right. */
    public readonly array $verticalLineXs;

    public readonly int $firstVerticalX;

    public readonly int $verticalStep;

    public readonly int $firstHorizontalY;

    public readonly int $horizontalStep;

    /**
     * @param string $bytes Encoded image bytes (JPEG, PNG, GIF, WebP, or BMP).
     */
    public function __construct(string $bytes)
    {
        $this->whiteLevel = (new CenterLuminosityThreshold($bytes))->level;

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

            $horizontal = self::thickHorizontalLines($source, $width, $height, $this->whiteLevel);
            $vertical = self::verticalLines($source, $width, $height, $this->whiteLevel);
        } finally {
            imagedestroy($source);
        }

        $this->thickLineYs = $horizontal['positions'];
        $this->firstHorizontalY = $horizontal['positions'][0];
        $this->horizontalStep = $horizontal['step'];
        $this->verticalLineXs = $vertical['positions'];
        $this->firstVerticalX = $vertical['positions'][0];
        $this->verticalStep = $vertical['step'];
    }

    /**
     * @return array{positions: list<int>, step: int}
     */
    private static function thickHorizontalLines(GdImage $source, int $width, int $height, float $whiteLevel): array
    {
        $columns = self::samplePositions($width, self::SAMPLE_COUNT, 0.15);
        $profile = array_fill(0, $height, 0);
        foreach ($columns as $x) {
            for ($y = 0; $y < $height; $y++) {
                if (self::luma($source, $x, $y) < $whiteLevel) {
                    $profile[$y]++;
                }
            }
        }

        $need = (int) ceil(0.4 * count($columns));
        $peaks = self::runs($profile, $need, 24);
        $lines = self::rulingLines($peaks, 8, (int) max(12, floor($height * 0.15)));
        $thick = self::thickPhase($lines);

        return [
            'positions' => array_column($thick, 'pos'),
            'step' => self::stepOf(array_column($thick, 'pos')),
        ];
    }

    /**
     * @return array{positions: list<int>, step: int}
     */
    private static function verticalLines(GdImage $source, int $width, int $height, float $whiteLevel): array
    {
        $rows = self::samplePositions($height, self::SAMPLE_COUNT, 0.2);
        $score = array_fill(0, $width, 0);
        $radius = 5;
        foreach ($rows as $y) {
            $luma = [];
            for ($x = 0; $x < $width; $x++) {
                $luma[$x] = self::luma($source, $x, $y);
            }
            for ($x = $radius; $x < $width - $radius; $x++) {
                $z = $luma[$x];
                if ($z < $whiteLevel && $z + 4 < $luma[$x - $radius] && $z + 4 < $luma[$x + $radius]) {
                    $score[$x]++;
                }
            }
        }

        $need = max(8, (int) ceil(0.12 * count($rows)));
        $peaks = [];
        for ($x = 2; $x < $width - 2; $x++) {
            if ($score[$x] < $need) {
                continue;
            }
            if ($score[$x] < $score[$x - 1] || $score[$x] < $score[$x + 1]) {
                continue;
            }
            if ($score[$x] < $score[$x - 2] || $score[$x] < $score[$x + 2]) {
                continue;
            }
            $peaks[] = ['pos' => $x, 'score' => $score[$x], 'width' => 1];
        }

        $lines = self::rulingLines($peaks, 15, (int) max(40, floor($width * 0.2)));

        return [
            'positions' => array_column($lines, 'pos'),
            'step' => self::stepOf(array_column($lines, 'pos')),
        ];
    }

    /**
     * Collapse active rows into one peak per line. Runs wider than a ruling
     * line are shadows or handwriting and are left out.
     *
     * @param array<int, int> $profile
     * @return list<array{pos: int, score: int, width: int}>
     */
    private static function runs(array $profile, int $need, int $maxWidth): array
    {
        $peaks = [];
        $length = count($profile);
        $y = 0;
        while ($y < $length) {
            if ($profile[$y] < $need) {
                $y++;
                continue;
            }
            $start = $y;
            $best = $profile[$y];
            while ($y < $length && $profile[$y] >= $need) {
                if ($profile[$y] > $best) {
                    $best = $profile[$y];
                }
                $y++;
            }
            $width = $y - $start;
            if ($width > $maxWidth) {
                continue;
            }
            $peaks[] = [
                'pos' => intdiv($start + $y - 1, 2),
                'score' => $best,
                'width' => $width,
            ];
        }

        return $peaks;
    }

    /**
     * Lines that share one regular step, walked from the center of the page.
     *
     * @param list<array{pos: int, score: int, width: int}> $peaks
     * @return list<array{pos: int, score: int, width: int, index: int}>
     */
    private static function rulingLines(array $peaks, int $minGap, int $maxGap): array
    {
        if (count($peaks) < 3) {
            throw new Exception('Grid was not found');
        }

        usort($peaks, fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);
        $peaks = self::mergeClose($peaks, (int) max(3, floor($minGap / 2)));
        $step = self::dominantGap($peaks, $minGap, $maxGap);
        $tol = max(3, (int) round($step * 0.10));
        $seed = self::seedOnGrid($peaks, $step, $tol);
        if ($seed === null) {
            throw new Exception('Grid was not found');
        }

        $chosen = [$seed => true];
        $cursor = $peaks[$seed]['pos'];
        while (true) {
            $next = self::peakNear($peaks, $chosen, $cursor + $step, $tol)
                ?? self::peakNear($peaks, $chosen, $cursor + 2 * $step, $tol);
            if ($next === null || $peaks[$next]['pos'] <= $cursor) {
                break;
            }
            $chosen[$next] = true;
            $cursor = $peaks[$next]['pos'];
        }

        $cursor = $peaks[$seed]['pos'];
        while (true) {
            $next = self::peakNear($peaks, $chosen, $cursor - $step, $tol)
                ?? self::peakNear($peaks, $chosen, $cursor - 2 * $step, $tol);
            if ($next === null || $peaks[$next]['pos'] >= $cursor) {
                break;
            }
            $chosen[$next] = true;
            $cursor = $peaks[$next]['pos'];
        }

        $indexes = array_keys($chosen);
        sort($indexes);
        if (count($indexes) < 3) {
            throw new Exception('Grid was not found');
        }

        $lines = [];
        $index = 0;
        $previous = null;
        foreach ($indexes as $i) {
            if ($previous !== null) {
                $index += max(1, (int) round(($peaks[$i]['pos'] - $previous) / $step));
            }
            $lines[] = $peaks[$i] + ['index' => $index];
            $previous = $peaks[$i]['pos'];
        }

        return $lines;
    }

    /**
     * Widest repeating phase. A grid with only one thickness keeps every line.
     *
     * @param list<array{pos: int, score: int, width: int, index: int}> $lines
     * @return list<array{pos: int, score: int, width: int, index: int}>
     */
    private static function thickPhase(array $lines): array
    {
        $bestGroup = 0;
        $bestPhase = 0;
        $bestContrast = 0.5;
        $count = count($lines);

        for ($group = 2; $group <= 6; $group++) {
            if ($count < $group * 2) {
                continue;
            }
            $widths = array_fill(0, $group, []);
            foreach ($lines as $line) {
                $widths[$line['index'] % $group][] = $line['width'];
            }
            $medians = [];
            foreach ($widths as $phase => $values) {
                if ($values === []) {
                    $medians[$phase] = 0.0;
                    continue;
                }
                sort($values);
                $medians[$phase] = (float) $values[intdiv(count($values), 2)];
            }
            $phase = array_search(max($medians), $medians, true);
            $others = $medians;
            unset($others[$phase]);
            $rest = array_sum($others) / count($others);
            $contrast = $medians[$phase] - $rest;
            $closerGroup = abs($contrast - $bestContrast) <= 0.01 && $group > $bestGroup;
            if ($contrast > $bestContrast + 0.01 || $closerGroup) {
                $bestContrast = $contrast;
                $bestGroup = $group;
                $bestPhase = $phase;
            }
        }

        if ($bestGroup === 0) {
            return $lines;
        }

        return array_values(array_filter(
            $lines,
            fn (array $line): bool => $line['index'] % $bestGroup === $bestPhase
        ));
    }

    /**
     * Step shared by the strongest lines. Faint peaks are handwriting.
     *
     * @param list<array{pos: int, score: int, width: int}> $peaks
     */
    private static function dominantGap(array $peaks, int $minGap, int $maxGap): int
    {
        $maxScore = 0;
        foreach ($peaks as $peak) {
            $maxScore = max($maxScore, $peak['score']);
        }
        $strong = $maxScore * 0.22;
        $positions = [];
        foreach ($peaks as $peak) {
            if ($peak['score'] >= $strong) {
                $positions[] = $peak['pos'];
            }
        }
        if (count($positions) < 3) {
            $positions = array_column($peaks, 'pos');
        }

        $gaps = [];
        $count = count($positions);
        for ($i = 1; $i < $count; $i++) {
            $gap = $positions[$i] - $positions[$i - 1];
            if ($gap >= $minGap && $gap <= $maxGap) {
                $gaps[] = $gap;
            }
        }
        if ($gaps === []) {
            throw new Exception('Grid was not found');
        }

        $bestGap = $gaps[0];
        $bestCount = -1;
        foreach ($gaps as $gap) {
            $near = 0;
            $limit = max(1, (int) round($gap * 0.12));
            foreach ($gaps as $other) {
                if (abs($other - $gap) <= $limit) {
                    $near++;
                }
            }
            if ($near > $bestCount || ($near === $bestCount && $gap < $bestGap)) {
                $bestCount = $near;
                $bestGap = $gap;
            }
        }

        $cluster = [];
        $limit = max(1, (int) round($bestGap * 0.12));
        foreach ($gaps as $gap) {
            if (abs($gap - $bestGap) <= $limit) {
                $cluster[] = $gap;
            }
        }
        sort($cluster);

        return (int) round($cluster[intdiv(count($cluster), 2)]);
    }

    /**
     * Peak whose regular step explains the most other peaks around the middle.
     *
     * @param list<array{pos: int, score: int, width: int}> $peaks
     */
    private static function seedOnGrid(array $peaks, int $step, int $tolerance): ?int
    {
        $middle = $peaks[intdiv(count($peaks), 2)]['pos'];
        $best = null;
        $bestHits = -1;
        foreach ($peaks as $i => $peak) {
            if (abs($peak['pos'] - $middle) > $step * 4) {
                continue;
            }
            $hits = 0;
            foreach ($peaks as $other) {
                $k = (int) round(($other['pos'] - $peak['pos']) / $step);
                if (abs($other['pos'] - ($peak['pos'] + $k * $step)) <= $tolerance) {
                    $hits++;
                }
            }
            if ($hits > $bestHits) {
                $bestHits = $hits;
                $best = $i;
            }
        }

        return $best;
    }

    /**
     * @param list<array{pos: int, score: int, width: int}> $peaks
     * @param array<int, bool> $used
     */
    private static function peakNear(array $peaks, array $used, int $target, int $tolerance): ?int
    {
        $best = null;
        $bestRank = -1;
        $bestDistance = PHP_INT_MAX;
        foreach ($peaks as $i => $peak) {
            if (isset($used[$i])) {
                continue;
            }
            $distance = abs($peak['pos'] - $target);
            if ($distance > $tolerance) {
                continue;
            }
            $rank = $peak['score'] - $distance;
            if ($rank > $bestRank || ($rank === $bestRank && $distance < $bestDistance)) {
                $best = $i;
                $bestRank = $rank;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @param list<array{pos: int, score: int, width: int}> $peaks
     * @return list<array{pos: int, score: int, width: int}>
     */
    private static function mergeClose(array $peaks, int $gap): array
    {
        $merged = [];
        foreach ($peaks as $peak) {
            $last = array_key_last($merged);
            if ($last !== null && $peak['pos'] - $merged[$last]['pos'] < $gap) {
                if ($peak['score'] > $merged[$last]['score']) {
                    $merged[$last] = $peak;
                }
                continue;
            }
            $merged[] = $peak;
        }

        return $merged;
    }

    /**
     * @param list<int> $positions
     */
    private static function stepOf(array $positions): int
    {
        if (count($positions) < 2) {
            throw new Exception('Grid was not found');
        }
        $gaps = [];
        $count = count($positions);
        for ($i = 1; $i < $count; $i++) {
            $gaps[] = $positions[$i] - $positions[$i - 1];
        }
        sort($gaps);

        return (int) round($gaps[intdiv(count($gaps), 2)]);
    }

    /**
     * @return list<int>
     */
    private static function samplePositions(int $size, int $count, float $margin): array
    {
        $count = max(1, min($count, $size));
        $start = (int) floor($size * $margin);
        $end = (int) ceil($size * (1 - $margin)) - 1;
        if ($end <= $start) {
            $start = 0;
            $end = $size - 1;
        }
        if ($count === 1) {
            return [intdiv($start + $end, 2)];
        }

        $positions = [];
        for ($i = 0; $i < $count; $i++) {
            $positions[] = $start + (int) round($i * ($end - $start) / ($count - 1));
        }

        return array_values(array_unique($positions));
    }

    private static function luma(GdImage $image, int $x, int $y): float
    {
        $rgba = imagecolorat($image, $x, $y);
        $r = ($rgba >> 16) & 0xFF;
        $g = ($rgba >> 8) & 0xFF;
        $b = $rgba & 0xFF;

        return (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b) / 255 * 100;
    }
}
