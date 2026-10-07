<?php

namespace Corrai\Subject\Dictation;

use Corrai\Model\BaseAssessment;

class Assessment extends BaseAssessment
{
    public string $subject = 'Dictation';

    public const NAMES = [
        'en' => 'Dictation',
        'fr' => 'Français (dictée)',
        'ru' => 'Диктант',
        'uk' => 'Диктант',
        'es' => 'Dictado',
        'pt' => 'Ditado',
        'ro' => 'Dictare',
        'de' => 'Diktat',
    ];

    public function fileClass(): string
    {
        return Submission::class;
    }

    public function submissionClass(): string
    {
        return Submission::class;
    }
}
