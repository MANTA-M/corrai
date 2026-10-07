<?php

namespace Corrai\Subject\Spanish;

use Corrai\Model\BaseAssessment;
use Corrai\Model\SubjectFile;

class Assessment extends BaseAssessment
{
    public string $subject = 'Spanish';

    public const NAMES = [
        'en' => 'Spanish',
        'fr' => 'Espagnol',
        'ru' => 'Испанский',
        'uk' => 'Іспанська',
        'es' => 'Español',
        'pt' => 'Espanhol',
        'ro' => 'Spaniolă',
        'de' => 'Spanisch',
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
