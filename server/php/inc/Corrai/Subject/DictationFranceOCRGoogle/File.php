<?php

namespace Corrai\Subject\DictationFranceOCRGoogle;

use Corrai\Model\SubmissionFile;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\Dictation\StatusLabels;

/**
 * Dictation France OCR Google submission state machine.
 *
 * Statuses: stored|correction_asked → ocr_done → errors_found → annotations → corrected
 */
class File extends Submission
{
}

