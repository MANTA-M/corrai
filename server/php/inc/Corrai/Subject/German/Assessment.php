<?php

namespace Corrai\Subject\German;

use Corrai\Model\BaseAssessment;
use Corrai\Model\SubjectFile;

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

    public function subjectFileClass(): string
    {
        return SubjectFile::class;
    }

    public function submissionClass(): string
    {
        return Submission::class;
    }
}
