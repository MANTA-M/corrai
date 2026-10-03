<?php

namespace Corrai\Subject\Dictation;

use Corrai\Model\File as BaseFile;
use Corrai\Queue\RedisQueue;

/**
 * Submission state machine.
 *
 * Statuses: stored|correction_asked → ocr_done → errors_found → annotations → corrected
 */
class File extends BaseFile
{
    private const OCR_LANG = 'fr';

    public const TRANSCRIPTION_INSTRUCTION =
        'Transcript only what is writen without correcting it. DO NOT ADD ANY LETTER OR SIGN. '
        . 'If something is badly written, put a mark to say it\'s unreadable. '
        . 'Value de quality of caligraphy from 0.0 to 1.0.';

    public function on_stored(): void
    {
        if ($this->type !== 'submission') {
            return;
        }

        $this->appendEvent('OCR queued');
        RedisQueue::getInstance()->enqueueOcr($this->contentKey(), self::OCR_LANG);
    }

    public function on_correction_asked(): void
    {
        $this->on_stored();
    }

    public function on_ocr_done(): void
    {
        (new CorrectingTask())->correct($this);
    }

    public function on_errors_found(): void
    {
        (new AnnotatingTask())->annotate($this);
    }

    public function on_annotations(): void
    {
        (new RenderingTask())->render($this);
    }

}
