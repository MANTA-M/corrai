<?php

namespace Corrai\Subject\DictationFranceOCRGoogle;

use Corrai\Llm\Eden\GoogleOCRClient;
use Corrai\Model\OCRResult;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\ObjectStore;
use Throwable;

/**
 * Google OCR through Eden AI. Writes ocr_result.json then, after a correction
 * request, enqueues Task1Correcting.
 */
class GoogleOcr extends PathQueueItemTask
{
    private const OCR_LANG = 'fr';

    protected function process(object $queue_item_data, string $s3_path): void
    {
        $file = $this->loadFile($s3_path);
        if (!$file instanceof File) {
            $file = $file->asClass(File::class);
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

    public function recognize(File $file, bool $enqueueCorrecting = false): void
    {
        if ($file->type !== 'submission') {
            return;
        }

        $copyPath = null;
        try {
            $file->appendEvent('OCR started');
            $store = ObjectStore::getInstance();
            $copyPath = $store->downloadToTemp($file->contentKey());

            $client = $this->createGoogleOCRClient();
            $client->set_file($copyPath, $file->name);
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

            if ($enqueueCorrecting) {
                RedisQueue::getInstance()->enqueueFile($file->id, Task1Correcting::class);
            }
        } finally {
            if ($copyPath !== null) {
                @unlink($copyPath);
            }
        }
    }

    protected function createGoogleOCRClient(): GoogleOCRClient
    {
        return new GoogleOCRClient();
    }
}
