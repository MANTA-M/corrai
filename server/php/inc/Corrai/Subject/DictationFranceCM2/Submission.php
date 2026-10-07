<?php

namespace Corrai\Subject\DictationFranceCM2;

use Corrai\Model\BaseAssessment;
use Corrai\Model\SubmissionFile;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\AssessmentFactory;
use Corrai\Subject\Dictation\StatusLabels;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\WSException;

/**
 * Dictation France CM2 submission state machine.
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

    public function on_stored(): void
    {
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
}
