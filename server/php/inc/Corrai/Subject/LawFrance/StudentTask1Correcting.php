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
use Corrai\Utils\Store\HashId;
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
            $this->correctionGridText($assessment),
            $submission,
            $languageName
        );

        $this->storeCorrectionFile($student, $reply);
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
        string $correctionGrid,
        array $submission,
        string $languageName
    ): string {
        $request = new ClaudeSonnetClient();
        $request->set_system_content(
            "Vous êtes un professeur chargé de corriger les copies d'une école d'avocat.\n"
            . "Appliquez strictement la grille de correction. Reproduisez les mêmes modificateurs généraux, "
            . "les mêmes parties, les mêmes questions et les mêmes critères, dans le même ordre, "
            . "avec les mêmes libellés et les mêmes modificateurs.\n"
            . "Pour chaque critère, indiquez s'il est retenu. Les points obtenus d'une question sont la somme "
            . "des modificateurs positifs retenus, sans dépasser le barème de la question, après déduction "
            . "des modificateurs négatifs retenus. La note sur 20 intègre les modificateurs généraux retenus.\n"
            . "L'appréciation et les remarques sont rédigées en " . $languageName . ".\n"
            . "\n# Instructions\n"
            . $instructionText
            . "\n# Sujet & Grille de correction\n"
            . $correctionGrid
        );
        $request->set_json_response('law_correction', self::correctionSchema($languageName));
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
     * Même arborescence que la grille : modificateurs généraux, parties, questions
     * et critères proposés. Chaque critère est complété par son application à la copie.
     *
     * @return array<string, mixed>
     */
    private static function correctionSchema(string $languageName): array
    {
        $criterion = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['critère', 'modificateur', 'retenu', 'commentaire'],
            'properties' => [
                'critère' => [
                    'type' => 'string',
                    'description' => 'Libellé identique au critère de la grille.',
                ],
                'modificateur' => [
                    'type' => 'integer',
                    'description' => 'Points prévus par la grille : ajoutés si le nombre est positif, retirés s\'il est négatif.',
                ],
                'retenu' => [
                    'type' => 'boolean',
                    'description' => 'Vrai si ce critère s\'applique à la copie.',
                ],
                'commentaire' => [
                    'type' => 'string',
                    'description' => 'Justification courte, en ' . $languageName . '.',
                ],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['modificateurs_généraux', 'parties', 'mark', 'appreciation', 'remarks'],
            'properties' => [
                'modificateurs_généraux' => [
                    'type' => 'array',
                    'description' => 'Mêmes modificateurs que la grille, dans le même ordre.',
                    'items' => $criterion,
                ],
                'parties' => [
                    'type' => 'array',
                    'description' => 'Mêmes parties et mêmes questions que la grille, dans le même ordre.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['titre', 'questions'],
                        'properties' => [
                            'titre' => [
                                'type' => 'string',
                                'description' => 'Titre de la partie, identique à la grille.',
                            ],
                            'questions' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['titre', 'points', 'points_obtenus', 'critères_proposés'],
                                    'properties' => [
                                        'titre' => [
                                            'type' => 'string',
                                            'description' => 'Titre de la question, identique à la grille.',
                                        ],
                                        'points' => [
                                            'type' => 'integer',
                                            'description' => 'Barème de la question, identique à la grille.',
                                        ],
                                        'points_obtenus' => [
                                            'type' => 'integer',
                                            'description' => 'Points retenus pour cette question, entre 0 et le barème.',
                                        ],
                                        'critères_proposés' => [
                                            'type' => 'array',
                                            'description' => 'Mêmes critères que la grille, dans le même ordre.',
                                            'items' => $criterion,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'mark' => [
                    'type' => 'number',
                    'description' => 'Note sur 20.',
                ],
                'appreciation' => [
                    'type' => 'string',
                    'description' => 'Appréciation en Markdown, en ' . $languageName . '.',
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
                                'description' => 'Numéro de page.',
                            ],
                            'text' => [
                                'type' => 'string',
                                'description' => 'Remarque en ' . $languageName . '.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
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

    private function correctionGridText(BaseAssessment $assessment): string
    {
        $key = ObjectStore::assessmentSubjectCorrectionGridKey(
            $assessment->school_id,
            $assessment->user_id,
            (string) $assessment->id
        );
        $store = ObjectStore::getInstance();
        if (!$store->exists($key)) {
            throw new WSException('Correction grid is missing', 400);
        }
        return S3File::at($key)->getContents();
    }

    /**
     * Full correction JSON, stored as the student's correction.json.
     */
    public function storeCorrectionFile(BaseStudent $student, string $correction): S3File
    {
        if ($student->id === null || $student->id === '') {
            throw new WSException('Student has no id', 400);
        }
        $key = ObjectStore::assessmentStudentPrefix(
            $student->school_id,
            $student->user_id,
            $student->assessment_id,
            (string) $student->id
        ) . 'correction.json';

        $store = ObjectStore::getInstance();
        $file = null;
        if ($store->exists($key)) {
            try {
                $file = S3File::from_key($key);
            } catch (Throwable $e) {
                $file = null;
            }
        }
        if ($file === null) {
            $file = new S3File();
            $file->id = HashId::create();
            $file->key = $key;
            $file->created = time();
        }
        $file->school_id = $student->school_id;
        $file->user_id = $student->user_id;
        $file->assessment_id = $student->assessment_id;
        $file->name = 'correction.json';
        $file->type = 'correction';
        $file->student = (string) $student->id;
        if ($file->created <= 0) {
            $file->created = time();
        }
        $file->putContents($correction, 'application/json');
        $store->setIdPointer((string) $file->id, $key, false);
        return $file;
    }

    /**
     * Grade and Markdown appreciation shown on the student.
     */
    public function storeStudentResult(BaseStudent $student, string $correction): void
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
