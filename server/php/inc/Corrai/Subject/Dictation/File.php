<?php

namespace Corrai\Subject\Dictation;

use Corrai\Model\SubmissionFile;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\Dictation\StatusLabels;

/**
 * Submission state machine.
 *
 * Statuses: stored|correction_asked → ocr_done → errors_found → annotations → corrected
 */
class File extends Submission
{
}

