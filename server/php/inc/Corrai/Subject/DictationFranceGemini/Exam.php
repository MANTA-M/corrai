<?php

namespace Corrai\Subject\DictationFranceGemini;

use Corrai\Model\BaseExam;

class Exam extends BaseExam
{
    public string $subject = 'Dictation';
    public ?string $country = 'fr';
    public ?string $level = 'Gemini';
}
