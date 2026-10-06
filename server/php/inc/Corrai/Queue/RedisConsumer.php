<?php

namespace Corrai\Queue;

use Corrai\Model\BaseAssessment;
use Corrai\Model\BaseFile;
use Corrai\Model\File;
use Corrai\Subject\AssessmentFactory;
use Corrai\Utils\Store\ObjectStore;
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
    }

    /**
     * Run a task class against the file's content object.
     */
    public static function treatFileTask(string $fileId, string $taskClass): void
    {
        $file = BaseFile::from_hash($fileId);
        self::treatPathTask($file->contentKey(), $taskClass, $fileId);
    }

    /**
     * Run a path task class enqueued after OCR on the same content path.
     */
    public static function treatPathTask(string $path, string $taskClass, ?string $fileId = null): void
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
            'file_id' => $fileId,
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
}
