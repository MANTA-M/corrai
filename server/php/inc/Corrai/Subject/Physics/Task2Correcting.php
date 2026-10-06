<?php

namespace Corrai\Subject\Physics;

use Corrai\Llm\Openrouter\ClaudeSonnetClient;
use Corrai\Llm\Openrouter\LlmClientFactory;
use Corrai\Model\BaseAssessment;
use Corrai\Model\SubmissionFile;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\WSException;
use Throwable;

class Task2Correcting extends PathQueueItemTask
{
    protected function process(object $queue_item_data, string $s3_path): void
    {
        $file = $this->loadFile($s3_path);
        if ($file instanceof SubmissionFile) {
            $this->correctSubmission($file);
        }
    }

    public function correctSubmission(SubmissionFile $file): void
    {
        if ($file->type !== 'submission') {
            return;
        }

        try {
            $assessment = $this->loadAssessment($file);
            $store = ObjectStore::getInstance();
            $errorsKey = $file->foundErrorsKey();
            if (!$store->exists($errorsKey)) {
                throw new WSException('Transcription is missing for this file', 400);
            }
            $transcription = $store->getContents($errorsKey);
            $correction = $this->correct($assessment, $transcription, $this->languageName($file));
            $store->putContents(
                $file->markupAnnotationsKey(),
                $correction,
                'text/plain; charset=utf-8'
            );
            $file->status = 'correction_ready';
            $file->saveAttributes();
            $file->appendEvent('Correction written');
            RedisQueue::getInstance()->enqueueFile($file->id, Task3Annotating::class);
        } catch (Throwable $th) {
            $this->failCorrection($file, $th);
        }
    }
    private function correct(BaseAssessment $assessment, string $transcription, string $languageName): string
    {
        $instructionText = $assessment->instructionFilesText();
        $request = new ClaudeSonnetClient();
        $request->set_system_content(
            'You are a physics professor and you have to correct the following submission. '
            . 'Check formulas, units, reasoning, and numerical results. '
            . 'Respond with a textual correction including the mark and the appreciation. '
            . 'Use the language ' . $languageName
            . ' with the following instructions bellow. '
            . $instructionText
        );
        $request->add_text("Submission transcription (LaTeX):\n" . $transcription);
        return $request->call_text();
    }
}
