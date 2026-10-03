<?php

namespace Corrai\Subject\DictationFranceGemini;

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
        RedisQueue::getInstance()->enqueueOcr($this->contentKey(), 'pre-ocr', '', self::OCR_LANG);
    }

    public function on_correction_asked(): void
    {
        if ($this->type !== 'submission') {
            return;
        }

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

    public function straightenedKey(): string
    {
        return rtrim($this->prefix(), '/') . '/straightened.png';
    }

}
