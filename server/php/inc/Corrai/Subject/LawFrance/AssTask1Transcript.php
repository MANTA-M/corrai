<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Clients\Google\Vision;
use Corrai\Clients\Mindee\MindeeOCR;
use Corrai\Model\BaseAssessment;
use Corrai\Model\InputFile;
use Corrai\Model\OCRResult;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Model\Task\QueueItemTask;
use Corrai\Utils\Store\ObjectStore;
use Throwable;

/**
 * Parallel OCR for every unclassified copy that still lacks ocr_result.json.
 *
 * Google Vision uses RestClient::QueryMulti. Mindee and PDF/TIFF fall back to
 * sequential process(). After each OCR success, rotate/crop runs inline via
 * SubmissionTask2RotateAndCrop::apply().
 */
class AssTask1Transcript extends QueueItemTask
{
    private const OCR_LANG = 'fr';

    private const OCR_SERVICE_MINDEE = 'mindee';

    private const OCR_SERVICE_GOOGLE = 'google';

    private const OCR_SERVICE = self::OCR_SERVICE_GOOGLE;

    public function __construct(
        private ?Vision $visionClient = null,
        private ?MindeeOCR $mindeeClient = null,
        private ?PathQueueItemTask $eventReporter = null,
        private ?SubmissionTask2RotateAndCrop $rotateAndCrop = null,
    ) {
    }

    protected function process(object $queue_item_data): void
    {
        $assessmentId = $queue_item_data->assessment_id ?? null;
        if ($assessmentId === null || $assessmentId === '') {
            error_log('[AssTask1Transcript] Assessment ID is missing on ticket');
            return;
        }

        try {
            $assessment = BaseAssessment::from_hash($assessmentId);
            $this->processAssessment($assessment);
        } catch (Throwable $e) {
            error_log(sprintf('[AssTask1Transcript] Error processing assessment %s: %s', $assessmentId, $e->getMessage()));
        }
    }

    public function processAssessment(BaseAssessment $assessment): void
    {
        if (!$assessment instanceof Assessment) {
            return;
        }

        $store = ObjectStore::getInstance();
        /** @var list<Submission> $files */
        $files = [];
        foreach ($assessment->unclassifiedFileIds() as $fileId) {
            try {
                $file = $assessment->getFile($fileId);
            } catch (Throwable $e) {
                error_log(sprintf('[AssTask1Transcript] Failed to load file %s: %s', $fileId, $e->getMessage()));
                continue;
            }
            if (!$file instanceof InputFile) {
                continue;
            }
            if (!$file instanceof Submission) {
                $file = $file->asClass(Submission::class);
            }
            if ($store->exists($file->ocrResultKey())) {
                continue;
            }
            $files[] = $file;
        }

        if ($files === []) {
            return;
        }

        if (self::OCR_SERVICE === self::OCR_SERVICE_MINDEE) {
            $this->processMindee($files);
            return;
        }

        $this->processGoogle($files);
    }

    /**
     * @param list<Submission> $files
     */
    private function processGoogle(array $files): void
    {
        $client = $this->createVisionClient();
        $store = ObjectStore::getInstance();
        $multiRequests = [];
        /** @var array<string, array{file: Submission, startState: ?array}> $pending */
        $pending = [];

        foreach ($files as $file) {
            $fileId = (string) $file->id;
            if ($file->size > Vision::MAX_FILE_SIZE) {
                $this->failFile($file, null, 'Image file too heavy');
                continue;
            }

            $startState = $this->reporter()->reportStart($fileId);
            try {
                $file->appendEvent('OCR started');
            } catch (Throwable $e) {
                error_log($e->getMessage());
            }

            $mime = strtolower(trim(explode(';', $file->content_type, 2)[0]));
            if (Vision::isDocumentMime($mime)) {
                $this->processGoogleDocument($file, $client, $startState);
                continue;
            }

            try {
                $bytes = $store->getContents($file->contentKey());
            } catch (Throwable $e) {
                $this->failFile($file, $startState, $e->getMessage());
                continue;
            }
            if ($bytes === '' || strlen($bytes) > Vision::MAX_FILE_SIZE) {
                $this->failFile($file, $startState, 'Image file too heavy');
                continue;
            }

            $pending[$fileId] = ['file' => $file, 'startState' => $startState];
            $multiRequests[] = [
                'path' => Vision::IMAGES_ANNOTATE_URL,
                'method' => 'POST',
                'postData' => $client->buildImagesAnnotatePostData($bytes, self::OCR_LANG),
                'file_id' => $fileId,
            ];
        }

        if ($multiRequests === []) {
            return;
        }

        try {
            $client->authorize();
        } catch (Throwable $e) {
            foreach ($pending as $entry) {
                $this->failFile($entry['file'], $entry['startState'], $e->getMessage());
            }
            return;
        }

        $client->QueryMulti($multiRequests, function (array $params, ?string $body, int $httpCode) use ($client, $pending): void {
            $fileId = $params['file_id'] ?? null;
            if (!is_string($fileId) || $fileId === '' || !isset($pending[$fileId])) {
                return;
            }
            $entry = $pending[$fileId];
            $file = $entry['file'];
            $startState = $entry['startState'];

            try {
                if ($httpCode < 200 || $httpCode > 299 || $body === null || $body === '') {
                    throw new \Exception('OCR request failed', $httpCode > 0 ? $httpCode : 500);
                }
                $output = $client->outputFromImagesAnnotateBody($body);
                $result = OCRResult::from_google($output);
                $this->finishOcrSuccess($file, $result, $startState);
            } catch (Throwable $e) {
                error_log(sprintf('[AssTask1Transcript] OCR failed for file %s: %s', $fileId, $e->getMessage()));
                $this->failFile($file, $startState, 'OCR failed');
            }
        });
    }

    /**
     * @param array{file?: array<string, mixed>, student?: array<string, mixed>|null, stats?: array<string, mixed>}|null $startState
     */
    private function processGoogleDocument(Submission $file, Vision $client, ?array $startState): void
    {
        $copyPath = null;
        try {
            $copyPath = ObjectStore::getInstance()->downloadToTemp($file->contentKey());
            $client->set_file($copyPath, $file->name, $file);
            $client->set_language(self::OCR_LANG);
            $result = OCRResult::from_google($client->process());
            $this->finishOcrSuccess($file, $result, $startState);
        } catch (Throwable $e) {
            error_log(sprintf('[AssTask1Transcript] Document OCR failed for file %s: %s', (string) $file->id, $e->getMessage()));
            $this->failFile($file, $startState, 'OCR failed');
        } finally {
            if ($copyPath !== null && is_file($copyPath)) {
                @unlink($copyPath);
            }
        }
    }

    /**
     * @param list<Submission> $files
     */
    private function processMindee(array $files): void
    {
        foreach ($files as $file) {
            $fileId = (string) $file->id;
            if ($file->size > MindeeOCR::MAX_FILE_SIZE) {
                $this->failFile($file, null, 'Image file too heavy');
                continue;
            }

            $startState = $this->reporter()->reportStart($fileId);
            $copyPath = null;
            try {
                $file->appendEvent('OCR started');
                $copyPath = ObjectStore::getInstance()->downloadToTemp($file->contentKey());
                $client = $this->createMindeeClient();
                $client->set_file($copyPath, $file->name, $file);
                $client->set_language(self::OCR_LANG);
                $result = OCRResult::from_google($client->process());
                $this->finishOcrSuccess($file, $result, $startState);
            } catch (Throwable $e) {
                error_log(sprintf('[AssTask1Transcript] Mindee OCR failed for file %s: %s', $fileId, $e->getMessage()));
                $this->failFile($file, $startState, 'OCR failed');
            } finally {
                if ($copyPath !== null && is_file($copyPath)) {
                    @unlink($copyPath);
                }
            }
        }
    }

    /**
     * @param array{file?: array<string, mixed>, student?: array<string, mixed>|null, stats?: array<string, mixed>}|null $startState
     */
    private function finishOcrSuccess(Submission $file, OCRResult $result, ?array $startState): void
    {
        $store = ObjectStore::getInstance();
        $store->putContents(
            $file->ocrResultKey(),
            $result->to_json(true),
            'application/json'
        );

        try {
            $file->appendEvent('OCR ended');
            $file->saveAttributes();
        } catch (Throwable $e) {
            error_log(sprintf('[AssTask1Transcript] Failed to save OCR ended for %s: %s', (string) $file->id, $e->getMessage()));
        }

        try {
            $this->rotateTask()->apply($file);
        } catch (Throwable $e) {
            error_log(sprintf('[AssTask1Transcript] Rotate/crop failed for %s: %s', (string) $file->id, $e->getMessage()));
            $this->failFile($file, $startState, 'OCR failed');
            return;
        }

        if ($file->id !== null && $file->id !== '') {
            $this->reporter()->reportEnd((string) $file->id, $startState);
        }
    }

    /**
     * @param array{file?: array<string, mixed>, student?: array<string, mixed>|null, stats?: array<string, mixed>}|null $startState
     */
    private function failFile(Submission $file, ?array $startState, string $eventName): void
    {
        try {
            if ($eventName === 'OCR failed' || $eventName === 'Image file too heavy') {
                $file->appendEvent($eventName);
            } else {
                $file->appendEvent('OCR failed');
            }
            $file->status = 'error';
            $file->saveAttributes();
        } catch (Throwable $ignore) {
        }
        if ($file->id !== null && $file->id !== '') {
            $this->reporter()->reportEnd((string) $file->id, $startState);
        }
    }

    protected function createVisionClient(): Vision
    {
        return $this->visionClient ?? new Vision();
    }

    protected function createMindeeClient(): MindeeOCR
    {
        return $this->mindeeClient ?? new MindeeOCR();
    }

    private function reporter(): PathQueueItemTask
    {
        if ($this->eventReporter !== null) {
            return $this->eventReporter;
        }
        return $this->eventReporter = new class extends PathQueueItemTask {
            protected function process(object $queue_item_data, string $s3_path): void
            {
            }
        };
    }

    private function rotateTask(): SubmissionTask2RotateAndCrop
    {
        return $this->rotateAndCrop ?? new SubmissionTask2RotateAndCrop();
    }
}

if (!class_exists('LawFrance\\AssTask1Transcript', false)) {
    class_alias(AssTask1Transcript::class, 'LawFrance\\AssTask1Transcript');
}
