<?php

namespace Corrai\Subject\Physics;

use Corrai\Llm\Openrouter\ClaudeSonnetClient;
use Corrai\Llm\Openrouter\LlmClientFactory;
use Corrai\Model\BaseAssessment;
use Corrai\Model\File as ModelFile;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\WSException;
use Throwable;

class Task1Transcribing extends PathQueueItemTask
{
    protected function process(object $queue_item_data, string $s3_path): void
    {
        $file = $this->loadFile($s3_path);
        if ($file instanceof ModelFile) {
            $this->transcribeSubmission($file);
        }
    }

    public function transcribeSubmission(ModelFile $file): void
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
            'You are a careful transcription assistant for a physics assessment. '
            . 'Transcribe the submitted paper into LaTeX, including formulas, units, and diagrams described in text. '
            . 'Return only the LaTeX transcription with no extra commentary.'
        );
        $request->add_file($tmpPath, $filename);
        $request->add_text('Transcribe this physics submission into LaTeX.');
        return $request->call_text();
    }
}
