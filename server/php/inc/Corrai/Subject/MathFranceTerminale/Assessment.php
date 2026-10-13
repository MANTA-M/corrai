<?php

namespace Corrai\Subject\MathFranceTerminale;

use Corrai\Subject\Math\Assessment as MathAssessment;

class Assessment extends MathAssessment
{
    public ?string $country = 'fr';

    public ?string $level = 'Terminale';
}
