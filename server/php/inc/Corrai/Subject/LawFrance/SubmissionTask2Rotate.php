<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Model\OCRResult;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Task\TaskRotateAndCrop;
use Corrai\Utils\Store\ObjectStore;
use GdImage;
use Throwable;

class SubmissionTask2Rotate extends PathQueueItemTask
{
    protected function process(object $queue_item_data, string $s3_path): void
    {
        $file = $this->loadFile($s3_path);
        if (!$file instanceof Submission) {
            $file = $file->asClass(Submission::class);
        }
        try {
            $this->rotate($file, $s3_path);
        } catch (Throwable $th) {
            error_log(sprintf('[SubmissionTask2Rotate] Rotate failed for file %s: %s', (string) $file->id, $th->getMessage()));
        } finally {
            try {
                RedisQueue::getInstance()->enqueueFile($file->id, SubmissionTask3Crop::class);
            } catch (Throwable $e) {
                error_log(sprintf('[SubmissionTask2Rotate] Failed to enqueue SubmissionTask3Crop for file %s: %s', (string) $file->id, $e->getMessage()));
                throw $e;
            }
        }
    }

    public function rotate(Submission $file, string $s3_path): void
    {
        $store = ObjectStore::getInstance();
        $ocrKey = $file->ocrResultKey();
        if (!$store->exists($ocrKey)) {
            error_log(sprintf('[SubmissionTask2Rotate] OCR result is missing at %s for file %s (%s)', $ocrKey, (string) $file->id, $s3_path));
            return;
        }

        try {
            $ocrContents = $store->getContents($ocrKey);
            $result = OCRResult::from_json($ocrContents);
        } catch (Throwable $e) {
            error_log(sprintf('[SubmissionTask2Rotate] Failed to decode OCR result JSON for file %s (%s): %s', (string) $file->id, $s3_path, $e->getMessage()));
            return;
        }

        if ($result->words === []) {
            error_log(sprintf('[SubmissionTask2Rotate] OCR result has no words for file %s (%s), skipping rotate', (string) $file->id, $s3_path));
            return;
        }

        try {
            $bytes = $store->getContents($file->contentKey());
        } catch (Throwable $e) {
            error_log(sprintf('[SubmissionTask2Rotate] Failed to read image content for file %s (%s): %s', (string) $file->id, $s3_path, $e->getMessage()));
            return;
        }
        if ($bytes === '') {
            error_log(sprintf('[SubmissionTask2Rotate] Empty image content for file %s (%s)', (string) $file->id, $s3_path));
            return;
        }

        $type = TaskRotateAndCrop::outputType($bytes);
        try {
            [$image, $decodedByGd] = TaskRotateAndCrop::decode($bytes);
        } catch (Throwable $e) {
            error_log(sprintf('[SubmissionTask2Rotate] Failed to decode image for file %s (%s): %s', (string) $file->id, $s3_path, $e->getMessage()));
            return;
        }

        try {
            $result->detectRotation();
            $angle = $result->rotation;
            if ($angle !== 0) {
                $image = TaskRotateAndCrop::rotate($image, $angle, $bytes, $decodedByGd);
                $result->rotate_upright();
                TaskRotateAndCrop::storeImage($file, $image, $type);
                $store->putContents($ocrKey, $result->to_json(true), 'application/json');
                try {
                    $file->appendEvent(TaskRotateAndCrop::rotationEvent($angle));
                } catch (Throwable $e) {
                    error_log(sprintf('[SubmissionTask2Rotate] Failed to append rotation event for file %s (%s): %s', (string) $file->id, $s3_path, $e->getMessage()));
                }
            }
        } finally {
            if ($image instanceof GdImage) {
                imagedestroy($image);
            }
        }
    }
}

if (!class_exists('LawFrance\SubmissionTask2Rotate', false)) {
    class_alias(SubmissionTask2Rotate::class, 'LawFrance\SubmissionTask2Rotate');
}
