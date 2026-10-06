<?php

namespace Corrai\Model\Task;

use Corrai\Model\BaseAssessment;
use Corrai\Model\InputFile;
use Corrai\Stream\AssessmentEventFeed;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\SSEvent;
use Corrai\Utils\Http\WSException;
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
        $fileId = $queue_item_data->file_id ?? $this->resolveFileId($s3_path);
        $startState = $fileId !== null ? $this->reportStart($fileId) : null;
        try {
            $this->process($queue_item_data, $s3_path);
        } finally {
            if ($fileId !== null) {
                $this->reportEnd($fileId, $startState);
            }
        }
        error_log('Task processed: ' . $queue_item_data->task_id);
    }

    abstract protected function process(object $queue_item_data, string $s3_path): void;

    public function resolveFileId(string $s3_path): ?string
    {
        $path = rtrim($s3_path, '/');
        $contentName = '/' . ObjectStore::CONTENT_FILE;
        if (str_ends_with($path, $contentName)) {
            $path = substr($path, 0, -strlen($contentName));
        }

        $fileId = basename($path);
        return $fileId !== '' ? $fileId : null;
    }

    /**
     * Report task start with loading: true.
     *
     * @return array{file: array<string, mixed>, student: array<string, mixed>|null, stats: array<string, mixed>}|null
     */
    public function reportStart(string $fileId): ?array
    {
        try {
            $file = InputFile::from_hash($fileId);
            $assessment = BaseAssessment::from_hash($file->assessment_id);
            $before = AssessmentEventFeed::state($assessment, $file, false);
            $start = AssessmentEventFeed::state($assessment, $file, true);
            $this->publishDelta($assessment, $file, $before, $start);
            return $start;
        } catch (Throwable $e) {
            error_log('Failed to report task start for file ' . $fileId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Report task end with loading: false and updated attributes.
     *
     * @param array{file?: array<string, mixed>, student?: array<string, mixed>|null, stats?: array<string, mixed>}|null $startState
     */
    public function reportEnd(string $fileId, ?array $startState): void
    {
        try {
            $file = InputFile::from_hash($fileId);
            $assessment = BaseAssessment::from_hash($file->assessment_id);
            $end = AssessmentEventFeed::state($assessment, $file, false);
            $previousStudentId = is_array($startState) && is_array($startState['student'] ?? null)
                ? (string) ($startState['student']['id'] ?? '')
                : null;
            $this->publishDelta($assessment, $file, $startState, $end, $previousStudentId);
        } catch (Throwable $e) {
            error_log('Failed to report task end for file ' . $fileId . ': ' . $e->getMessage());
        }
    }

    /**
     * Publish the fields that differ from $before to Nchan channels.
     *
     * @param array{file?: array<string, mixed>, student?: array<string, mixed>|null, stats?: array<string, mixed>}|null $before
     * @param array{file: array<string, mixed>, student: array<string, mixed>|null, stats: array<string, mixed>} $after
     */
    public function publishDelta(
        BaseAssessment $assessment,
        InputFile $file,
        ?array $before,
        array $after,
        ?string $previousStudentId = null
    ): void {
        try {
            $assessmentEvent = AssessmentEventFeed::delta('assessment', $before, $after);
            if ($assessmentEvent !== null) {
                SSEvent::publish(SSEvent::assessmentChannel((string) $assessment->id), $assessmentEvent);
            }
            $studentId = trim((string) ($file->student ?? ''));
            if ($studentId !== '') {
                $studentEvent = AssessmentEventFeed::delta('student', $before, $after);
                if ($studentEvent !== null) {
                    SSEvent::publish(SSEvent::studentChannel($studentId), $studentEvent);
                }
            }
            if ($previousStudentId !== null && $previousStudentId !== '' && $previousStudentId !== $studentId) {
                $prevEvent = AssessmentEventFeed::delta('student', $before, $after);
                if ($prevEvent !== null) {
                    SSEvent::publish(SSEvent::studentChannel($previousStudentId), $prevEvent);
                }
            }
        } catch (Throwable $e) {
            error_log('SSEvent publish failed for file ' . $file->id . ': ' . $e->getMessage());
        }
    }

    protected function loadFile(string $s3_path): InputFile
    {
        return InputFile::from_path($s3_path);
    }

    protected function loadAssessment(InputFile $file): BaseAssessment
    {
        return BaseAssessment::from_hash($file->assessment_id);
    }

    protected function failCorrection(InputFile $file, Throwable $error): void
    {
        error_log($error->getMessage());
        try {
            $file->appendEvent('Correction failed');
            $file->status = 'error';
            $file->saveAttributes();
        } catch (Throwable $ignore) {
        }
    }

    protected function languageName(InputFile $file): string
    {
        return $this->loadAssessment($file)->correctionLanguageName();
    }
}
