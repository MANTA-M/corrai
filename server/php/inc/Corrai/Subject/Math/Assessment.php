<?php

namespace Corrai\Subject\Math;

use Corrai\Model\BaseAssessment;

class Assessment extends BaseAssessment
{
    public string $subject = 'Math';

    public const NAMES = [
        'en' => 'Mathematics',
        'fr' => 'Math',
        'ru' => 'Математика',
        'uk' => 'Математика',
        'es' => 'Matemáticas',
        'pt' => 'Matemática',
        'ro' => 'Matematică',
        'de' => 'Mathematik',
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
