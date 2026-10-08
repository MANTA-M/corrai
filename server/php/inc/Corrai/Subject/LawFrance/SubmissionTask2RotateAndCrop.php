<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Model\InputFile;
use Corrai\Queue\RedisQueue;
use Corrai\Task\TaskRotateAndCrop;
use Corrai\Task\Thumbnail;
use Throwable;

/**
 * Rotate and crop a copy, then mark OCR done and queue identification.
 *
 * Rotation and cropping are TaskRotateAndCrop. This class only advances the copy.
 */
class SubmissionTask2RotateAndCrop extends TaskRotateAndCrop
{
    public function complete(InputFile $file): void
    {
        $file->status = 'transcribed';
        try {
            $file->saveAttributes();
        } catch (Throwable $e) {
            error_log(sprintf('[SubmissionTask2RotateAndCrop] Failed to save file attributes for %s: %s', (string) $file->id, $e->getMessage()));
        }

        if ($file->id === null || $file->id === '') {
            return;
        }

        try {
            if (Submission::isRasterImage($file)) {
                RedisQueue::getInstance()->enqueueFile($file->id, Thumbnail::class);
            }
        } catch (Throwable $e) {
            error_log(sprintf('[SubmissionTask2RotateAndCrop] Failed to enqueue Thumbnail for file %s: %s', (string) $file->id, $e->getMessage()));
        }
        try {
            RedisQueue::getInstance()->enqueueFile($file->id, SubmissionTask3Identify::class);
        } catch (Throwable $e) {
            error_log(sprintf('[SubmissionTask2RotateAndCrop] Failed to enqueue SubmissionTask3Identify for file %s: %s', (string) $file->id, $e->getMessage()));
        }
    }
}

if (!class_exists('LawFrance\SubmissionTask2RotateAndCrop', false)) {
    class_alias(SubmissionTask2RotateAndCrop::class, 'LawFrance\SubmissionTask2RotateAndCrop');
}

if (!class_exists(SubmissionTask2Rotate::class, false)) {
    class_alias(SubmissionTask2RotateAndCrop::class, SubmissionTask2Rotate::class);
}

if (!class_exists('LawFrance\SubmissionTask2Rotate', false)) {
    class_alias(SubmissionTask2RotateAndCrop::class, 'LawFrance\SubmissionTask2Rotate');
}
