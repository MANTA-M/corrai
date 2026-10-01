<?php

namespace Corrai\Queue;

use Corrai\Model\BaseAssessment;
use Corrai\Model\File;
use Corrai\Subject\AssessmentFactory;
use Corrai\Utils\ObjectStore;
use Exception;

/**
 * Processes file-status tickets from the Redis work queue.
 */
class RedisConsumer
{
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

        $fileAttrKey = ObjectStore::assessmentFileAttrKey($schoolId, $userId, $assessmentId, $fileId);
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
