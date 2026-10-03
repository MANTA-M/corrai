<?php

namespace Corrai\Subject\DictationFranceCM1;

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

    public function on_stored(): void
    {
        if ($this->type !== 'submission') {
            return;
        }

        $this->appendEvent('OCR queued');
        RedisQueue::getInstance()->enqueueOcr($this->contentKey(), 'pre-ocr', self::OCR_LANG);
    }

    public function on_correction_asked(): void
    {
        if ($this->type !== 'submission') {
            return;
        }

        $this->appendEvent('OCR queued');
        RedisQueue::getInstance()->enqueueOcr($this->contentKey(), 'ocr', self::OCR_LANG);
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
