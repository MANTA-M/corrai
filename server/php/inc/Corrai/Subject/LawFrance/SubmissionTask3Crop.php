<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Model\BaseAssessment;
use Corrai\Model\SubmissionFile;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Throwable;

class SubmissionTask3Crop extends PathQueueItemTask
{
    public const CROP_EVENT = 'Cropped to the text';

    protected function process(object $queue_item_data, string $s3_path): void
    {
        $file = $this->loadFile($s3_path);
        if (!$file instanceof SubmissionFile) {
            $file = $file->asClass(Submission::class);
        }
        $this->cropSubmission($file);
    }

    public function cropSubmission(SubmissionFile $file, ?BaseAssessment $assessment = null): void
    {
        try {
            $file->saveAttributes();
        } catch (Throwable $e) {
            error_log(sprintf('[SubmissionTask3Crop] Failed to save file attributes for %s: %s', (string) $file->id, $e->getMessage()));
        }
        try {
            $file->appendEvent(self::CROP_EVENT);
        } catch (Throwable $e) {
            // Ignore when event storage is unavailable
        }

        if ($file->id !== null && $file->id !== '') {
            try {
                RedisQueue::getInstance()->enqueueFile($file->id, SubmissionTask3Identify::class);
            } catch (Throwable $e) {
                error_log(sprintf('[SubmissionTask3Crop] Failed to enqueue SubmissionTask3Identify for file %s: %s', (string) $file->id, $e->getMessage()));
                throw $e;
            }
        }
    }
}

if (!class_exists('LawFrance\\SubmissionTask3Crop', false)) {
    class_alias(SubmissionTask3Crop::class, 'LawFrance\\SubmissionTask3Crop');
}
