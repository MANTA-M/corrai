<?php

namespace Corrai\Subject\Russian;

use Corrai\Model\SubmissionFile;

/**
 * Submission state machine.
 *
 * Statuses: correction_asked → transcribed → correction_ready → corrected
 */
class Submission extends SubmissionFile
{
    public function on_stored(): void
    {
    }

    public function on_correction_asked(): void
    {
        if ($this->type !== 'submission') {
            return;
        }
        (new Task1Transcribing())->transcribeSubmission($this);
    }

    public function on_transcribed(): void
    {
        if ($this->type !== 'submission') {
            return;
        }
        (new Task2Correcting())->correctSubmission($this);
    }

    public function on_correction_ready(): void
    {
        if ($this->type !== 'submission') {
            return;
        }
        (new Task3Annotating())->annotateSubmission($this);
    }
}
