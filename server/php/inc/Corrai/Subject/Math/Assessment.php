<?php

namespace Corrai\Subject\Math;

use Corrai\Model\BaseAssessment;
use Corrai\Model\SubjectFile;

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

    public function subjectFileClass(): string
    {
        return SubjectFile::class;
    }

    public function submissionClass(): string
    {
        return Submission::class;
    }
}
