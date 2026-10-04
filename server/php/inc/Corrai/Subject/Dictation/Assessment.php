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
        return File::class;
    }

    public function submissionClass(): string
    {
        return File::class;
    }

    public function startCorrection(): array
    {
        return $this->correctFirstCopy();
    }

    public function testCorrection(): array
    {
        return $this->correctFirstCopy();
    }
}
