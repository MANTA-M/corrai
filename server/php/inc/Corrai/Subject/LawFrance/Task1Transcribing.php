<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Llm\Openrouter\ClaudeSonnetClient;
use Corrai\Llm\Openrouter\LlmClientFactory;
use Corrai\Model\BaseAssessment;
use Corrai\Model\SubmissionFile;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\WSException;
use Throwable;

class Task1Transcribing extends PathQueueItemTask
{
    protected function process(object $queue_item_data, string $s3_path): void
    {
        $file = $this->loadFile($s3_path);
        File::queueThumbnail($file);
        if ($file instanceof SubmissionFile) {
            $this->transcribeSubmission($file);
        }
    }

    public function transcribeSubmission(SubmissionFile $file): void
    {
        if ($file->type !== 'submission') {
            return;
        }

        $copyPath = null;
        try {
            $store = ObjectStore::getInstance();
            $copyPath = $store->downloadToTemp($file->contentKey());
            $transcription = $this->transcribe($copyPath, $file->name);
            $store->putContents(
                $file->foundErrorsKey(),
                $transcription,
                'text/plain; charset=utf-8'
            );
            $file->status = 'transcribed';
            $file->saveAttributes();
            $file->appendEvent('Transcription written');
            RedisQueue::getInstance()->enqueueFile($file->id, Task2Correcting::class);
        } catch (Throwable $th) {
            $this->failCorrection($file, $th);
        } finally {
            if ($copyPath !== null) {
                @unlink($copyPath);
            }
        }
    }
    private function transcribe(string $tmpPath, string $filename): string
    {
        $request = new ClaudeSonnetClient();
        $request->set_system_content(
            'You are a careful transcription assistant for a law assessment. '
            . 'Follow the user instruction exactly. '
            . 'Return only the transcription, the unreadable marks, and the calligraphy score.'
        );
        $request->add_file($tmpPath, $filename);
        $request->add_text(\Corrai\Subject\Dictation\File::TRANSCRIPTION_INSTRUCTION);
        return $request->call_text();
    }
}
