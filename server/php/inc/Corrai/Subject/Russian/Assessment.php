<?php

namespace Corrai\Subject\Russian;

use Corrai\Model\BaseAssessment;
use Corrai\Model\SubjectFile;

class Assessment extends BaseAssessment
{
    public string $subject = 'Russian';

    public const NAMES = [
        'en' => 'Russian',
        'fr' => 'Russe',
        'ru' => 'Русский',
        'uk' => 'Російська',
        'es' => 'Ruso',
        'pt' => 'Russo',
        'ro' => 'Rusă',
        'de' => 'Russisch',
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
