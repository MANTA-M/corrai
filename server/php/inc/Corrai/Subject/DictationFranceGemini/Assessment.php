<?php

namespace Corrai\Subject\DictationFranceGemini;

use Corrai\Model\BaseAssessment;

class Assessment extends BaseAssessment
{
    public string $subject = 'Dictation';
    public ?string $country = 'fr';
    public ?string $level = 'Gemini';

    public const NAMES = [
        'en' => 'Dictation Gemini France',
        'fr' => 'Dictée Gemini France',
        'ru' => 'Диктант Gemini Франция',
        'uk' => 'Диктант Gemini Франція',
        'es' => 'Dictado Gemini Francia',
        'pt' => 'Ditado Gemini Portugal',
        'ro' => 'Dictare Gemini Franța',
        'de' => 'Diktat Gemini Frankreich',
    ];

    public function fileClass(): string
    {
        return File::class;
    }

    public function submissionClass(): string
    {
        return File::class;
    }
}
