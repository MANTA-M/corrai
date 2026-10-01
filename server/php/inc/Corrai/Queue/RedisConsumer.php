<?php

namespace Corrai\Queue;

use Corrai\Model\BaseExam;
use Corrai\Model\File;
use Corrai\Subject\ExamFactory;
use Corrai\Utils\ObjectStore;
use Exception;

/**
 * Processes file-status tickets from the Redis work queue.
 */
class RedisConsumer
{
    /**
     * Load file and exam from S3 attributes, then dispatch on_{status}.
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
        $examId = $parsed['exam_id'];

        $fileAttrKey = ObjectStore::examFileAttrKey($schoolId, $userId, $examId, $fileId);
        if (!$store->exists($fileAttrKey)) {
            throw new Exception("File with hash $fileId does not exist");
        }
        $fileLoaded = $store->getJson($fileAttrKey);

        $examAttrKey = ObjectStore::examAttrKey($schoolId, $userId, $examId);
        if (!$store->exists($examAttrKey)) {
            throw new Exception("Exam with hash $examId does not exist");
        }
        $examLoaded = $store->getJson($examAttrKey);

        $exam = ExamFactory::fromAttributes($examLoaded['data'], $schoolId, $userId, $examId);
        $file = $exam->fileFromAttributes($fileLoaded['data'], $fileId, $fileLoaded['etag']);

        self::dispatch($exam, $file);
    }

    /**
     * Call file method on_{status}, then exam method, or log when missing.
     */
    public static function dispatch(BaseExam $exam, File $file): void
    {
        $status = $file->status;
        $method = 'on_' . $status;
        if (method_exists($file, $method)) {
            $file->$method();
            return;
        }
        if (method_exists($exam, $method)) {
            $exam->$method($file);
            return;
        }

        error_log(sprintf(
            'No method %s on %s or %s for file %s (status=%s)',
            $method,
            $file::class,
            $exam::class,
            $file->id ?? '',
            $status
        ));
    }
}
