<?php

namespace Corrai\Subject\DictationFranceCM1;

use Corrai\Llm\Openrouter\ClaudeSonnetClient;
use Corrai\Model\BaseAssessment;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\WSException;
use Throwable;

class Task1Correcting extends PathQueueItemTask
{
    public ?float $rescale = null;

    protected function process(object $queue_item_data, string $s3_path): void
    {
        $this->correct($this->loadFile($s3_path));
    }
    /**
     * Find dictation errors. Same step as File::on_ocr_done.
     */
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
            $copyPath = $store->downloadToTemp($file->contentKey());

            $subjectFiles = $this->downloadSubjectFiles($assessment, $store);
            $correction = $this->findErrors(
                $assessment,
                $copyPath,
                $file->name,
                $this->languageName($file),
                $subjectFiles
            );
            $store->putContents(
                $file->foundErrorsKey(),
                $this->correctionDocument($correction),
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

    private function correctionDocument(string $correction): string
    {
        $data = json_decode($correction, true);
        if (!is_array($data)) {
            return $correction;
        }
        $data['rescale'] = $this->rescale;
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return $correction;
        }

        return $encoded;
    }
    /**
     * @param list<array{path: string, name: string}> $subjectFiles
     */
    protected function findErrors(
        BaseAssessment $assessment,
        string $copyPath,
        string $copyName,
        string $languageName,
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
            'First, give the OCR image cropping coordinates. Put 0 if no cropping was done. '
            . 'Also list every handwritten line by the Y of its baseline, using the same normalized coordinates as error boxes: 0 at the top of the cropped image and 1000 at the bottom. '
            . 'Second step, find the errors: You decipher a student dictation copy by reading it against the official corrigé. '
            . 'Identify every error compared with the corrigé: spelling, accents, missing or extra words, '
            . 'punctuation, word order, and passages that are unreadable. '
            . 'Gather the coordinates of the box containing the error in the original image. '
            . 'Third step, filter the errors: Do not get missing space errors. '
            . 'Do not count as errors badly written letters and keep only clear spelling or grammar errors. '
            . 'Step three, write the correction: Do not rewrite the full dictation. List only the errors. '
            . 'For each error give the student writing, the expected text from the corrigé, and the kind of mistake. '
            . 'Write text fields in ' . $languageName . '. '
            . 'All coordinates are pixels of the original image, origin top-left. '
            . "Follow these assessment-specific instructions:\n"
            . $instructionText
        );
        $request->set_json_response('dictation_errors', [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['cropped_image', 'lines', 'errors'],
            'properties' => [
                'cropped_image' => [
                    'type' => 'object',
                    'description' => 'OCR-analyzed image cropped coordinates in pixels. Origin is top-left.',
                    'additionalProperties' => false,
                    'required' => ['x1', 'y1', 'x2', 'y2'],
                    'properties' => [
                        'x1' => ['type' => 'integer'],
                        'y1' => ['type' => 'integer'],
                        'x2' => ['type' => 'integer'],
                        'y2' => ['type' => 'integer'],
                    ],
                ],
                'lines' => [
                    'type' => 'array',
                    'description' => 'One entry per handwritten line. y is the baseline, normalized from 0 at the top of the cropped image to 1000 at the bottom.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['y'],
                        'properties' => [
                            'y' => [
                                'type' => 'integer',
                                'description' => 'Baseline Y of the handwritten line, normalized between 0 and 1000.',
                            ],
                        ],
                    ],
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
                                'description' => 'The errorbounding box using normalized values ​​between 0 and 1000 (where 0,0 is the top-left corner and 1000,1000 is the bottom-right corner).',
                                'additionalProperties' => false,
                                'required' => ['x1', 'y1', 'x2', 'y2'],
                                'properties' => [
                                    'x1' => ['type' => 'integer'],
                                    'y1' => ['type' => 'integer'],
                                    'x2' => ['type' => 'integer'],
                                    'y2' => ['type' => 'integer'],
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
        $resp = $request->call_text();
        $this->rescale = $request->rescale;
        return $resp;
    }

    protected function createClaudeSonnetClient(): ClaudeSonnetClient
    {
        return new ClaudeSonnetClient();
    }
}
