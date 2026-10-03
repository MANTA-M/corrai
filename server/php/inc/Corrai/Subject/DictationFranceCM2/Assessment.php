<?php

namespace Corrai\Subject\DictationFranceCM2;

use Corrai\Model\BaseAssessment;
use Corrai\Model\Student;

class Assessment extends BaseAssessment
{
    public string $subject = 'Dictation';
    public ?string $country = 'fr';
    public ?string $level = 'CM2';

    public const NAMES = [
        'en' => 'Dictation CM2 France',
        'fr' => 'Dictée CM2 France',
        'ru' => 'Диктант CM2 Франция',
        'uk' => 'Диктант CM2 Франція',
        'es' => 'Dictado CM2 Francia',
        'pt' => 'Ditado CM2 Portugal',
        'ro' => 'Dictare CM2 Franța',
        'de' => 'Diktat CM2 Frankreich',
    ];

    public function assessmentItemClass(): string
    {
        return self::class;
    }

    public function studentClass(): string
    {
        return Student::class;
    }

    public function fileClass(): string
    {
        return File::class;
    }

    public function submissionClass(): string
    {
        return File::class;
    }
}
