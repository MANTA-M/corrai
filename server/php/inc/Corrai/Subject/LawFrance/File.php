<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Model\InputFile;
use Corrai\Model\SubmissionFile;
use Corrai\Queue\RedisQueue;
use Corrai\Task\Thumbnail;

/**
 * Submission state machine.
 *
 * Statuses: correction_asked → transcribed → correction_ready → corrected
 */
class File extends Submission
{
}

