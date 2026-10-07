<?php

namespace Corrai\Subject\Law;

use Corrai\Model\BaseAssessment;
use Corrai\Model\SubjectFile;

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

    public function subjectFileClass(): string
    {
        return SubjectFile::class;
    }

    public function submissionClass(): string
    {
        return Submission::class;
    }
}
