<?php

namespace Corrai\Subject\Law;

use Corrai\Model\BaseAssessment;

class Assessment extends BaseAssessment
{
    public string $subject = 'Law';

    public const NAMES = [
        'en' => 'Law',
        'fr' => 'Droit',
        'ru' => 'Право',
        'uk' => 'Право',
        'es' => 'Derecho',
        'pt' => 'Direito',
        'ro' => 'Drept',
        'de' => 'Recht',
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
