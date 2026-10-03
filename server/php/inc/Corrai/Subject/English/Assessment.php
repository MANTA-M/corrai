<?php

namespace Corrai\Subject\English;

use Corrai\Model\BaseAssessment;

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

    public function fileClass(): string
    {
        return File::class;
    }

    public function submissionClass(): string
    {
        return File::class;
    }
}
