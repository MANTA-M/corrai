<?php

namespace Corrai\Utils\Image;

use Imagick;
use ImagickException;
use InvalidArgumentException;

/**
 * Color corrections applied to encoded image bytes.
 *
 * The source bytes stay unchanged. Each correction returns a new ColorFilter
 * that holds the corrected image.
 */
class ColorFilter
{
    public readonly string $image;

    /**
     * @param string $bytes Encoded image bytes.
     */
    public function __construct(string $bytes)
    {
        if ($bytes === '') {
            throw new InvalidArgumentException('Image bytes must not be empty');
        }

        try {
            $imagick = new Imagick();
            $imagick->readImageBlob($bytes);
        } catch (ImagickException $exception) {
            throw new InvalidArgumentException('Invalid image data', 0, $exception);
        }

        $this->image = $bytes;
        $imagick->clear();
    }

    /**
     * Stretch each color channel so the darkest ink and the lightest paper
     * span the full range.
     */
    public function normalizeColors(): self
    {
        $imagick = new Imagick();
        $imagick->readImageBlob($this->image);
        $imagick->normalizeImage();

        $result = $imagick->getImageBlob();
        $imagick->clear();

        return new self($result);
    }

    /**
     * Flatten uneven paper illumination by dividing the scan by a strong blur.
     */
    public function backgroundDivision(): self
    {
        $imagick = new Imagick();
        $imagick->readImageBlob($this->image);

        // 1. Dupliquer l'image pour créer un masque du papier
        $background = clone $imagick;

        // 2. Flouter fortement pour ne garder que la luminosité du fond (sans le texte)
        $background->blurImage(0, 100);

        // 3. Diviser l'image originale par le fond flouté
        $imagick->compositeImage($background, Imagick::COMPOSITE_DIVIDE, 0, 0);

        // 4. Ajuster légèrement le point de blanc pour garantir un fond 100 % blanc
        $quantum = $imagick->getQuantumRange()['quantumRangeLong'];
        $imagick->levelImage(0 * $quantum, 1.0, 0.90 * $quantum);

        $result = $imagick->getImageBlob();
        $background->clear();
        $imagick->clear();

        return new self($result);
    }

    /**
     * Turn every pixel lighter than $luminosity into white.
     *
     * Luminosity is Rec. 601 luma, from 0 (black) to 100 (white). Pixels at
     * the threshold stay unchanged.
     */
    public function whiteThresholdImage(float $luminosity = 80.0): self
    {
        if ($luminosity < 0 || $luminosity > 100) {
            throw new InvalidArgumentException("Luminosity must be between 0 and 100, got {$luminosity}");
        }

        $imagick = new Imagick();
        $imagick->readImageBlob($this->image);
        $limit = $luminosity / 100;

        $iterator = $imagick->getPixelIterator();
        foreach ($iterator as $row) {
            foreach ($row as $pixel) {
                $color = $pixel->getColor(true);
                $luma = 0.299 * $color['r'] + 0.587 * $color['g'] + 0.114 * $color['b'];
                if ($luma <= $limit) {
                    continue;
                }
                $pixel->setColorValue(Imagick::COLOR_RED, 1.0);
                $pixel->setColorValue(Imagick::COLOR_GREEN, 1.0);
                $pixel->setColorValue(Imagick::COLOR_BLUE, 1.0);
            }
            $iterator->syncIterator();
        }

        $result = $imagick->getImageBlob();
        $imagick->clear();

        return new self($result);
    }
}
