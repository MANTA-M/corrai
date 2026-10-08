<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Clients\Google\Vision;
use Corrai\Model\OCRResult;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Store\ObjectStore;
use Throwable;

class SubmissionTask1Ocr extends PathQueueItemTask
{
    private const OCR_LANG = 'fr';

    /**
     * OCR service to use is Mindee.
     */
    private const OCR_SERVICE_MINDEE = 'mindee';
    /**
     * OCR service to use is Google Vision.
     */
    private const OCR_SERVICE_GOOGLE = 'google';
    /**
     * OCR service to use.
     */
    private const OCR_SERVICE = self::OCR_SERVICE_GOOGLE;

    public function __construct(private ?Vision $visionClient = null)
    {
    }

    protected function process(object $queue_item_data, string $s3_path): void
    {
        $file = $this->loadFile($s3_path);
        if (!$file instanceof Submission) {
            $file = $file->asClass(Submission::class);
        }
        try {
            $this->recognize($file);
        } catch (Throwable $th) {
            error_log(sprintf('[SubmissionTask1Ocr] OCR failed for file %s: %s', (string) $file->id, $th->getMessage()));
            try {
                $file->appendEvent('OCR failed');
                $file->status = 'error';
                $file->saveAttributes();
            } catch (Throwable $ignore) {
            }
        }
    }

    public function recognize(Submission $file): void
    {
        if ($file->size > Vision::MAX_FILE_SIZE) {
            try {
                $file->appendEvent('Image file too heavy');
            } catch (Throwable $e) {
                error_log($e->getMessage());
            }
            throw new \Exception('Image file too heavy');
        }

        $copyPath = null;
        try {
            $file->appendEvent('OCR started');
            $store = ObjectStore::getInstance();
            $copyPath = $store->downloadToTemp($file->contentKey());

            if (self::OCR_SERVICE === self::OCR_SERVICE_MINDEE) {
                $client = $this->createMindeeClient();
                $client->set_file($copyPath, $file->name, $file);
                $client->set_language(self::OCR_LANG);
                $result = OCRResult::from_mindee($client->process());
            } else {
                $client = $this->createVisionClient();
                $client->set_file($copyPath, $file->name, $file);
                $client->set_language(self::OCR_LANG);
                $result = OCRResult::from_google($client->process());
            }  

            $store->putContents(
                $file->ocrResultKey(),
                $result->to_json(true),
                'application/json'
            );

            $file->appendEvent('OCR ended');
            $file->saveAttributes();

            try {
                RedisQueue::getInstance()->enqueueFile($file->id, SubmissionTask2RotateAndCrop::class);
            } catch (Throwable $e) {
                error_log(sprintf('[SubmissionTask1Ocr] Failed to enqueue SubmissionTask2RotateAndCrop for file %s: %s', (string) $file->id, $e->getMessage()));
                throw $e;
            }
        } finally {
            if ($copyPath !== null && is_file($copyPath)) {
                @unlink($copyPath);
            }
        }
    }

    protected function createVisionClient(): Vision
    {
        return $this->visionClient ?? new Vision();
    }
}

if (!class_exists('LawFrance\SubmissionTask1Ocr', false)) {
    class_alias(SubmissionTask1Ocr::class, 'LawFrance\SubmissionTask1Ocr');
}
