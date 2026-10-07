<?php

namespace Corrai\Subject;

use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Utils\Store\ObjectStore;
use Throwable;

/**
 * Google OCR of one subject page image taken from the file queue.
 */
class SubjectPageOcr extends PathQueueItemTask
{
    public function __construct(private ?SubjectImageOcr $ocr = null)
    {
    }

    protected function process(object $queue_item_data, string $s3_path): void
    {
        try {
            $file = $this->loadFile($s3_path);
        } catch (Throwable $exception) {
            error_log(sprintf('[SubjectPageOcr] Failed to load file from path %s: %s', $s3_path, $exception->getMessage()));
            return;
        }
        if ($file->type !== 'subject') {
            return;
        }
        if (in_array($file->status, [SubjectPages::STATUS_REFINE, SubjectPages::STATUS_DONE], true)) {
            return;
        }

        $copy = null;
        try {
            $copy = ObjectStore::getInstance()->downloadToTemp($file->contentKey());
            SubjectPages::transcribe(
                $file,
                $this->ocr ?? new GoogleSubjectImageOcr(),
                $copy,
                $file->name
            );
        } catch (Throwable $exception) {
            error_log(sprintf(
                '[SubjectPageOcr] OCR failed for subject file %s: %s',
                (string) $file->id,
                $exception->getMessage()
            ));
        } finally {
            if ($copy !== null && is_file($copy)) {
                @unlink($copy);
            }
        }
    }
}
