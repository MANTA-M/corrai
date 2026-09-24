<?php

$GD_directives = <<<GD

imagettftext(
    GdImage $image,
    float $size,
    float $angle,
    int $x,
    int $y,
    int $color,
    string $font_filename,
    string $text,
    array $options = []
): array|false

imagefttext(
    GdImage $image,
    float $size,
    float $angle,
    int $x,
    int $y,
    int $color,
    string $font_filename,
    string $text,
    array $options = []
): array|false

imagestring(
    GdImage $image,
    GdFont|int $font,
    int $x,
    int $y,
    string $string,
    int $color
): bool

imagestringup(
    GdImage $image,
    GdFont|int $font,
    int $x,
    int $y,
    string $string,
    int $color
): bool

imagechar(
    GdImage $image,
    GdFont|int $font,
    int $x,
    int $y,
    string $char,
    int $color
): bool

imagecharup(
    GdImage $image,
    GdFont|int $font,
    int $x,
    int $y,
    string $char,
    int $color
): bool

// Text Dimensions & Bounding Boxes
imagettfbbox(
    float $size,
    float $angle,
    string $font_filename,
    string $string,
    array $options = []
): array|false

imageftbbox(
    float $size,
    float $angle,
    string $font_filename,
    string $string,
    array $options = []
): array|false

imagefontheight(GdFont|int $font): int

imagefontwidth(GdFont|int $font): int

// Shapes & Lines
imageline(
    GdImage $image,
    int $x1,
    int $y1,
    int $x2,
    int $y2,
    int $color
): bool

imagerectangle(
    GdImage $image,
    int $x1,
    int $y1,
    int $x2,
    int $y2,
    int $color
): bool

imagefilledrectangle(
    GdImage $image,
    int $x1,
    int $y1,
    int $x2,
    int $y2,
    int $color
): bool

imageellipse(
    GdImage $image,
    int $center_x,
    int $center_y,
    int $width,
    int $height,
    int $color
): bool

imagefilledellipse(
    GdImage $image,
    int $center_x,
    int $center_y,
    int $width,
    int $height,
    int $color
): bool

imagepolygon(
    GdImage $image,
    array $points,
    int $color
): bool

imagefilledpolygon(
    GdImage $image,
    array $points,
    int $color
): bool

GD;

