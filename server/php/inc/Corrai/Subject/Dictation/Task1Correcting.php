<?php

namespace Corrai\Subject\Dictation;

use Corrai\Clients\Openrouter\ClaudeSonnetClient;
use Corrai\Model\BaseAssessment;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\WSException;
use Throwable;

class Task1Correcting extends PathQueueItemTask
{

    protected function process(object $queue_item_data, string $s3_path): void
    {
        $this->correct($this->loadFile($s3_path));
    }
    /**
     * Find dictation errors. Same step as Submission::on_ocr_done.
     */
    public function correct(Submission $file): void
    {

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
                $correction,
                'text/plain; charset=utf-8'
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
     * @param list<array{path: string, name: string}> $subjectFiles
     */
    private function findErrors(
        BaseAssessment $assessment,
        string $copyPath,
        string $copyName,
        string $languageName,
        array $subjectFiles = []
    ): string {
        $instructionText = $assessment->instructionFilesText();
        $request = new ClaudeSonnetClient();
        $request->set_system_content(
            'First step, find the errors: You decipher a student dictation copy by reading it against the official corrigé. '
            . 'Identify every error compared with the corrigé: spelling, accents, missing or extra words, '
            . 'punctuation, word order, and passages that are unreadable. '
            . 'Second step, filter the errors: Do not get missing space errors. '
            . 'Do not count as errors badly written letters and keep only clear spelling or grammar errors. '
            . 'Step three, write the correction: Do not rewrite the full dictation. List only the errors. '
            . 'For each error give the student writing, the expected text from the corrigé, and the kind of mistake. '
            . 'Write in ' . $languageName . '. '
            . "Follow these assessment-specific instructions:\n"
            . $instructionText
        );
        foreach ($subjectFiles as $subjectFile) {
            $request->add_text($subjectFile['name'] . ':');
            $request->add_file($subjectFile['path'], $subjectFile['name']);
        }
        $request->add_text('Student copy to decipher:');
        $request->add_file($copyPath, $copyName);
        return $request->call_text();
    }
}
