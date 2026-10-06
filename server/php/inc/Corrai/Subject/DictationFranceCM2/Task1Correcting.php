<?php

namespace Corrai\Subject\DictationFranceCM2;

use Corrai\Llm\Openrouter\ClaudeSonnetClient;
use Corrai\Model\BaseAssessment;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\WSException;
use Throwable;

/**
 * Find dictation errors after OCR. Same step as File::on_ocr_done.
 */
class Task1Correcting extends PathQueueItemTask
{
    protected function process(object $queue_item_data, string $s3_path): void
    {
        $this->correct($this->loadFile($s3_path));
    }

    public function correct(File $file): void
    {
        if ($file->type !== 'submission') {
            return;
        }

        $copyPath = null;
        $subjectFiles = [];
        try {
            $assessment = $this->loadAssessment($file);
            $store = ObjectStore::getInstance();
            $ocrKey = $file->ocrResultKey();
            if (!$store->exists($ocrKey)) {
                throw new WSException('OCR result is missing for this file', 400);
            }
            $ocrRaw = $store->getContents($ocrKey);
            $ocrWords = json_decode($ocrRaw, true);
            if (!is_array($ocrWords)) {
                throw new WSException('Invalid OCR result JSON', 400);
            }

            $unclassified = ObjectStore::assessmentFileArea($file->type, $file->student) === 'unclassified';

            $copyPath = $store->downloadToTemp($file->contentKey());
            $subjectFiles = $this->downloadSubjectFiles($assessment, $store);

            $correction = $this->findErrors(
                $assessment,
                $copyPath,
                $file->name,
                $assessment->correctionLanguageName(),
                $ocrWords,
                $subjectFiles
            );

            if ($unclassified) {
                $this->assignUnclassifiedCopy($file, $assessment, $correction);
            }
            $this->storeStudentResult($file, $assessment, $correction);

            $store->putContents(
                $file->foundErrorsKey(),
                $correction,
                'application/json'
            );

            $file->status = 'errors_found';
            $file->saveAttributes();
            $file->appendEvent('Errors found');
            RedisQueue::getInstance()->enqueueFile($file->id, Task2Annotating::class);
        } catch (Throwable $th) {
            $this->failCorrection($file, $th);
        } finally {
            if ($copyPath !== null) {
                @unlink($copyPath);
            }
            foreach ($subjectFiles as $subjectFile) {
                @unlink($subjectFile['path']);
            }
        }
    }

    /**
     * Every file stored under the assessment subject/ folder.
     *
     * @return list<array{path: string, name: string}>
     */
    private function downloadSubjectFiles(BaseAssessment $assessment, ObjectStore $store): array
    {
        $prefix = ObjectStore::assessmentSubjectFilesPrefix(
            $assessment->school_id,
            $assessment->user_id,
            (string) $assessment->id
        );
        $files = [];
        try {
            foreach ($store->listChildPrefixes($prefix) as $fileId) {
                $subjectFile = $assessment->getFile($fileId);
                $path = $store->downloadToTemp($subjectFile->contentKey());
                $files[] = ['path' => $path, 'name' => $subjectFile->name];
            }
        } catch (Throwable $error) {
            foreach ($files as $subjectFile) {
                @unlink($subjectFile['path']);
            }
            throw $error;
        }

        return $files;
    }

    /**
     * When the copy is still unclassified, move its whole directory under the named student.
     */
    public function assignUnclassifiedCopy(File $file, BaseAssessment $assessment, string $correction): void
    {
        $data = json_decode($correction, true);
        if (!is_array($data)) {
            return;
        }
        $studentName = trim((string) ($data['student_name'] ?? ''));
        if ($studentName === '') {
            $studentName = $assessment->nextUnknownStudentName();
        }

        $student = $assessment->findOrCreateStudentByName($studentName);
        $file->student = $student->id;
        $file->saveAttributes();
        $file->appendEvent('Assigned to student ' . $student->name);
    }

    /**
     * Once the copy belongs to a student, keep the grade and the Markdown appreciation on that student.
     */
    public function storeStudentResult(File $file, BaseAssessment $assessment, string $correction): void
    {
        $studentId = trim((string) ($file->student ?? ''));
        if ($studentId === '') {
            return;
        }
        $data = json_decode($correction, true);
        if (!is_array($data)) {
            return;
        }

        $student = $assessment->getStudent($studentId);
        $changed = false;
        if (is_numeric($data['note'] ?? null)) {
            $student->mark = (float) $data['note'];
            $changed = true;
        }
        $appreciation = $data['appreciation'] ?? null;
        if (is_string($appreciation) && trim($appreciation) !== '') {
            $student->appreciation = $appreciation;
            $changed = true;
        }
        if ($changed) {
            $student->save();
            $assessment->on_mark_change($student);
        }
    }

    /**
     * @param list<array{text: string, page: int, box: array{0: int, 1: int, 2: int, 3: int}}> $ocrWords
     * @param list<array{path: string, name: string}> $subjectFiles
     */
    public function findErrors(
        BaseAssessment $assessment,
        string $copyPath,
        string $copyName,
        string $languageName,
        array $ocrWords = [],
        array $subjectFiles = []
    ): string {
        $size = @getimagesize($copyPath);
        $width = is_array($size) ? (int) $size[0] : 0;
        $height = is_array($size) ? (int) $size[1] : 0;
        if ($width < 1 || $height < 1) {
            throw new WSException('The source file is not an image GD can annotate', 400);
        }

        $instructionText = $assessment->instructionFilesText();
        $request = $this->createClaudeSonnetClient();
        $request->set_system_content(
            'First step, read the student name: Read the full name written at the top of the copy. '
            . 'Return it as student_name. If no name is written or it is unreadable, return an empty string. '
            . 'Second step, find the errors: You decipher a student dictation copy by reading it against the official corrigé. '
            . 'Identify every error compared with the corrigé: spelling, accents, missing or extra words, '
            . 'punctuation, word order, and passages that are unreadable. '
            . 'Gather the coordinates of the box containing the error in the original image. '
            . 'You also receive the OCR words of the copy as JSON, each with its box [x1, y1, x2, y2] in pixels of that image. '
            . 'For every error, look for the OCR word that holds the student writing of the error and reuse its box as is. '
            . 'The OCR text may be misspelled or partial: match on position in the dictation as well as on the letters or the line or the order in the text. '
            . 'Only when no OCR word matches, estimate the box yourself from the image using the main lines of the grid. '
            . 'Third step, filter the errors: Do not get missing space errors. '
            . 'Do not count as errors badly written letters and keep only clear spelling or grammar errors. '
            . 'Fourth step, write the correction: Do not rewrite the full dictation. List only the errors. '
            . 'For each error give the student writing, the expected text from the corrigé, and the kind of mistake. '
            . 'Fifth step, grade the copy and write an appreciation: Give the mark out of 20 as note, a number, '
            . 'following the assessment instructions (one point off per error, and the calligraphy point when it applies). '
            . 'Write appreciation in Markdown, in ' . $languageName . ', addressed to the student. '
            . 'Write text fields in ' . $languageName . '. '
            . 'All coordinates are pixels of the image you receive, origin (0,0) is top-left. '
            . "Follow these assessment-specific instructions:\n"
            . $instructionText
        );
        $request->set_json_response('dictation_errors', [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['student_name', 'errors', 'note', 'appreciation'],
            'properties' => [
                'student_name' => [
                    'type' => 'string',
                    'description' => 'Full name written at the top of the copy, or an empty string when none is readable.',
                ],
                'note' => [
                    'type' => 'number',
                    'description' => 'Mark out of 20.',
                ],
                'appreciation' => [
                    'type' => 'string',
                    'description' => 'Appreciation in Markdown, in ' . $languageName . '.',
                ],
                'errors' => [
                    'type' => 'array',
                    'description' => 'Clear spelling or grammar errors only. Omit missing spaces and badly written letters. Do not rewrite the dictation.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['student', 'expected', 'kind', 'box'],
                        'properties' => [
                            'student' => [
                                'type' => 'string',
                                'description' => 'What the student wrote, in ' . $languageName,
                            ],
                            'expected' => [
                                'type' => 'string',
                                'description' => 'Expected text from the corrigé, in ' . $languageName,
                            ],
                            'kind' => [
                                'type' => 'string',
                                'description' => 'Kind of mistake, in ' . $languageName,
                            ],
                            'box' => [
                                'type' => 'object',
                                'description' => 'The error bounding box coordinates: the box of the matching OCR word, '
                                    . 'or your own estimate when no OCR word matches.',
                                'additionalProperties' => false,
                                'required' => ['x1', 'y1', 'x2', 'y2'],
                                'properties' => [
                                    'x1' => ['type' => 'number'],
                                    'y1' => ['type' => 'number'],
                                    'x2' => ['type' => 'number'],
                                    'y2' => ['type' => 'number'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        foreach ($subjectFiles as $subjectFile) {
            $request->add_text($subjectFile['name'] . ':');
            $request->add_file($subjectFile['path'], $subjectFile['name']);
        }
        $request->add_text('Student copy to decipher:');
        $request->add_file($copyPath, $copyName);
        $request->add_text("OCR words of the student copy:\n" . $this->ocrJson($ocrWords));
        return $request->call_text();
    }

    /**
     * @param list<array{text: string, page: int, box: array{0: int, 1: int, 2: int, 3: int}}> $words
     */
    private function ocrJson(array $words): string
    {
        $json = json_encode($words, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new WSException('Cannot encode the OCR result', 500);
        }

        return $json . "\n";
    }

    protected function createClaudeSonnetClient(): ClaudeSonnetClient
    {
        return new ClaudeSonnetClient();
    }
}
