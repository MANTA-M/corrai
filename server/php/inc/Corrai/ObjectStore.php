<?php

namespace Corrai;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Exception;

/**
 * Thin S3 wrapper pointed at SeaweedFS (or any S3-compatible endpoint).
 *
 * Tree layout:
 *   {schoolId}/school.csv
 *   {schoolId}/{userId}/user.csv
 *   {schoolId}/{userId}/{examId}/exam.csv
 *   {schoolId}/{userId}/{examId}/unassigned/{filename}
 *   {schoolId}/{userId}/{examId}/files.csv  (type + author tags per file)
 *   _id/{hash}  — pointer to node prefix for O(1) from_hash
 */
class ObjectStore
{
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
    // Path builders
    // -------------------------------------------------------------------------

    public static function idIndexKey(string $hash): string
    {
        return '_id/' . $hash;
    }

    public static function schoolPrefix(string $schoolId): string
    {
        return $schoolId . '/';
    }

    public static function schoolCsvKey(string $schoolId): string
    {
        return $schoolId . '/school.csv';
    }

    public static function userPrefix(string $schoolId, string $userId): string
    {
        return $schoolId . '/' . $userId . '/';
    }

    public static function userCsvKey(string $schoolId, string $userId): string
    {
        return $schoolId . '/' . $userId . '/user.csv';
    }

    public static function examPrefix(string $schoolId, string $userId, string $examId): string
    {
        return $schoolId . '/' . $userId . '/' . $examId . '/';
    }

    public static function examCsvKey(string $schoolId, string $userId, string $examId): string
    {
        return $schoolId . '/' . $userId . '/' . $examId . '/exam.csv';
    }

    public static function examFilesCsvKey(string $schoolId, string $userId, string $examId): string
    {
        return self::examPrefix($schoolId, $userId, $examId) . 'files.csv';
    }

    public static function examUnassignedPrefix(string $schoolId, string $userId, string $examId): string
    {
        return self::examPrefix($schoolId, $userId, $examId) . 'unassigned/';
    }

    public static function examUnassignedKey(
        string $schoolId,
        string $userId,
        string $examId,
        string $filename
    ): string {
        return self::examUnassignedPrefix($schoolId, $userId, $examId) . $filename;
    }

    public static function examSubjectPrefix(string $schoolId, string $userId, string $examId): string
    {
        return self::examPrefix($schoolId, $userId, $examId) . 'subject/';
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
     */
    public function put(string $key, string $localPath, ?string $contentType = null): void
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

        $this->client->putObject($params);
    }

    /**
     * Upload an in-memory string/body to the object store.
     */
    public function putContents(string $key, string $body, ?string $contentType = null): void
    {
        $this->ensureBucket();

        $params = [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $body,
        ];
        if ($contentType !== null && $contentType !== '') {
            $params['ContentType'] = $contentType;
        }

        $this->client->putObject($params);
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
     * Fetch an object. Returns ['Body' => stream/resource, 'ContentType' => string, 'ContentLength' => int].
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
     * Delete all objects under a prefix (e.g. schoolId/).
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
     * List objects under a prefix.
     * Returns array of ['name' => basename, 'size' => int, 'created' => unix timestamp].
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
}
