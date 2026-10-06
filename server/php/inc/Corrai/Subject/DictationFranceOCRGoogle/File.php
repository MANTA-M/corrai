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
class File extends SubmissionFile
{
    protected static function statusLabelTable(): array
    {
        return array_merge(parent::statusLabelTable(), StatusLabels::table());
    }

    public function on_stored(): void
    {
        if ($this->type !== 'submission') {
            return;
        }

        $this->appendEvent('Pre-OCR queued');
        RedisQueue::getInstance()->enqueueFile($this->id, GoogleOcr::class);
    }

    public function on_correction_asked(): void
    {
        if ($this->type !== 'submission') {
            return;
        }

        // OCR is already stored, or Task1Correcting runs it when the result is missing.
        $this->appendEvent('Correction queued');
        RedisQueue::getInstance()->enqueueFile($this->id, Task1Correcting::class);
    }

    public function on_ocr_done(): void
    {
        (new Task1Correcting())->correct($this);
    }

    public function on_errors_found(): void
    {
        (new Task2Annotating())->annotate($this);
    }

    public function on_annotations(): void
    {
        (new Task3Rendering())->render($this);
    }
}
