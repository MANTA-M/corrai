<?php

namespace Corrai\Subject\DictationFranceCM1;

use Corrai\Model\BaseAssessment;

class Assessment extends BaseAssessment
{
    public string $subject = 'Dictation';
    public ?string $country = 'fr';
    public ?string $level = 'CM1';

    public const NAMES = [
        'en' => 'Dictation CM1 France',
        'fr' => 'Dictée CM1 France',
        'ru' => 'Диктант CM1 Франция',
        'uk' => 'Диктант CM1 Франція',
        'es' => 'Dictado CM1 Francia',
        'pt' => 'Ditado CM1 Portugal',
        'ro' => 'Dictare CM1 Franța',
        'de' => 'Diktat CM1 Frankreich',
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
