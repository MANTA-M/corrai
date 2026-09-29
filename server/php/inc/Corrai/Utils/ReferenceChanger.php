<?php

namespace Corrai\Utils;

use InvalidArgumentException;

/**
 * Maps coordinates from a source reference frame to a destination frame.
 *
 * Two corresponding points define an axis-aligned scale and a translation.
 * Those transforms are built with imageaffinematrixget and concatenated
 * so a single matrix applies both.
 */
class ReferenceChanger
{
    /** @var array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} */
    private readonly array $matrix;

    /**
     * @param array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}} $sourceMarkings
     *        Coordinates of the two markings in the source reference frame.
     * @param array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}} $destinationMarkings
     *        Coordinates of the same two markings in the destination reference frame.
     */
    public function __construct(array $sourceMarkings, array $destinationMarkings)
    {
        [$aSource, $bSource] = self::markings($sourceMarkings, 'source');
        [$aDestination, $bDestination] = self::markings($destinationMarkings, 'destination');

        $deltaX = $bSource[0] - $aSource[0];
        $deltaY = $bSource[1] - $aSource[1];
        if ($deltaX === 0 || $deltaY === 0) {
            throw new InvalidArgumentException('Source points A and B must have distinct X and Y coordinates');
        }

        $scaleX = ($bDestination[0] - $aDestination[0]) / $deltaX;
        $scaleY = ($bDestination[1] - $aDestination[1]) / $deltaY;
        $translateX = $aDestination[0] - $scaleX * $aSource[0];
        $translateY = $aDestination[1] - $scaleY * $aSource[1];

        $scale = imageaffinematrixget(IMG_AFFINE_SCALE, ['x' => $scaleX, 'y' => $scaleY]);
        $translate = imageaffinematrixget(IMG_AFFINE_TRANSLATE, ['x' => $translateX, 'y' => $translateY]);
        if ($scale === false || $translate === false) {
            throw new InvalidArgumentException('GD could not build the scale or translate matrix');
        }

        $matrix = imageaffinematrixconcat($scale, $translate);
        if ($matrix === false) {
            throw new InvalidArgumentException('GD could not concatenate the scale and translate matrices');
        }

        $this->matrix = $matrix;
    }

    /**
     * Map a source coordinate into the destination reference frame.
     *
     * @return array{0: int, 1: int}
     */
    public function transform(int $x, int $y): array
    {
        $matrix = $this->matrix;
        $destinationX = $x * $matrix[0] + $y * $matrix[2] + $matrix[4];
        $destinationY = $x * $matrix[1] + $y * $matrix[3] + $matrix[5];

        return [(int) round($destinationX), (int) round($destinationY)];
    }

    /**
     * @param array<mixed> $markings
     * @return array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}
     */
    private static function markings(array $markings, string $frame): array
    {
        if (count($markings) !== 2 || !array_is_list($markings)) {
            throw new InvalidArgumentException("{$frame} markings must be an array of 2 coordinates");
        }

        return [
            self::coordinate($markings[0], "first {$frame} marking"),
            self::coordinate($markings[1], "second {$frame} marking"),
        ];
    }

    /**
     * @param mixed $coordinate
     * @return array{0: int, 1: int}
     */
    private static function coordinate(mixed $coordinate, string $name): array
    {
        if (!is_array($coordinate) || count($coordinate) !== 2 || !array_is_list($coordinate)) {
            throw new InvalidArgumentException("{$name} must be an array of 2 integers");
        }
        if (!is_int($coordinate[0]) || !is_int($coordinate[1])) {
            throw new InvalidArgumentException("{$name} must be an array of 2 integers");
        }

        return [$coordinate[0], $coordinate[1]];
    }
}
