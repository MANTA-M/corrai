<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Model\BaseAssessment;

class Assessment extends BaseAssessment
{
    public string $subject = 'Law';
    public ?string $country = 'fr';

    public const NAMES = [
        'en' => 'Law France',
        'fr' => 'Droit France',
        'ru' => 'Право Франция',
        'uk' => 'Право Франція',
        'es' => 'Derecho Francia',
        'pt' => 'Direito Portugal',
        'ro' => 'Drept România',
        'de' => 'Recht Deutschland',
    ];

    public function fileClass(): string
    {
        return Submission::class;
    }

    public function submissionClass(): string
    {
        return Submission::class;
    }
}
