<?php

namespace Corrai\Subject\English;

use Corrai\Model\BaseAssessment;
use Corrai\Model\SubjectFile;

class Assessment extends BaseAssessment
{
    public string $subject = 'English';

    public const NAMES = [
        'en' => 'English',
        'fr' => 'Anglais',
        'ru' => 'Английский',
        'uk' => 'Англійська',
        'es' => 'Inglés',
        'pt' => 'Inglês',
        'ro' => 'Engleză',
        'de' => 'Englisch',
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
