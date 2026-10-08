<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Clients\Openrouter\ClaudeSonnetClient;
use Corrai\Model\BaseAssessment;
use Corrai\Model\BaseStudent;
use Corrai\Model\InstructionFile;
use Corrai\Model\OCRResult;
use Corrai\Model\S3File;
use Corrai\Model\SubmissionFile;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\WSException;
use Throwable;

class StudentTask1Correcting extends PathQueueItemTask
{
    public function process_task(object $queue_item_data): void
    {
        $studentId = $queue_item_data->student_id ?? null;
        if ($studentId !== null && $studentId !== '') {
            error_log('Processing task: ' . ($queue_item_data->task_id ?? static::class));
            try {
                $student = BaseStudent::from_hash($studentId);
                $this->processStudent($student);
            } catch (Throwable $e) {
                error_log(sprintf('[StudentTask1Correcting] Error processing student %s: %s', $studentId, $e->getMessage()));
            }
            error_log('Task processed: ' . ($queue_item_data->task_id ?? static::class));
            return;
        }

        parent::process_task($queue_item_data);
    }

    public function processStudent(BaseStudent $student): void
    {
        try {
            $assessment = $student->getAssessment();
            $this->correctStudent($assessment, $student);
        } catch (Throwable $e) {
            error_log(sprintf('[StudentTask1Correcting] Error processing student %s: %s', (string) $student->id, $e->getMessage()));
            try {
                $student->status = 'error';
                $student->save();
            } catch (Throwable $ignore) {
            }
            throw $e;
        }
    }

    protected function process(object $queue_item_data, string $s3_path): void
    {
        $file = $this->loadFile($s3_path);
        if ($file instanceof SubmissionFile) {
            $this->correctSubmission($file);
        }
    }

    public function correctSubmission(SubmissionFile $file): void
    {
        try {
            $assessment = $this->loadAssessment($file);
            $studentId = trim((string) ($file->student ?? ''));
            if ($studentId === '') {
                throw new WSException('Submission has no student', 400);
            }
            $this->correctStudent($assessment, $assessment->getStudent($studentId));
        } catch (Throwable $th) {
            $this->failCorrection($file, $th);
        }
    }

    public function correctStudent(BaseAssessment $assessment, BaseStudent $student): void
    {
        $files = $student->getSubmissions();
        if ($files === []) {
            throw new WSException('No submissions found for student ' . $student->name, 400);
        }
        usort($files, static function (SubmissionFile $a, SubmissionFile $b): int {
            return strcmp($a->name, $b->name);
        });

        $submission = [];
        foreach ($files as $file) {
            $submission[] = $this->ocrText($file);
        }

        $languageName = $assessment->correctionLanguageName();
        $reply = $this->correct(
            $this->instructionText($assessment),
            $this->compileSubjectText($assessment),
            $submission,
            $languageName
        );

        $key = ObjectStore::assessmentStudentPrefix(
            $student->school_id,
            $student->user_id,
            $student->assessment_id,
            (string) $student->id
        ) . 'correction.json';
        S3File::at($key)->putContents($reply, 'application/json');

        $this->storeStudentResult($student, $reply);
        foreach ($files as $file) {
            $file->status = 'corrected';
            $file->saveAttributes();
            $file->appendEvent('Correction written');
        }
    }

    /**
     * @param list<string> $submission
     */
    private function correct(
        string $instructionText,
        string $compileSubject,
        array $submission,
        string $languageName
    ): string {
        $request = new ClaudeSonnetClient();
        $request->set_system_content(
            "Vous êtes un profresseur en charge de cooriger les copies d'une école d'avocat.\n"
            . $instructionText
            . "\n# Le sujet de l'examen\n"
            . $compileSubject
        );
        $request->set_json_response('law_correction', [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['mark', 'appreciation', 'remarks'],
            'properties' => [
                'mark' => [
                    'type' => 'number',
                    'description' => 'Mark out of 20.',
                ],
                'appreciation' => [
                    'type' => 'string',
                    'description' => 'Appreciation in Markdown, in ' . $languageName . '.',
                ],
                'remarks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['page', 'text'],
                        'properties' => [
                            'page' => [
                                'type' => 'number',
                                'description' => 'Page number.',
                            ],
                            'text' => [
                                'type' => 'string',
                                'description' => 'Remark ' . $languageName . '.',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $request->add_text($this->submissionText($submission));
        $reply = $request->call_text();
        $decoded = json_decode($reply, true);
        if (!is_array($decoded)) {
            throw new WSException('Correction reply is not JSON', 500);
        }
        $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            throw new WSException('Cannot encode the correction', 500);
        }
        return $json . "\n";
    }

    /**
     * @param list<string> $submission
     */
    private function submissionText(array $submission): string
    {
        $pages = [];
        foreach ($submission as $index => $text) {
            $pages[] = 'Page ' . ($index + 1) . ":\n" . $text;
        }
        return implode("\n\n", $pages);
    }

    private function ocrText(SubmissionFile $file): string
    {
        $store = ObjectStore::getInstance();
        $ocrKey = $file->ocrResultKey();
        if (!$store->exists($ocrKey)) {
            throw new WSException('OCR is missing for ' . $file->name, 400);
        }
        return OCRResult::from_json($store->getContents($ocrKey))->text;
    }

    private function instructionText(BaseAssessment $assessment): string
    {
        $store = ObjectStore::getInstance();
        $parts = [];
        foreach ($assessment->listFileModels() as $file) {
            $isInstruction = $file instanceof InstructionFile
                || ($file instanceof S3File && $file->type === 'instructions');
            if (!$isInstruction) {
                continue;
            }
            $parts[] = $store->getContents($file->contentKey());
        }
        return implode("\n\n", $parts);
    }

    private function compileSubjectText(BaseAssessment $assessment): string
    {
        $key = ObjectStore::assessmentSubjectCompileKey(
            $assessment->school_id,
            $assessment->user_id,
            (string) $assessment->id
        );
        $store = ObjectStore::getInstance();
        if (!$store->exists($key)) {
            throw new WSException('Compiled subject is missing', 400);
        }
        return S3File::at($key)->getContents();
    }

    private function storeStudentResult(BaseStudent $student, string $correction): void
    {
        $data = json_decode($correction, true);
        if (!is_array($data)) {
            return;
        }
        $changed = false;
        if (is_numeric($data['mark'] ?? null)) {
            $student->mark = (float) $data['mark'];
            $changed = true;
        }
        $appreciation = $data['appreciation'] ?? null;
        if (is_string($appreciation) && trim($appreciation) !== '') {
            $student->appreciation = $appreciation;
            $changed = true;
        }
        if ($student->status !== 'graded') {
            $student->status = 'graded';
            $changed = true;
        }
        if ($changed) {
            $student->save();
        }
    }
}

if (!class_exists('Corrai\Subject\LawFrance\Task2Correcting', false)) {
    class_alias(StudentTask1Correcting::class, 'Corrai\Subject\LawFrance\Task2Correcting');
}
if (!class_exists('LawFrance\StudentTask1Correcting', false)) {
    class_alias(StudentTask1Correcting::class, 'LawFrance\StudentTask1Correcting');
}
if (!class_exists('LawFrance\Task2Correcting', false)) {
    class_alias(StudentTask1Correcting::class, 'LawFrance\Task2Correcting');
}
