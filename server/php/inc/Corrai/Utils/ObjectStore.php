<?php

namespace Corrai\Utils;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Exception;

/**
 * Thin S3 wrapper pointed at SeaweedFS (or any S3-compatible endpoint).
 *
 * Tree layout:
 *   schools/<schoolId>/attributes.json
 *   schools/<schoolId>/teachers/<teacherId>/attributes.json
 *   schools/<schoolId>/teachers/<teacherId>/exams/<examId>/attributes.json
 *   schools/<schoolId>/teachers/<teacherId>/exams/<examId>/files/<fileId>/attributes.json
 *   schools/<schoolId>/teachers/<teacherId>/exams/<examId>/files/<fileId>/content
 *   schools/<schoolId>/teachers/<teacherId>/exams/<examId>/files/<fileId>/ocr_result.json
 *   schools/<schoolId>/teachers/<teacherId>/exams/<examId>/files/<fileId>/found_errors.json
 *   schools/<schoolId>/teachers/<teacherId>/exams/<examId>/files/<fileId>/markup_directives.php
 *   schools/<schoolId>/teachers/<teacherId>/exams/<examId>/files/<fileId>/events/<eventId>.json
 *   schools/<schoolId>/teachers/<teacherId>/exams/<examId>/students/<studentId>/attributes.json
 *   _id/{hash}  — pointer to node prefix for O(1) from_hash
 *
 * Legacy CSV keys (school.csv, user.csv, exam.csv, files.csv) remain for migration only.
 */
class ObjectStore
{
    public const ATTR_FILE = 'attributes.json';
    public const CONTENT_FILE = 'content';
    public const OCR_RESULT_FILE = 'ocr_result.json';
    public const FOUND_ERRORS_FILE = 'found_errors.json';
    public const MARKUP_DIRECTIVES_FILE = 'markup_directives.php';
    public const SCHEMA = 1;

    private static ?self $instance = null;

    private S3Client $client;
    private string $bucket;
    private bool $bucketReady = false;

    private function __construct()
    {
        $endpoint = self::envValue('S3_ENDPOINT', 'http://seaweedfs:8333');
        $region = self::envValue('S3_REGION', 'us-east-1');
        $accessKey = self::envValue('S3_ACCESS_KEY');
        $secretKey = self::envValue('S3_SECRET_KEY');
        $this->bucket = self::envValue('S3_BUCKET', 'corrai');

        if ($endpoint === '' || $accessKey === '' || $secretKey === '') {
            throw new Exception('S3_ENDPOINT, S3_ACCESS_KEY and S3_SECRET_KEY must be configured');
        }

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
        ]);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
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

    public static function examsPrefix(string $schoolId, string $teacherId): string
    {
        return self::teacherPrefix($schoolId, $teacherId) . 'exams/';
    }

    public static function examPrefix(string $schoolId, string $teacherId, string $examId): string
    {
        return self::examsPrefix($schoolId, $teacherId) . $examId . '/';
    }

    public static function examAttrKey(string $schoolId, string $teacherId, string $examId): string
    {
        return self::examPrefix($schoolId, $teacherId, $examId) . self::ATTR_FILE;
    }

    public static function examFilesPrefix(string $schoolId, string $teacherId, string $examId): string
    {
        return self::examPrefix($schoolId, $teacherId, $examId) . 'files/';
    }

    public static function examFilePrefix(
        string $schoolId,
        string $teacherId,
        string $examId,
        string $fileId
    ): string {
        return self::examFilesPrefix($schoolId, $teacherId, $examId) . $fileId . '/';
    }

    public static function examFileAttrKey(
        string $schoolId,
        string $teacherId,
        string $examId,
        string $fileId
    ): string {
        return self::examFilePrefix($schoolId, $teacherId, $examId, $fileId) . self::ATTR_FILE;
    }

    public static function examFileContentKey(
        string $schoolId,
        string $teacherId,
        string $examId,
        string $fileId
    ): string {
        return self::examFilePrefix($schoolId, $teacherId, $examId, $fileId) . self::CONTENT_FILE;
    }

    public static function examFileOcrResultKey(
        string $schoolId,
        string $teacherId,
        string $examId,
        string $fileId
    ): string {
        return self::examFilePrefix($schoolId, $teacherId, $examId, $fileId) . self::OCR_RESULT_FILE;
    }

    public static function examFileFoundErrorsKey(
        string $schoolId,
        string $teacherId,
        string $examId,
        string $fileId
    ): string {
        return self::examFilePrefix($schoolId, $teacherId, $examId, $fileId) . self::FOUND_ERRORS_FILE;
    }

    public static function examFileMarkupDirectivesKey(
        string $schoolId,
        string $teacherId,
        string $examId,
        string $fileId
    ): string {
        return self::examFilePrefix($schoolId, $teacherId, $examId, $fileId) . self::MARKUP_DIRECTIVES_FILE;
    }

    public static function examFileEventsPrefix(
        string $schoolId,
        string $teacherId,
        string $examId,
        string $fileId
    ): string {
        return self::examFilePrefix($schoolId, $teacherId, $examId, $fileId) . 'events/';
    }

    public static function examFileEventKey(
        string $schoolId,
        string $teacherId,
        string $examId,
        string $fileId,
        string $eventId
    ): string {
        return self::examFileEventsPrefix($schoolId, $teacherId, $examId, $fileId) . $eventId . '.json';
    }

    public static function examStudentsPrefix(string $schoolId, string $teacherId, string $examId): string
    {
        return self::examPrefix($schoolId, $teacherId, $examId) . 'students/';
    }

    public static function examStudentPrefix(
        string $schoolId,
        string $teacherId,
        string $examId,
        string $studentId
    ): string {
        return self::examStudentsPrefix($schoolId, $teacherId, $examId) . $studentId . '/';
    }

    public static function examStudentAttrKey(
        string $schoolId,
        string $teacherId,
        string $examId,
        string $studentId
    ): string {
        return self::examStudentPrefix($schoolId, $teacherId, $examId, $studentId) . self::ATTR_FILE;
    }

    /**
     * Parse a schools/... node prefix into path segments.
     *
     * @return array{kind: string, school_id: string, teacher_id?: string, exam_id?: string, file_id?: string, student_id?: string}
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

        if (($parts[4] ?? '') !== 'exams' || !isset($parts[5])) {
            throw new Exception('Invalid exam path in prefix: ' . $prefix);
        }
        $examId = $parts[5];
        if (count($parts) === 6) {
            return [
                'kind' => 'exam',
                'school_id' => $schoolId,
                'teacher_id' => $teacherId,
                'exam_id' => $examId,
            ];
        }

        if (($parts[6] ?? '') === 'files' && isset($parts[7])) {
            return [
                'kind' => 'file',
                'school_id' => $schoolId,
                'teacher_id' => $teacherId,
                'exam_id' => $examId,
                'file_id' => $parts[7],
            ];
        }

        if (($parts[6] ?? '') === 'students' && isset($parts[7])) {
            return [
                'kind' => 'student',
                'school_id' => $schoolId,
                'teacher_id' => $teacherId,
                'exam_id' => $examId,
                'student_id' => $parts[7],
            ];
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

    public static function legacyExamPrefix(string $schoolId, string $userId, string $examId): string
    {
        return $schoolId . '/' . $userId . '/' . $examId . '/';
    }

    public static function legacyExamCsvKey(string $schoolId, string $userId, string $examId): string
    {
        return $schoolId . '/' . $userId . '/' . $examId . '/exam.csv';
    }

    public static function legacyExamFilesCsvKey(string $schoolId, string $userId, string $examId): string
    {
        return self::legacyExamPrefix($schoolId, $userId, $examId) . 'files.csv';
    }

    public static function legacyExamFilesPrefix(string $schoolId, string $userId, string $examId): string
    {
        return self::legacyExamPrefix($schoolId, $userId, $examId) . 'files/';
    }

    public static function legacyExamFileKey(
        string $schoolId,
        string $userId,
        string $examId,
        string $filename
    ): string {
        return self::legacyExamFilesPrefix($schoolId, $userId, $examId) . $filename;
    }

    public static function legacyExamUnassignedPrefix(string $schoolId, string $userId, string $examId): string
    {
        return self::legacyExamPrefix($schoolId, $userId, $examId) . 'unassigned/';
    }

    public static function legacyExamSubjectPrefix(string $schoolId, string $userId, string $examId): string
    {
        return self::legacyExamPrefix($schoolId, $userId, $examId) . 'subject/';
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
    public function put(string $key, string $localPath, ?string $contentType = null, ?string $ifMatch = null): ?string
    {
        $this->ensureBucket();

        $params = [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'SourceFile' => $localPath,
        ];
        if ($contentType !== null && $contentType !== '') {
            $params['ContentType'] = $contentType;
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
        ?string $ifMatch = null
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
     * @return array{ETag: string|null, ContentLength: int, ContentType: string}
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
        ];
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
    public function setIdPointer(string $hash, string $nodePrefix): void
    {
        $this->putContents(self::idIndexKey($hash), rtrim($nodePrefix, '/') . '/', 'text/plain');
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
        if (substr($prefix, -1) !== '/') {
            $prefix .= '/';
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
