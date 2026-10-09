<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Clients\Openrouter\GeminiFlashLiteClient;
use Corrai\Model\BaseAssessment;
use Corrai\Model\SubmissionFile;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Store\ObjectStore;
use Throwable;

class SubmissionTask3Identify extends PathQueueItemTask
{
    public const IDENTIFY_EVENT = 'Submission identified';

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
            try {
                $this->assignIdentifiedStudent($file, $assessment);
            } catch (Throwable $e) {
                error_log(sprintf('[SubmissionTask3Identify] Failed to assign file %s: %s', (string) $file->id, $e->getMessage()));
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

        if (!$assessment instanceof Assessment) {
            return;
        }
        try {
            $assessment->allocateSubmission();
        } catch (Throwable $e) {
            error_log(sprintf('[SubmissionTask3Identify] Failed to allocate submissions for assessment %s: %s', (string) $assessment->id, $e->getMessage()));
        }
    }

    private function writeStudentIdentifier(SubmissionFile $file, BaseAssessment $assessment): void
    {
        $instruction = $assessment->instructionFilesText();
        $store = ObjectStore::getInstance();
        $copyPath = null;
        try {
            $copyPath = $store->downloadToTemp($file->contentKey());
        } catch (Throwable $e) {
            error_log(sprintf('[SubmissionTask3Identify] Failed to read image for file %s: %s', (string) $file->id, $e->getMessage()));
            return;
        }

        try {
            $client = $this->geminiClient();
            $client->set_system_content(
                'You identify a student on an exam paper and evaluate handwriting/calligraphy quality. '
                . 'Respond only with a JSON object {"id": string or null, "qualigraphy_score": number or null}.'
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
                    'qualigraphy_score' => [
                        'anyOf' => [
                            ['type' => 'number'],
                            ['type' => 'null'],
                        ],
                    ],
                ],
                'required' => ['id', 'qualigraphy_score'],
                'additionalProperties' => false,
            ]);
            $client->add_text("Assessment instructions:\n" . $instruction);
            $client->add_file($copyPath, $file->name);
            $client->add_text(
                'The attached image is the exam paper. Find the student identification if it exists. If it does not exist, id is null. '
                . 'Also evaluate the handwriting/calligraphy quality and provide a qualigraphy_score (a number from 0 to 10, or null if unreadable or not applicable).'
            );

            $result = $client->call();
        } finally {
            if ($copyPath !== null && is_file($copyPath)) {
                @unlink($copyPath);
            }
        }
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

        $qualigraphyScore = $response['qualigraphy_score'] ?? null;
        if ($qualigraphyScore !== null && !is_int($qualigraphyScore) && !is_float($qualigraphyScore)) {
            if (is_numeric($qualigraphyScore)) {
                $qualigraphyScore = (float) $qualigraphyScore;
            } else {
                throw new \Exception('Qualigraphy score must be a number or null');
            }
        }
        $file->qualigraphy_score = $qualigraphyScore;
    }

    /**
     * A name already stored on the copy, or the identifier just read, becomes the student.
     * Saving the file afterwards copies its whole directory under that student.
     */
    private function assignIdentifiedStudent(SubmissionFile $file, BaseAssessment $assessment): void
    {
        $assigned = is_string($file->student) && trim($file->student) !== '';
        if ($assigned) {
            return;
        }
        $name = is_string($file->student_identifier) ? trim($file->student_identifier) : '';
        if ($name === '') {
            return;
        }
        $student = $assessment->findOrCreateStudentByName($name);
        $file->student = $student->id;
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
