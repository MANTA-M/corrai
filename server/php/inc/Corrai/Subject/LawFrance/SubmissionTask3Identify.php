<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Clients\Openrouter\GeminiFlashLiteClient;
use Corrai\Model\BaseAssessment;
use Corrai\Model\OCRResult;
use Corrai\Model\SubmissionFile;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Store\ObjectStore;
use Throwable;

class SubmissionTask3Identify extends PathQueueItemTask
{
    public const IDENTIFY_EVENT = 'Submission identified';

    private const OCR_WORD_LIMIT = 30;

    public function __construct(private ?GeminiFlashLiteClient $gemini = null)
    {
    }

    protected function process(object $queue_item_data, string $s3_path): void
    {
        $file = $this->loadFile($s3_path);
        if (!$file instanceof SubmissionFile) {
            $file = $file->asClass(Submission::class);
        }
        $this->identifySubmission($file);
    }

    public function identifySubmission(SubmissionFile $file, ?BaseAssessment $assessment = null): void
    {
        if ($assessment === null && $file->assessment_id !== null && $file->assessment_id !== '') {
            try {
                $assessment = $this->loadAssessment($file);
            } catch (Throwable $e) {
                error_log(sprintf('[SubmissionTask3Identify] Failed to load assessment %s for file %s: %s', $file->assessment_id, (string) $file->id, $e->getMessage()));
            }
        }

        if ($assessment !== null) {
            try {
                $this->writeStudentIdentifier($file, $assessment);
            } catch (Throwable $e) {
                error_log(sprintf('[SubmissionTask3Identify] Failed to identify file %s: %s', (string) $file->id, $e->getMessage()));
            }
        }

        $file->status = 'transcribed';
        try {
            $file->saveAttributes();
        } catch (Throwable $e) {
            error_log(sprintf('[SubmissionTask3Identify] Failed to save file attributes for %s: %s', (string) $file->id, $e->getMessage()));
        }
        try {
            $file->appendEvent(self::IDENTIFY_EVENT);
        } catch (Throwable $e) {
            // Ignore when event storage is unavailable
        }

        if ($assessment === null) {
            return;
        }

        if (!$assessment->hasUnassignedTranscribingSubmissions((string) $file->id)) {
            $assessment->status = 'affecting';
            try {
                $assessment->save();
            } catch (Throwable $e) {
                error_log('[SubmissionTask3Identify] Assessment save failed: ' . $e->getMessage());
            }
            try {
                $assessment->appendEvent('Affectation queued');
            } catch (Throwable $e) {
                // Ignore when event storage is unavailable
            }
            if ($assessment->id !== null && $assessment->id !== '') {
                RedisQueue::getInstance()->enqueueAssessment((string) $assessment->id, AssTask1Affectation::class);
            }
        }
    }

    private function writeStudentIdentifier(SubmissionFile $file, BaseAssessment $assessment): void
    {
        $instruction = $assessment->instructionFilesText();
        $store = ObjectStore::getInstance();
        $ocrKey = $file->ocrResultKey();
        if (!$store->exists($ocrKey)) {
            error_log(sprintf('[SubmissionTask3Identify] OCR result is missing at %s for file %s', $ocrKey, (string) $file->id));
            return;
        }

        $ocr = OCRResult::from_json($store->getContents($ocrKey));
        $words = $this->firstWords($ocr);
        $wordsJson = json_encode($words, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($wordsJson)) {
            throw new \Exception('Cannot encode the OCR words');
        }

        $client = $this->geminiClient();
        $client->set_system_content(
            'You identify a student on an exam paper. '
            . 'Respond only with a JSON object {"id": string or null}.'
        );
        $client->set_json_response('student_identifier', [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'null'],
                    ],
                ],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ]);
        $client->add_text("Assessment instructions:\n" . $instruction);
        $client->add_text("OCR words from the exam paper, limited to the first 30, with their box:\n" . $wordsJson);
        $client->add_text("Find the student identification if it exists. If it does not exist, id is null.");

        $result = $client->call();
        $response = $result['response'] ?? null;
        if (!is_array($response) || !array_key_exists('id', $response)) {
            $detail = is_string($response) ? $response : 'empty identification';
            throw new \Exception('Student identification failed: ' . $detail);
        }
        $id = $response['id'];
        if ($id !== null && !is_string($id)) {
            throw new \Exception('Student identification id must be a string or null');
        }
        $file->student_identifier = $id;
    }

    /**
     * @return list<array{text: string, box: array{left: float, top: float, right: float, bottom: float}}>
     */
    private function firstWords(OCRResult $ocr): array
    {
        $words = [];
        foreach (array_slice($ocr->words, 0, self::OCR_WORD_LIMIT) as $word) {
            $words[] = [
                'text' => $word->text,
                'box' => [
                    'left' => $word->left,
                    'top' => $word->top,
                    'right' => $word->right,
                    'bottom' => $word->bottom,
                ],
            ];
        }
        return $words;
    }

    protected function geminiClient(): GeminiFlashLiteClient
    {
        return $this->gemini ?? new GeminiFlashLiteClient();
    }
}

if (!class_exists('LawFrance\\SubmissionTask3Identify', false)) {
    class_alias(SubmissionTask3Identify::class, 'LawFrance\\SubmissionTask3Identify');
}

if (!class_exists('Corrai\\Subject\\LawFrance\\SubmissionTask4Identify', false)) {
    class_alias(SubmissionTask3Identify::class, 'Corrai\\Subject\\LawFrance\\SubmissionTask4Identify');
}

if (!class_exists('LawFrance\\SubmissionTask4Identify', false)) {
    class_alias(SubmissionTask3Identify::class, 'LawFrance\\SubmissionTask4Identify');
}
