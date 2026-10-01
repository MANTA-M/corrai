<?php

namespace Corrai\Subject\DictationFranceCM2;

use Corrai\Model\BaseExam;

class Exam extends BaseExam
{
    public string $subject = 'Dictation';
    public ?string $country = 'fr';
    public ?string $level = 'CM2';

    public function fileClass(): string
    {
        return File::class;
    }
}
