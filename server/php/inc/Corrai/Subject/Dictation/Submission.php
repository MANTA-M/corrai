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
class Submission extends SubmissionFile
{
    private const OCR_LANG = 'fr';

    protected static function statusLabelTable(): array
    {
        return array_merge(parent::statusLabelTable(), StatusLabels::table());
    }

    public const TRANSCRIPTION_INSTRUCTION =
        'Transcript only what is writen without correcting it. DO NOT ADD ANY LETTER OR SIGN. '
        . 'If something is badly written, put a mark to say it\'s unreadable. '
        . 'Value de quality of caligraphy from 0.0 to 1.0.';

    public function on_stored(): void
    {
        $this->appendEvent('OCR queued');
        RedisQueue::getInstance()->enqueueOcr($this->contentKey(), 'pre-ocr', '', self::OCR_LANG);
    }

    public function on_correction_asked(): void
    {
        $this->appendEvent('OCR queued');
        RedisQueue::getInstance()->enqueueOcr($this->contentKey(), 'ocr', Task1Correcting::class, self::OCR_LANG);
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
