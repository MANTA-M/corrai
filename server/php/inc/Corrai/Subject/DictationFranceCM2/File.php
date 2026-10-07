<?php

namespace Corrai\Subject\DictationFranceCM2;

use Corrai\Model\BaseAssessment;
use Corrai\Model\SubmissionFile;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\AssessmentFactory;
use Corrai\Subject\Dictation\StatusLabels;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\WSException;

/**
 * Dictation France CM2 submission state machine.
 *
 * Statuses: stored|correction_asked → ocr_done → errors_found → annotations → corrected
     */
class File extends Submission
{
}

