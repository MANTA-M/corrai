<?php

namespace Corrai\Model\Task;

use Corrai\Model\BaseAssessment;
use Corrai\Model\BaseFile;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\WSException;
use Throwable;

abstract class PathQueueItemTask
{
    public function process_task(object $queue_item_data): void
    {
        $s3_path = $queue_item_data->path ?? null;
        if ($s3_path === null || $s3_path === '') {
            error_log('S3 path is missing');
            return;
        }

        $store = ObjectStore::getInstance();
        if (!$store->exists($s3_path)) {
            error_log('S3 object not found: ' . $s3_path);
            return;
        }

        error_log('Processing task: ' . $queue_item_data->task_id);
        $this->process($queue_item_data, $s3_path);
        error_log('Task processed: ' . $queue_item_data->task_id);
    }

    abstract protected function process(object $queue_item_data, string $s3_path): void;

    protected function loadFile(string $s3_path): BaseFile
    {
        $path = rtrim($s3_path, '/');
        $contentName = '/' . ObjectStore::CONTENT_FILE;
        if (str_ends_with($path, $contentName)) {
            $path = substr($path, 0, -strlen($contentName));
        }

        return BaseFile::from_hash(basename($path));
    }

    protected function loadAssessment(BaseFile $file): BaseAssessment
    {
        return BaseAssessment::from_hash($file->assessment_id);
    }

    /**
     * @return array{id: string, name: string, type: string, student: ?string}
     */
    protected function firstSolutionFile(BaseAssessment $assessment): array
    {
        foreach ($assessment->list_files() as $file) {
            if (($file['type'] ?? '') === 'solution') {
                return $file;
            }
        }
        throw new WSException('No corrigé file on this assessment', 400);
    }

    protected function failCorrection(BaseFile $file, Throwable $error): void
    {
        error_log($error->getMessage());
        try {
            $file->appendEvent('Correction failed');
            $file->status = 'error';
            $file->saveAttributes();
        } catch (Throwable $ignore) {
        }
    }

    protected function languageName(BaseFile $file): string
    {
        return $this->loadAssessment($file)->correctionLanguageName();
    }
}
