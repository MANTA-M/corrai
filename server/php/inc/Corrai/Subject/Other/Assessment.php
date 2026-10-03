<?php

namespace Corrai\Subject\Other;

use Corrai\Model\BaseAssessment;

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

    public function fileClass(): string
    {
        return File::class;
    }

    public function submissionClass(): string
    {
        return File::class;
    }
}
