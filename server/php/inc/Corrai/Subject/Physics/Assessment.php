<?php

namespace Corrai\Subject\Physics;

use Corrai\Model\BaseAssessment;

class Assessment extends BaseAssessment
{
    public string $subject = 'Physics';

    public const NAMES = [
        'en' => 'Physics',
        'fr' => 'Physique',
        'ru' => 'Физика',
        'uk' => 'Фізика',
        'es' => 'Física',
        'pt' => 'Física',
        'ro' => 'Fizică',
        'de' => 'Physik',
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
