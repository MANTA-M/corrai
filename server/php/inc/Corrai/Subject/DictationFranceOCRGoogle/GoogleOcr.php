<?php

namespace Corrai\Subject\DictationFranceOCRGoogle;

use Corrai\Clients\Google\Vision;
use Corrai\Model\OCRResult;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Task\TaskRotateAndCrop;
use Corrai\Utils\Store\ObjectStore;
use Throwable;

/**
 * Google OCR through direct Google Vision. Writes ocr_result.json.
 *
 * A pre-OCR leaves the file at ocr_done. Task1Correcting is enqueued only when
 * this run was asked for correction (file status correction_asked). The
 * correction button enqueues Task1Correcting directly so an existing OCR result
 * is reused.
 */
class GoogleOcr extends PathQueueItemTask
{
    private const OCR_LANG = 'fr';

    protected function process(object $queue_item_data, string $s3_path): void
    {
        $file = $this->loadFile($s3_path);
        if (!$file instanceof Submission) {
            $file = $file->asClass(Submission::class);
        }
        try {
            $this->recognize($file, $file->status === 'correction_asked');
        } catch (Throwable $th) {
            error_log($th->getMessage());
            try {
                $file->appendEvent('OCR failed');
                $file->status = 'error';
                $file->saveAttributes();
            } catch (Throwable $ignore) {
            }
        }
    }

    public function recognize(Submission $file, bool $enqueueCorrecting = false): void
    {
        if ($file->type !== 'submission') {
            return;
        }

        if ($file->size > Vision::MAX_FILE_SIZE) {
            try {
                $file->appendEvent('Image file too heavy');
            } catch (\Throwable $e) {
                error_log($e->getMessage());
            }
            throw new \Exception('Image file too heavy');
        }

        $copyPath = null;
        try {
            $file->appendEvent('OCR started');
            $store = ObjectStore::getInstance();
            $copyPath = $store->downloadToTemp($file->contentKey());

            $client = $this->createVisionClient();
            $client->set_file($copyPath, $file->name, $file);
            $client->set_language(self::OCR_LANG);
            $result = OCRResult::from_google($client->process());

            $store->putContents(
                $file->ocrResultKey(),
                $result->to_json(true),
                'application/json'
            );

            $file->status = 'ocr_done';
            $file->saveAttributes();
            $file->appendEvent('OCR ended');

            try {
                RedisQueue::getInstance()->enqueueFile($file->id, TaskRotateAndCrop::class);
            } catch (Throwable $e) {
                error_log(sprintf('[GoogleOcr] Failed to enqueue TaskRotateAndCrop for file %s: %s', (string) $file->id, $e->getMessage()));
                throw $e;
            }

            if ($enqueueCorrecting) {
                try {
                    RedisQueue::getInstance()->enqueueFile($file->id, Task1Correcting::class);
                } catch (Throwable $e) {
                    error_log(sprintf('[GoogleOcr] Failed to enqueue Task1Correcting for file %s: %s', (string) $file->id, $e->getMessage()));
                    throw $e;
                }
            }
        } finally {
            if ($copyPath !== null) {
                @unlink($copyPath);
            }
        }
    }

    protected function createVisionClient(): Vision
    {
        return new Vision();
    }

    protected function createGoogleOCRClient(): Vision
    {
        return $this->createVisionClient();
    }
}
