<?php

namespace Corrai\Utils\Store;

use Aws\CommandInterface;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Corrai\Utils\Http\JsonUtils;
use Exception;

/**
 * Thin S3 wrapper pointed at SeaweedFS (or any S3-compatible endpoint).
 *
 * Tree layout:
 *   schools/<schoolId>/attributes.json
 *   schools/<schoolId>/teachers/<teacherId>/attributes.json
 *   schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/attributes.json
 *   schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/events/<eventId>.json
 *   schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/subject/<fileId>/attributes.json
 *   schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/subject/<fileId>/content
 *   schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/subject/compile_subject
 *   schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/students/<studentId>/attributes.json
 *   schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/students/<studentId>/<fileId>/attributes.json
 *   schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/students/<studentId>/<fileId>/content
 *   schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/students/<studentId>/<fileId>/events/<eventId>.json
 *   schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/unclassified/<fileId>/attributes.json
 *   schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/unclassified/<fileId>/content
 *   schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/blobs/<fileId>
 *   _id/{hash}  — pointer to a node prefix, or to a blobs/<fileId> object
 *
 * Input files keep a directory: subject material and instructions under subject/,
 * student copies under students/<studentId>/<fileId>/, copies not yet assigned
 * under unclassified/. Solution, correction, debug and files with no role are a
 * single object under blobs/. The legacy files/<fileId>/ prefix is still recognized
 * so older objects stay readable. Older solution, correction and debug directories
 * stay readable too.
 *
 * Legacy CSV keys (school.csv, user.csv, exam.csv, files.csv) remain for migration only.
 */
class ObjectStore
{
    public const ATTR_FILE = 'attributes.json';
    public const CONTENT_FILE = 'content';
    public const OCR_RESULT_FILE = 'ocr_result.json';
    public const FOUND_ERRORS_FILE = 'found_errors.json';
    public const MARKUP_ANNOTATIONS_FILE = 'markup_annotations.php';
    public const THUMBNAIL_FILE = 'thumbnail';
    public const SCHEMA = 1;

    public const DEFAULT_CONNECT_TIMEOUT = 2.0;
    public const DEFAULT_TIMEOUT = 10.0;
    public const DEFAULT_RETRIES = 3;
    public const DEFAULT_SLOW_THRESHOLD_MS = 500.0;

    private static ?self $instance = null;
    /** @var (\Closure(string, string, float, CommandInterface): void)|null */
    private static ?\Closure $logCallback = null;

    private S3Client $client;
    private string $bucket;
    private bool $bucketReady = false;

    public function __construct(?S3Client $client = null, ?string $bucket = null)
    {
        if ($client !== null) {
            $this->client = $client;
            $this->bucket = $bucket ?? self::envValue('S3_BUCKET', 'corrai');
            self::attachTimingMiddleware($this->client);
            return;
        }

        $endpoint = self::envValue('S3_ENDPOINT', 'http://seaweedfs:8333');
        $region = self::envValue('S3_REGION', 'us-east-1');
        $accessKey = self::envValue('S3_ACCESS_KEY');
        $secretKey = self::envValue('S3_SECRET_KEY');
        $this->bucket = $bucket ?? self::envValue('S3_BUCKET', 'corrai');

        if ($endpoint === '' || $accessKey === '' || $secretKey === '') {
            throw new Exception('S3_ENDPOINT, S3_ACCESS_KEY and S3_SECRET_KEY must be configured');
        }

        $connectTimeout = (float) self::envValue('S3_CONNECT_TIMEOUT', (string) self::DEFAULT_CONNECT_TIMEOUT);
        $timeout = (float) self::envValue('S3_TIMEOUT', (string) self::DEFAULT_TIMEOUT);
        $retries = (int) self::envValue('S3_RETRIES', (string) self::DEFAULT_RETRIES);

        $this->client = new S3Client([
            'version' => 'latest',
            'region' => $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
            'http' => [
                'connect_timeout' => $connectTimeout,
                'timeout' => $timeout,
            ],
            'retries' => $retries,
        ]);

        self::attachTimingMiddleware($this->client);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function getClient(): S3Client
    {
        return $this->client;
    }

    public static function setLogCallback(?\Closure $callback): void
    {
        self::$logCallback = $callback;
    }

    public static function getSlowThresholdMs(): float
    {
        $raw = self::envValue('S3_SLOW_THRESHOLD_MS');
        if ($raw !== '' && is_numeric($raw)) {
            return (float) $raw;
        }
        return self::DEFAULT_SLOW_THRESHOLD_MS;
    }

    public static function attachTimingMiddleware(S3Client $client): void
    {
        $client->getHandlerList()->remove('s3-timing');
        $client->getHandlerList()->appendInit(
            static function (callable $handler) {
                return static function (CommandInterface $cmd, $req = null) use ($handler) {
                    $start = microtime(true);
                    return $handler($cmd, $req)->then(
                        static function ($result) use ($cmd, $start) {
                            self::logSlowRequest($cmd, $start);
                            return $result;
                        },
                        static function ($reason) use ($cmd, $start) {
                            self::logSlowRequest($cmd, $start);
                            throw $reason;
                        }
                    );
                };
            },
            's3-timing'
        );
    }

    public static function logSlowRequest(CommandInterface $cmd, float $start): void
    {
        $durationMs = (microtime(true) - $start) * 1000;
        $thresholdMs = self::getSlowThresholdMs();
        if ($durationMs > $thresholdMs) {
            $key = self::extractCommandKey($cmd);
            $message = sprintf(
                'S3 slow request (%s): key=%s duration=%.1f ms',
                $cmd->getName(),
                $key !== '' ? $key : '(none)',
                $durationMs
            );
            if (self::$logCallback !== null) {
                (self::$logCallback)($message, $key, $durationMs, $cmd);
            }
            error_log($message);
        }
    }

    public static function extractCommandKey(CommandInterface $cmd): string
    {
        if (isset($cmd['Key']) && is_string($cmd['Key']) && $cmd['Key'] !== '') {
            return $cmd['Key'];
        }
        if (isset($cmd['Prefix']) && is_string($cmd['Prefix']) && $cmd['Prefix'] !== '') {
            return $cmd['Prefix'];
        }
        if (isset($cmd['Delete']['Objects']) && is_array($cmd['Delete']['Objects'])) {
            $keys = [];
            foreach ($cmd['Delete']['Objects'] as $obj) {
                if (isset($obj['Key']) && is_string($obj['Key']) && $obj['Key'] !== '') {
                    $keys[] = $obj['Key'];
                }
            }
            if (!empty($keys)) {
                if (count($keys) > 5) {
                    return implode(', ', array_slice($keys, 0, 5)) . '... (' . count($keys) . ' objects)';
                }
                return implode(', ', $keys);
            }
        }
        if (isset($cmd['Bucket']) && is_string($cmd['Bucket']) && $cmd['Bucket'] !== '') {
            return $cmd['Bucket'];
        }
        return '';
    }

    /**
     * Read a config value from $_ENV, $_SERVER, or the process environment.
     * PHP-FPM often leaves $_ENV empty even when Docker injects the variable.
     */
    private static function envValue(string $key, string $default = ''): string
    {
        foreach ([$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return $default;
    }

    // -------------------------------------------------------------------------
    // Path builders (JSON tree)
    // -------------------------------------------------------------------------

    public static function idIndexKey(string $hash): string
    {
        return '_id/' . $hash;
    }

    public static function schoolsRootPrefix(): string
    {
        return 'schools/';
    }

    public static function schoolPrefix(string $schoolId): string
    {
        return 'schools/' . $schoolId . '/';
    }

    public static function schoolAttrKey(string $schoolId): string
    {
        return self::schoolPrefix($schoolId) . self::ATTR_FILE;
    }

    public static function teachersPrefix(string $schoolId): string
    {
        return self::schoolPrefix($schoolId) . 'teachers/';
    }

    public static function teacherPrefix(string $schoolId, string $teacherId): string
    {
        return self::teachersPrefix($schoolId) . $teacherId . '/';
    }

    public static function teacherAttrKey(string $schoolId, string $teacherId): string
    {
        return self::teacherPrefix($schoolId, $teacherId) . self::ATTR_FILE;
    }

    public static function assessmentsPrefix(string $schoolId, string $teacherId): string
    {
        return self::teacherPrefix($schoolId, $teacherId) . 'assessments/';
    }

    public static function assessmentPrefix(string $schoolId, string $teacherId, string $assessmentId): string
    {
        return self::assessmentsPrefix($schoolId, $teacherId) . $assessmentId . '/';
    }

    public static function assessmentAttrKey(string $schoolId, string $teacherId, string $assessmentId): string
    {
        return self::assessmentPrefix($schoolId, $teacherId, $assessmentId) . self::ATTR_FILE;
    }

    public static function assessmentEventsPrefix(string $schoolId, string $teacherId, string $assessmentId): string
    {
        return self::assessmentPrefix($schoolId, $teacherId, $assessmentId) . 'events/';
    }

    public static function assessmentEventKey(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $eventId
    ): string {
        return self::assessmentEventsPrefix($schoolId, $teacherId, $assessmentId) . $eventId . '.json';
    }

    /**
     * Legacy flat file area. New writes use subject/, students/, or unclassified/.
     */
    public static function assessmentFilesPrefix(string $schoolId, string $teacherId, string $assessmentId): string
    {
        return self::assessmentPrefix($schoolId, $teacherId, $assessmentId) . 'files/';
    }

    public static function assessmentSubjectFilesPrefix(string $schoolId, string $teacherId, string $assessmentId): string
    {
        return self::assessmentPrefix($schoolId, $teacherId, $assessmentId) . 'subject/';
    }

    /**
     * Compiled OCR (or raw) text of every subject file, in creation order.
     */
    public static function assessmentSubjectCompileKey(string $schoolId, string $teacherId, string $assessmentId): string
    {
        return self::assessmentSubjectFilesPrefix($schoolId, $teacherId, $assessmentId) . 'compile_subject';
    }

    /**
     * LLM correction grid built from the compiled subject.
     */
    public static function assessmentSubjectCorrectionGridKey(string $schoolId, string $teacherId, string $assessmentId): string
    {
        return self::assessmentSubjectFilesPrefix($schoolId, $teacherId, $assessmentId) . 'correction_grid.json';
    }

    public static function assessmentUnclassifiedFilesPrefix(
        string $schoolId,
        string $teacherId,
        string $assessmentId
    ): string {
        return self::assessmentPrefix($schoolId, $teacherId, $assessmentId) . 'unclassified/';
    }

    /**
     * Where a file lives given its type and optional student assignment.
     *
     * A student id stores the copy under that student. Subject material
     * (subject and instructions, plus legacy solution directories) lives under
     * subject/. A submission that has no student yet stays under unclassified/.
     * New solution, correction and debug objects use assessmentBlobKey().
     *
     * @return 'student'|'subject'|'unclassified'
     */
    public static function assessmentFileArea(string $type, ?string $studentId): string
    {
        if ($studentId !== null && trim($studentId) !== '') {
            return 'student';
        }
        if (in_array($type, ['subject', 'solution', 'instructions'], true)) {
            return 'subject';
        }
        return 'unclassified';
    }

    public static function assessmentFilePrefix(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $fileId,
        string $type = '',
        ?string $studentId = null
    ): string {
        $area = self::assessmentFileArea($type, $studentId);
        if ($area === 'student') {
            return self::assessmentStudentPrefix($schoolId, $teacherId, $assessmentId, trim((string) $studentId))
                . $fileId . '/';
        }
        if ($area === 'subject') {
            return self::assessmentSubjectFilesPrefix($schoolId, $teacherId, $assessmentId) . $fileId . '/';
        }
        return self::assessmentUnclassifiedFilesPrefix($schoolId, $teacherId, $assessmentId) . $fileId . '/';
    }

    /**
     * Single-object files (solution, correction, debug, or no role yet).
     */
    public static function assessmentBlobsPrefix(string $schoolId, string $teacherId, string $assessmentId): string
    {
        return self::assessmentPrefix($schoolId, $teacherId, $assessmentId) . 'blobs/';
    }

    public static function assessmentBlobKey(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $fileId
    ): string {
        return self::assessmentBlobsPrefix($schoolId, $teacherId, $assessmentId) . $fileId;
    }

    public static function assessmentFileAttrKey(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $fileId,
        string $type = '',
        ?string $studentId = null
    ): string {
        return self::assessmentFilePrefix($schoolId, $teacherId, $assessmentId, $fileId, $type, $studentId)
            . self::ATTR_FILE;
    }

    public static function assessmentFileContentKey(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $fileId,
        string $type = '',
        ?string $studentId = null
    ): string {
        return self::assessmentFilePrefix($schoolId, $teacherId, $assessmentId, $fileId, $type, $studentId)
            . self::CONTENT_FILE;
    }

    public static function assessmentFileOcrResultKey(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $fileId,
        string $type = '',
        ?string $studentId = null
    ): string {
        return self::assessmentFilePrefix($schoolId, $teacherId, $assessmentId, $fileId, $type, $studentId)
            . self::OCR_RESULT_FILE;
    }

    public static function assessmentFileFoundErrorsKey(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $fileId,
        string $type = '',
        ?string $studentId = null
    ): string {
        return self::assessmentFilePrefix($schoolId, $teacherId, $assessmentId, $fileId, $type, $studentId)
            . self::FOUND_ERRORS_FILE;
    }

    public static function assessmentFileMarkupAnnotationsKey(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $fileId,
        string $type = '',
        ?string $studentId = null
    ): string {
        return self::assessmentFilePrefix($schoolId, $teacherId, $assessmentId, $fileId, $type, $studentId)
            . self::MARKUP_ANNOTATIONS_FILE;
    }

    public static function assessmentFileThumbnailKey(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $fileId,
        string $type = '',
        ?string $studentId = null
    ): string {
        return self::assessmentFilePrefix($schoolId, $teacherId, $assessmentId, $fileId, $type, $studentId)
            . self::THUMBNAIL_FILE;
    }

    public static function assessmentFileEventsPrefix(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $fileId,
        string $type = '',
        ?string $studentId = null
    ): string {
        return self::assessmentFilePrefix($schoolId, $teacherId, $assessmentId, $fileId, $type, $studentId)
            . 'events/';
    }

    public static function assessmentFileEventKey(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $fileId,
        string $eventId,
        string $type = '',
        ?string $studentId = null
    ): string {
        return self::assessmentFileEventsPrefix(
            $schoolId,
            $teacherId,
            $assessmentId,
            $fileId,
            $type,
            $studentId
        ) . $eventId . '.json';
    }

    public static function assessmentStudentsPrefix(string $schoolId, string $teacherId, string $assessmentId): string
    {
        return self::assessmentPrefix($schoolId, $teacherId, $assessmentId) . 'students/';
    }

    public static function assessmentStudentPrefix(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $studentId
    ): string {
        return self::assessmentStudentsPrefix($schoolId, $teacherId, $assessmentId) . $studentId . '/';
    }

    public static function assessmentStudentAttrKey(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $studentId
    ): string {
        return self::assessmentStudentPrefix($schoolId, $teacherId, $assessmentId, $studentId) . self::ATTR_FILE;
    }

    public static function assessmentStudentCorrectionKey(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $studentId
    ): string {
        return self::assessmentStudentPrefix($schoolId, $teacherId, $assessmentId, $studentId) . 'correction.png';
    }

    public static function assessmentStudentCompiledSubmissionKey(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $studentId
    ): string {
        return self::assessmentStudentPrefix($schoolId, $teacherId, $assessmentId, $studentId) . 'compiled_submission.json';
    }

    /**
     * Parse a schools/... node prefix into path segments.
     *
     * @return array{kind: string, school_id: string, teacher_id?: string, assessment_id?: string, file_id?: string, student_id?: string}
     */
    public static function parseNodePrefix(string $prefix): array
    {
        $parts = explode('/', trim($prefix, '/'));
        if (count($parts) < 2 || $parts[0] !== 'schools') {
            throw new Exception('Invalid node prefix: ' . $prefix);
        }

        $schoolId = $parts[1];
        if (count($parts) === 2) {
            return ['kind' => 'school', 'school_id' => $schoolId];
        }

        if (($parts[2] ?? '') !== 'teachers' || !isset($parts[3])) {
            throw new Exception('Invalid teacher path in prefix: ' . $prefix);
        }
        $teacherId = $parts[3];
        if (count($parts) === 4) {
            return ['kind' => 'teacher', 'school_id' => $schoolId, 'teacher_id' => $teacherId];
        }

        if (($parts[4] ?? '') !== 'assessments' || !isset($parts[5])) {
            throw new Exception('Invalid assessment path in prefix: ' . $prefix);
        }
        $assessmentId = $parts[5];
        if (count($parts) === 6) {
            return [
                'kind' => 'assessment',
                'school_id' => $schoolId,
                'teacher_id' => $teacherId,
                'assessment_id' => $assessmentId,
            ];
        }

        $area = $parts[6] ?? '';
        if ($area === 'blobs' && isset($parts[7]) && !isset($parts[8])) {
            return [
                'kind' => 'blob',
                'school_id' => $schoolId,
                'teacher_id' => $teacherId,
                'assessment_id' => $assessmentId,
                'file_id' => $parts[7],
                'area' => 'blobs',
            ];
        }

        if (in_array($area, ['subject', 'unclassified', 'files'], true) && isset($parts[7]) && !isset($parts[8])) {
            return [
                'kind' => 'file',
                'school_id' => $schoolId,
                'teacher_id' => $teacherId,
                'assessment_id' => $assessmentId,
                'file_id' => $parts[7],
                'area' => $area === 'files' ? 'unclassified' : $area,
            ];
        }

        if ($area === 'students' && isset($parts[7])) {
            if (!isset($parts[8])) {
                return [
                    'kind' => 'student',
                    'school_id' => $schoolId,
                    'teacher_id' => $teacherId,
                    'assessment_id' => $assessmentId,
                    'student_id' => $parts[7],
                ];
            }
            if (!isset($parts[9])) {
                if ($parts[8] === 'correction.png' || str_contains($parts[8], '.')) {
                    return [
                        'kind' => 'blob',
                        'school_id' => $schoolId,
                        'teacher_id' => $teacherId,
                        'assessment_id' => $assessmentId,
                        'student_id' => $parts[7],
                        'file_id' => $parts[8],
                        'area' => 'student',
                    ];
                }
                return [
                    'kind' => 'file',
                    'school_id' => $schoolId,
                    'teacher_id' => $teacherId,
                    'assessment_id' => $assessmentId,
                    'student_id' => $parts[7],
                    'file_id' => $parts[8],
                    'area' => 'student',
                ];
            }
        }

        throw new Exception('Unrecognized node prefix: ' . $prefix);
    }

    // -------------------------------------------------------------------------
    // Legacy CSV path builders (migration only)
    // -------------------------------------------------------------------------

    public static function legacySchoolPrefix(string $schoolId): string
    {
        return $schoolId . '/';
    }

    public static function legacySchoolCsvKey(string $schoolId): string
    {
        return $schoolId . '/school.csv';
    }

    public static function legacyUserPrefix(string $schoolId, string $userId): string
    {
        return $schoolId . '/' . $userId . '/';
    }

    public static function legacyUserCsvKey(string $schoolId, string $userId): string
    {
        return $schoolId . '/' . $userId . '/user.csv';
    }

    public static function legacyAssessmentPrefix(string $schoolId, string $userId, string $assessmentId): string
    {
        return $schoolId . '/' . $userId . '/' . $assessmentId . '/';
    }

    public static function legacyAssessmentCsvKey(string $schoolId, string $userId, string $assessmentId): string
    {
        return $schoolId . '/' . $userId . '/' . $assessmentId . '/exam.csv';
    }

    public static function legacyAssessmentFilesCsvKey(string $schoolId, string $userId, string $assessmentId): string
    {
        return self::legacyAssessmentPrefix($schoolId, $userId, $assessmentId) . 'files.csv';
    }

    public static function legacyAssessmentFilesPrefix(string $schoolId, string $userId, string $assessmentId): string
    {
        return self::legacyAssessmentPrefix($schoolId, $userId, $assessmentId) . 'files/';
    }

    public static function legacyAssessmentFileKey(
        string $schoolId,
        string $userId,
        string $assessmentId,
        string $filename
    ): string {
        return self::legacyAssessmentFilesPrefix($schoolId, $userId, $assessmentId) . $filename;
    }

    public static function legacyAssessmentUnassignedPrefix(string $schoolId, string $userId, string $assessmentId): string
    {
        return self::legacyAssessmentPrefix($schoolId, $userId, $assessmentId) . 'unassigned/';
    }

    public static function legacyAssessmentSubjectPrefix(string $schoolId, string $userId, string $assessmentId): string
    {
        return self::legacyAssessmentPrefix($schoolId, $userId, $assessmentId) . 'subject/';
    }

    // -------------------------------------------------------------------------
    // Bucket / object operations
    // -------------------------------------------------------------------------

    public function ensureBucket(): void
    {
        if ($this->bucketReady) {
            return;
        }

        try {
            $this->client->headBucket(['Bucket' => $this->bucket]);
        } catch (S3Exception $e) {
            $status = $e->getStatusCode();
            if ($status === 404 || $status === 403) {
                $this->client->createBucket(['Bucket' => $this->bucket]);
            } else {
                throw $e;
            }
        }

        $this->bucketReady = true;
    }

    /**
     * Upload a local file to the object store.
     *
     * @return string|null New ETag when the store returns one
     */
    public function put(
        string $key,
        string $localPath,
        ?string $contentType = null,
        ?string $ifMatch = null,
        ?array $metadata = null
    ): ?string {
        $this->ensureBucket();

        $params = [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'SourceFile' => $localPath,
        ];
        if ($contentType !== null && $contentType !== '') {
            $params['ContentType'] = $contentType;
        }
        if ($metadata !== null && $metadata !== []) {
            $params['Metadata'] = $metadata;
        }
        self::applyIfMatch($params, $ifMatch);

        try {
            $result = $this->client->putObject($params);
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 412) {
                throw new StoreConflictException("Conflict writing $key", 412, $e);
            }
            throw $e;
        }

        return self::normalizeEtag($result['ETag'] ?? null);
    }

    /**
     * Upload an in-memory string/body to the object store.
     *
     * @return string|null New ETag when the store returns one
     */
    public function putContents(
        string $key,
        string $body,
        ?string $contentType = null,
        ?string $ifMatch = null,
        ?array $metadata = null
    ): ?string {
        $this->ensureBucket();

        $params = [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $body,
        ];
        if ($contentType !== null && $contentType !== '') {
            $params['ContentType'] = $contentType;
        }
        if ($metadata !== null && $metadata !== []) {
            $params['Metadata'] = $metadata;
        }
        self::applyIfMatch($params, $ifMatch);

        try {
            $result = $this->client->putObject($params);
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 412) {
                throw new StoreConflictException("Conflict writing $key", 412, $e);
            }
            throw $e;
        }

        return self::normalizeEtag($result['ETag'] ?? null);
    }

    /**
     * Put JSON attributes (schema stamped).
     *
     * @param array<string, mixed> $data
     * @return string|null New ETag
     */
    public function putJson(string $key, array $data, ?string $ifMatch = null): ?string
    {
        if (!isset($data['schema'])) {
            $data['schema'] = self::SCHEMA;
        }
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new Exception('Failed to encode JSON for ' . $key);
        }
        return $this->putContents($key, $body, 'application/json', $ifMatch);
    }

    /**
     * @return array{data: array, etag: string|null}
     */
    public function getJson(string $key): array
    {
        $object = $this->get($key);
        $body = $object['Body'];
        $raw = is_string($body) ? $body : (string) $body;
        $data = JsonUtils::decodeArray($raw);
        if (!is_array($data)) {
            throw new Exception("Invalid JSON object at $key");
        }
        return [
            'data' => $data,
            'etag' => $object['ETag'] ?? null,
        ];
    }

    /**
     * Copy every object under $sourcePrefix to the same relative key under $destPrefix.
     */
    public function copyPrefix(string $sourcePrefix, string $destPrefix): void
    {
        $sourcePrefix = rtrim($sourcePrefix, '/') . '/';
        $destPrefix = rtrim($destPrefix, '/') . '/';
        if ($sourcePrefix === $destPrefix) {
            return;
        }

        foreach ($this->listKeys($sourcePrefix) as $key) {
            $relative = substr($key, strlen($sourcePrefix));
            if ($relative === '') {
                continue;
            }
            $this->copy($key, $destPrefix . $relative);
        }
    }

    /**
     * Copy an object to a new key, preserving metadata.
     */
    public function copy(string $sourceKey, string $destKey): void
    {
        $this->ensureBucket();

        $this->client->copyObject([
            'Bucket' => $this->bucket,
            'CopySource' => $this->bucket . '/' . $sourceKey,
            'Key' => $destKey,
            'MetadataDirective' => 'COPY',
        ]);
    }

    /**
     * Fetch an object. Returns Body, ContentType, ContentLength, ETag.
     *
     * @return array{Body: mixed, ContentType: string, ContentLength: int, ETag: string|null}
     */
    public function get(string $key): array
    {
        $this->ensureBucket();

        $result = $this->client->getObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        return [
            'Body' => $result['Body'],
            'ContentType' => $result['ContentType'] ?? 'application/octet-stream',
            'ContentLength' => (int) ($result['ContentLength'] ?? 0),
            'ETag' => self::normalizeEtag($result['ETag'] ?? null),
        ];
    }

    /**
     * Head an object for ETag / size without reading the body.
     *
     * @return array{ETag: string|null, ContentLength: int, ContentType: string, Metadata: array<string, mixed>}
     */
    public function head(string $key): array
    {
        $this->ensureBucket();

        $result = $this->client->headObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        return [
            'ETag' => self::normalizeEtag($result['ETag'] ?? null),
            'ContentLength' => (int) ($result['ContentLength'] ?? 0),
            'ContentType' => (string) ($result['ContentType'] ?? 'application/octet-stream'),
            'Metadata' => is_array($result['Metadata'] ?? null) ? $result['Metadata'] : [],
        ];
    }

    /**
     * Replace user metadata and content type. The body is unchanged.
     *
     * @param array<string, string> $metadata
     */
    public function replaceMetadata(string $key, array $metadata, ?string $contentType = null): void
    {
        $this->ensureBucket();

        $params = [
            'Bucket' => $this->bucket,
            'CopySource' => $this->bucket . '/' . $key,
            'Key' => $key,
            'MetadataDirective' => 'REPLACE',
            'Metadata' => $metadata,
        ];
        if ($contentType !== null && $contentType !== '') {
            $params['ContentType'] = $contentType;
        }
        $this->client->copyObject($params);
    }

    /**
     * Fetch an object body as a string.
     */
    public function getContents(string $key): string
    {
        $object = $this->get($key);
        $body = $object['Body'];
        return is_string($body) ? $body : (string) $body;
    }

    /**
     * Download an object to a temporary local file. Caller must unlink when done.
     */
    public function downloadToTemp(string $key): string
    {
        $object = $this->get($key);
        $tmpPath = tempnam(sys_get_temp_dir(), 'corrai_s3_');
        if ($tmpPath === false) {
            throw new Exception('Failed to create temporary file');
        }

        $body = $object['Body'];
        $contents = is_string($body) ? $body : (string) $body;
        if (file_put_contents($tmpPath, $contents) === false) {
            @unlink($tmpPath);
            throw new Exception("Failed to write temporary file for key $key");
        }

        return $tmpPath;
    }

    public function delete(string $key): void
    {
        $this->ensureBucket();

        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
    }

    /**
     * Delete all objects under a prefix (e.g. schools/abc/).
     */
    public function deletePrefix(string $prefix): void
    {
        $this->ensureBucket();

        $paginator = $this->client->getPaginator('ListObjectsV2', [
            'Bucket' => $this->bucket,
            'Prefix' => $prefix,
        ]);

        foreach ($paginator as $page) {
            $contents = $page['Contents'] ?? [];
            if (empty($contents)) {
                continue;
            }

            $objects = [];
            foreach ($contents as $item) {
                $objects[] = ['Key' => $item['Key']];
            }

            $this->client->deleteObjects([
                'Bucket' => $this->bucket,
                'Delete' => ['Objects' => $objects],
            ]);
        }

        if ($prefix !== '') {
            try {
                $this->client->deleteObject([
                    'Bucket' => $this->bucket,
                    'Key' => $prefix,
                ]);
            } catch (\Throwable $e) {
                // Ignore if prefix directory marker does not exist
            }
        }
    }

    public function exists(string $key): bool
    {
        $this->ensureBucket();

        try {
            $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            return true;
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * List full object keys under a prefix (directory placeholders excluded).
     *
     * @return string[]
     */
    public function listKeys(string $prefix = ''): array
    {
        $this->ensureBucket();

        $keys = [];
        $paginator = $this->client->getPaginator('ListObjectsV2', [
            'Bucket' => $this->bucket,
            'Prefix' => $prefix,
        ]);

        foreach ($paginator as $page) {
            foreach ($page['Contents'] ?? [] as $item) {
                $key = $item['Key'] ?? '';
                if ($key === '' || substr($key, -1) === '/') {
                    continue;
                }
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * List objects under a prefix.
     * Returns array of ['name' => basename, 'size' => int, 'created' => unix timestamp, 'key' => full key].
     */
    public function list(string $prefix): array
    {
        $this->ensureBucket();

        $files = [];
        $paginator = $this->client->getPaginator('ListObjectsV2', [
            'Bucket' => $this->bucket,
            'Prefix' => $prefix,
        ]);

        foreach ($paginator as $page) {
            foreach ($page['Contents'] ?? [] as $item) {
                $key = $item['Key'];
                // Skip "directory placeholder" keys that end with /
                if (substr($key, -1) === '/') {
                    continue;
                }

                $name = basename($key);
                $created = 0;
                if (isset($item['LastModified'])) {
                    $lm = $item['LastModified'];
                    $created = $lm instanceof \DateTimeInterface
                        ? $lm->getTimestamp()
                        : (int) strtotime((string) $lm);
                }

                $files[] = [
                    'name' => $name,
                    'size' => (int) ($item['Size'] ?? 0),
                    'created' => $created,
                    'key' => $key,
                ];
            }
        }

        return $files;
    }

    /**
     * List immediate child "directory" prefixes under $prefix using Delimiter=/.
     * Returns bare child names (without trailing slash or parent path), e.g. ["abc1234", "def5678"].
     *
     * @return string[]
     */
    public function listChildPrefixes(string $prefix = ''): array
    {
        $this->ensureBucket();

        // Normalize: empty = bucket root; otherwise ensure trailing /
        if ($prefix !== '' && substr($prefix, -1) !== '/') {
            $prefix .= '/';
        }

        $children = [];
        $continuationToken = null;

        do {
            $params = [
                'Bucket' => $this->bucket,
                'Delimiter' => '/',
            ];
            if ($prefix !== '') {
                $params['Prefix'] = $prefix;
            }
            if ($continuationToken !== null) {
                $params['ContinuationToken'] = $continuationToken;
            }

            $result = $this->client->listObjectsV2($params);

            foreach ($result['CommonPrefixes'] ?? [] as $common) {
                $full = $common['Prefix'] ?? '';
                // Strip parent prefix and trailing slash → bare child name
                $relative = $prefix !== '' ? substr($full, strlen($prefix)) : $full;
                $relative = rtrim($relative, '/');
                if ($relative !== '') {
                    $children[] = $relative;
                }
            }

            $continuationToken = !empty($result['IsTruncated'])
                ? ($result['NextContinuationToken'] ?? null)
                : null;
        } while ($continuationToken !== null);

        return $children;
    }

    /**
     * List file names stored directly under $prefix (not in subdirectories).
     *
     * @return string[]
     */
    public function listImmediateFiles(string $prefix): array
    {
        $this->ensureBucket();

        if ($prefix !== '' && substr($prefix, -1) !== '/') {
            $prefix .= '/';
        }

        $names = [];
        $continuationToken = null;

        do {
            $params = [
                'Bucket' => $this->bucket,
                'Delimiter' => '/',
            ];
            if ($prefix !== '') {
                $params['Prefix'] = $prefix;
            }
            if ($continuationToken !== null) {
                $params['ContinuationToken'] = $continuationToken;
            }

            $result = $this->client->listObjectsV2($params);

            foreach ($result['Contents'] ?? [] as $item) {
                $key = (string) ($item['Key'] ?? '');
                if ($key === '' || substr($key, -1) === '/') {
                    continue;
                }
                $relative = $prefix !== '' ? substr($key, strlen($prefix)) : $key;
                if ($relative === '' || str_contains($relative, '/')) {
                    continue;
                }
                $names[] = $relative;
            }

            $continuationToken = !empty($result['IsTruncated'])
                ? ($result['NextContinuationToken'] ?? null)
                : null;
        } while ($continuationToken !== null);

        return $names;
    }

    /**
     * Register or update the _id/{hash} pointer to a node prefix.
     */
    public function setIdPointer(string $hash, string $nodePrefix, bool $directory = true): void
    {
        $value = rtrim($nodePrefix, '/');
        if ($directory) {
            $value .= '/';
        }
        $this->putContents(self::idIndexKey($hash), $value, 'text/plain');
    }

    /**
     * Resolve _id/{hash} to the node prefix. Throws if missing.
     */
    public function resolveIdPointer(string $hash): string
    {
        $key = self::idIndexKey($hash);
        if (!$this->exists($key)) {
            throw new Exception("ID pointer for hash $hash does not exist");
        }
        $prefix = trim($this->getContents($key));
        if ($prefix === '') {
            throw new Exception("ID pointer for hash $hash is empty");
        }
        return $prefix;
    }

    /**
     * Delete the _id/{hash} pointer.
     */
    public function deleteIdPointer(string $hash): void
    {
        $key = self::idIndexKey($hash);
        if ($this->exists($key)) {
            $this->delete($key);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function applyIfMatch(array &$params, ?string $ifMatch): void
    {
        if ($ifMatch === null || $ifMatch === '') {
            return;
        }
        $quoted = '"' . trim($ifMatch, '"') . '"';
        $params['@http'] = [
            'headers' => [
                'If-Match' => $quoted,
            ],
        ];
    }

    private static function normalizeEtag(mixed $etag): ?string
    {
        if (!is_string($etag) || $etag === '') {
            return null;
        }
        return trim($etag, '"');
    }
}
