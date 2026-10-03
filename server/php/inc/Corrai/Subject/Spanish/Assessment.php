<?php

namespace Corrai\Subject\Spanish;

use Corrai\Model\BaseAssessment;

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

    public function fileClass(): string
    {
        return File::class;
    }

    public function submissionClass(): string
    {
        return File::class;
    }
}
