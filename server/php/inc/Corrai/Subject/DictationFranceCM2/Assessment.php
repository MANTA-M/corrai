<?php

namespace Corrai\Subject\DictationFranceCM2;

use Corrai\Model\BaseAssessment;

class Assessment extends BaseAssessment
{
    public string $subject = 'Dictation';
    public ?string $country = 'fr';
    public ?string $level = 'CM2';

    public function fileClass(): string
    {
        return File::class;
    }
}
