<?php

namespace Corrai\Subject\DictationFranceCM1;

use Corrai\Model\BaseExam;

class Exam extends BaseExam
{
    public string $subject = 'Dictation';
    public ?string $country = 'fr';
    public ?string $level = 'CM1';
}
