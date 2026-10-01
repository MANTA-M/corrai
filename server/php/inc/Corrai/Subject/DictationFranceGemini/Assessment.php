<?php

namespace Corrai\Subject\DictationFranceGemini;

use Corrai\Model\BaseAssessment;

class Assessment extends BaseAssessment
{
    public string $subject = 'Dictation';
    public ?string $country = 'fr';
    public ?string $level = 'Gemini';
}
