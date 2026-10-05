<?php

namespace Corrai\Queue;

use Corrai\Model\BaseAssessment;
use Corrai\Model\BaseFile;
use Corrai\Model\File;
use Corrai\Stream\AssessmentEventFeed;
use Corrai\Subject\AssessmentFactory;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\SSEvent;
use Exception;
use Throwable;

/**
 * Processes file-status tickets from the Redis work queue.
 */
class RedisConsumer
{
    /**
     * Dispatch one popped ticket.
     *
     * OCR follow-ups carry a content path. Later pipeline steps carry a file id
     * and the next task class.
     *
     * @param array{file_id?: string, path?: string, task?: string} $ticket
     */
    public static function handleTicket(array $ticket): void
    {
        $fileId = self::ticketFileId($ticket);
        $before = $fileId !== null ? self::captureState($fileId) : null;

        if (isset($ticket['path'], $ticket['task'])) {
            self::treatPathTask($ticket['path'], $ticket['task']);
        } elseif (isset($ticket['file_id'], $ticket['task'])) {
            self::treatFileTask($ticket['file_id'], $ticket['task']);
        } elseif (isset($ticket['file_id'])) {
            self::treat($ticket['file_id']);
        } else {
            error_log('Missing path or task on ticket: ' . json_encode($ticket));
            return;
        }

        if ($fileId !== null) {
            self::publishForFileId($fileId, $before);
        }
    }

    /**
     * @param array{file_id?: string, path?: string, task?: string} $ticket
     */
    private static function ticketFileId(array $ticket): ?string
    {
        if (isset($ticket['file_id']) && $ticket['file_id'] !== '') {
            return $ticket['file_id'];
        }
        if (!isset($ticket['path']) || $ticket['path'] === '') {
            return null;
        }
        $path = rtrim($ticket['path'], '/');
        $contentName = '/' . ObjectStore::CONTENT_FILE;
        if (str_ends_with($path, $contentName)) {
            $path = substr($path, 0, -strlen($contentName));
        }
        $fileId = basename($path);
        return $fileId !== '' ? $fileId : null;
    }

    /**
     * @return array{file: array<string, mixed>, student: array<string, mixed>|null, stats: array<string, mixed>}|null
     */
    private static function captureState(string $fileId): ?array
    {
        try {
            $file = BaseFile::from_hash($fileId);
            $assessment = BaseAssessment::from_hash($file->assessment_id);
            return AssessmentEventFeed::state($assessment, $file);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Run a task class against the file's content object.
     */
    public static function treatFileTask(string $fileId, string $taskClass): void
    {
        $file = BaseFile::from_hash($fileId);
        self::treatPathTask($file->contentKey(), $taskClass);
    }

    /**
     * Run a path task class enqueued after OCR on the same content path.
     */
    public static function treatPathTask(string $path, string $taskClass): void
    {
        if (!str_starts_with($taskClass, 'Corrai\\') || !class_exists($taskClass)) {
            throw new Exception("Unknown task class $taskClass");
        }
        $task = new $taskClass();
        if (!method_exists($task, 'process_task')) {
            throw new Exception("Task $taskClass cannot process a queue item");
        }
        $task->process_task((object) [
            'path' => $path,
            'task' => $taskClass,
            'task_id' => $taskClass,
        ]);
    }

    /**
     * Load file and assessment from S3 attributes, then dispatch on_{status}.
     */
    public static function treat(string $fileId): void
    {
        $store = ObjectStore::getInstance();
        $prefix = $store->resolveIdPointer($fileId);
        $parsed = ObjectStore::parseNodePrefix($prefix);
        if ($parsed['kind'] !== 'file') {
            throw new Exception("Hash $fileId does not point to a file");
        }

        $schoolId = $parsed['school_id'];
        $userId = $parsed['teacher_id'];
        $assessmentId = $parsed['assessment_id'];

        $fileAttrKey = rtrim($prefix, '/') . '/' . ObjectStore::ATTR_FILE;
        if (!$store->exists($fileAttrKey)) {
            throw new Exception("File with hash $fileId does not exist");
        }
        $fileLoaded = $store->getJson($fileAttrKey);

        $assessmentAttrKey = ObjectStore::assessmentAttrKey($schoolId, $userId, $assessmentId);
        if (!$store->exists($assessmentAttrKey)) {
            throw new Exception("Assessment with hash $assessmentId does not exist");
        }
        $assessmentLoaded = $store->getJson($assessmentAttrKey);

        $assessment = AssessmentFactory::fromAttributes($assessmentLoaded['data'], $schoolId, $userId, $assessmentId);
        $file = $assessment->fileFromAttributes($fileLoaded['data'], $fileId, $fileLoaded['etag']);

        self::dispatch($assessment, $file);
    }

    /**
     * Call file method on_{status}, then assessment method, or log when missing.
     */
    public static function dispatch(BaseAssessment $assessment, File $file): void
    {
        $status = $file->status;
        $method = 'on_' . $status;
        if (method_exists($file, $method)) {
            $file->$method();
            return;
        }
        if (method_exists($assessment, $method)) {
            $assessment->$method($file);
            return;
        }

        error_log(sprintf(
            'No method %s on %s or %s for file %s (status=%s)',
            $method,
            $file::class,
            $assessment::class,
            $file->id ?? '',
            $status
        ));
    }

    /**
     * Publish the fields that differ from $before. A failed publish does not fail the ticket.
     *
     * @param array{file: array<string, mixed>, student: array<string, mixed>|null, stats: array<string, mixed>}|null $before
     */
    private static function publishForFileId(string $fileId, ?array $before): void
    {
        try {
            $file = BaseFile::from_hash($fileId);
            $assessment = BaseAssessment::from_hash($file->assessment_id);
            $after = AssessmentEventFeed::state($assessment, $file);
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
        } catch (Throwable $e) {
            error_log('SSEvent publish failed for file ' . $fileId . ': ' . $e->getMessage());
        }
    }
}
