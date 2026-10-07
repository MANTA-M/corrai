<?php

namespace Corrai\Subject\English;

use Corrai\Model\SubmissionFile;

/**
 * Submission state machine.
 *
 * Statuses: correction_asked → transcribed → correction_ready → corrected
 */
class File extends Submission
{
}

