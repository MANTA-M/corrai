<?php

namespace Corrai\Subject\Other;

use Corrai\Model\BaseAssessment;
use Corrai\Model\SubjectFile;

class Assessment extends BaseAssessment
{
    public string $subject = 'Other';

    public const NAMES = [
        'en' => 'Other',
        'fr' => 'Autre',
        'ru' => 'Другое',
        'uk' => 'Інше',
        'es' => 'Otro',
        'pt' => 'Outro',
        'ro' => 'Altul',
        'de' => 'Sonstiges',
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
