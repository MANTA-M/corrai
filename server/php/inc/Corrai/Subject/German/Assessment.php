<?php

namespace Corrai\Subject\German;

use Corrai\Model\BaseAssessment;

class Assessment extends BaseAssessment
{
    public string $subject = 'German';

    public const NAMES = [
        'en' => 'German',
        'fr' => 'Allemand',
        'ru' => 'Немецкий',
        'uk' => 'Німецька',
        'es' => 'Alemán',
        'pt' => 'Alemão',
        'ro' => 'Germană',
        'de' => 'Deutsch',
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
