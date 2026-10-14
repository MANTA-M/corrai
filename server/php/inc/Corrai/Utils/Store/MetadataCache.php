<?php

namespace Corrai\Utils\Store;

use Corrai\Model\BaseAssessment;
use Corrai\Model\BaseStudent;
use Corrai\Model\InputFile;
use Corrai\Model\S3File;
use Corrai\Model\User;
use Corrai\Subject\AssessmentFactory;
use Redis;
use RedisException;
use Throwable;

/**
 * Redis cache-aside for assessment metadata trees (lists + attributes).
 *
 * Keys are namespaced by school/teacher. Granularity is per assessment so a
 * file write does not rebuild every assessment of the teacher.
 *
 * Queue keys (corrai:files / corrai:ocr) are never touched.
 */
class MetadataCache
{
    public const TTL_SECONDS = 3600;

    public const KEY_PREFIX = 'corrai:meta:v1';

    private static ?self $instance = null;

    private Redis $client;

    public function __construct(?Redis $client = null)
    {
        if ($client !== null) {
            $this->client = $client;
            return;
        }

        $host = (string) ($_ENV['REDIS_HOST'] ?? '127.0.0.1');
        $port = (int) ($_ENV['REDIS_PORT'] ?? 6379);
        $redis = new Redis();
        $redis->connect($host, $port);
        $this->client = $redis;
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

    public function assessmentsIndexKey(string $schoolId, string $teacherId): string
    {
        return self::KEY_PREFIX . ':' . $schoolId . ':' . $teacherId . ':assessments';
    }

    public function assessmentKey(string $schoolId, string $teacherId, string $assessmentId): string
    {
        return self::KEY_PREFIX . ':' . $schoolId . ':' . $teacherId . ':a:' . $assessmentId;
    }

    public function assessmentGenKey(string $schoolId, string $teacherId, string $assessmentId): string
    {
        return $this->assessmentKey($schoolId, $teacherId, $assessmentId) . ':gen';
    }

    public function teacherAssessmentsSetKey(string $schoolId, string $teacherId): string
    {
        return self::KEY_PREFIX . ':' . $schoolId . ':' . $teacherId . ':aids';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAssessmentTree(string $schoolId, string $teacherId, string $assessmentId): ?array
    {
        try {
            $raw = $this->client->get($this->assessmentKey($schoolId, $teacherId, $assessmentId));
        } catch (RedisException $e) {
            error_log('MetadataCache getAssessmentTree failed: ' . $e->getMessage());
            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $tree
     */
    public function setAssessmentTree(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        array $tree,
        ?int $expectedGen = null
    ): bool {
        if ($expectedGen !== null) {
            $current = $this->currentGen($schoolId, $teacherId, $assessmentId);
            if ($current !== $expectedGen) {
                return false;
            }
        }

        $payload = json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return false;
        }

        try {
            $this->client->setex(
                $this->assessmentKey($schoolId, $teacherId, $assessmentId),
                self::TTL_SECONDS,
                $payload
            );
            $this->client->sAdd($this->teacherAssessmentsSetKey($schoolId, $teacherId), $assessmentId);
            return true;
        } catch (RedisException $e) {
            error_log('MetadataCache setAssessmentTree failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function getAssessmentsIndex(string $schoolId, string $teacherId): ?array
    {
        try {
            $raw = $this->client->get($this->assessmentsIndexKey($schoolId, $teacherId));
        } catch (RedisException $e) {
            error_log('MetadataCache getAssessmentsIndex failed: ' . $e->getMessage());
            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $items = $decoded['assessments'] ?? null;
        return is_array($items) ? array_values($items) : null;
    }

    /**
     * @param list<array<string, mixed>> $assessments
     */
    public function setAssessmentsIndex(string $schoolId, string $teacherId, array $assessments): void
    {
        $payload = json_encode(
            ['assessments' => array_values($assessments)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($payload === false) {
            return;
        }
        try {
            $this->client->setex(
                $this->assessmentsIndexKey($schoolId, $teacherId),
                self::TTL_SECONDS,
                $payload
            );
        } catch (RedisException $e) {
            error_log('MetadataCache setAssessmentsIndex failed: ' . $e->getMessage());
        }
    }

    public function invalidateAssessment(string $schoolId, string $teacherId, string $assessmentId): void
    {
        if ($schoolId === '' || $teacherId === '' || $assessmentId === '') {
            return;
        }
        try {
            $this->client->incr($this->assessmentGenKey($schoolId, $teacherId, $assessmentId));
            $this->client->del($this->assessmentKey($schoolId, $teacherId, $assessmentId));
        } catch (RedisException $e) {
            error_log('MetadataCache invalidateAssessment failed: ' . $e->getMessage());
        }
    }

    public function invalidateAssessmentsIndex(string $schoolId, string $teacherId): void
    {
        if ($schoolId === '' || $teacherId === '') {
            return;
        }
        try {
            $this->client->del($this->assessmentsIndexKey($schoolId, $teacherId));
        } catch (RedisException $e) {
            error_log('MetadataCache invalidateAssessmentsIndex failed: ' . $e->getMessage());
        }
    }

    public function invalidateTeacher(string $schoolId, string $teacherId): void
    {
        if ($schoolId === '' || $teacherId === '') {
            return;
        }
        try {
            $setKey = $this->teacherAssessmentsSetKey($schoolId, $teacherId);
            $ids = $this->client->sMembers($setKey);
            if (is_array($ids)) {
                foreach ($ids as $assessmentId) {
                    if (!is_string($assessmentId) || $assessmentId === '') {
                        continue;
                    }
                    $this->invalidateAssessment($schoolId, $teacherId, $assessmentId);
                }
            }
            $this->client->del($setKey);
            $this->invalidateAssessmentsIndex($schoolId, $teacherId);
        } catch (RedisException $e) {
            error_log('MetadataCache invalidateTeacher failed: ' . $e->getMessage());
        }
    }

    /**
     * Cache-aside load of one assessment tree.
     *
     * @return array<string, mixed>
     */
    public function getOrRebuildAssessment(BaseAssessment $assessment): array
    {
        $schoolId = $assessment->school_id;
        $teacherId = $assessment->user_id;
        $assessmentId = (string) $assessment->id;

        $cached = $this->getAssessmentTree($schoolId, $teacherId, $assessmentId);
        if ($cached !== null) {
            return $cached;
        }

        $gen = $this->currentGen($schoolId, $teacherId, $assessmentId);
        $tree = $this->rebuildAssessmentTree($schoolId, $teacherId, $assessmentId);
        $this->setAssessmentTree($schoolId, $teacherId, $assessmentId, $tree, $gen);
        return $tree;
    }

    /**
     * @return list<BaseAssessment>
     */
    public function getOrRebuildAssessmentsForUser(User $user): array
    {
        $schoolId = $user->school_id;
        $teacherId = (string) $user->id;
        if ($schoolId === '' || $teacherId === '') {
            return [];
        }

        $index = $this->getAssessmentsIndex($schoolId, $teacherId);
        if ($index === null) {
            $index = $this->rebuildAssessmentsIndex($schoolId, $teacherId);
            $this->setAssessmentsIndex($schoolId, $teacherId, $index);
        }

        $out = [];
        foreach ($index as $attrs) {
            if (!is_array($attrs)) {
                continue;
            }
            $id = (string) ($attrs['id'] ?? '');
            if ($id === '') {
                continue;
            }
            try {
                $out[] = AssessmentFactory::fromAttributes($attrs, $schoolId, $teacherId, $id);
            } catch (Throwable $e) {
                continue;
            }
        }
        return $out;
    }

    /**
     * Scan S3 and build the assessment metadata document.
     *
     * @return array<string, mixed>
     */
    public function rebuildAssessmentTree(string $schoolId, string $teacherId, string $assessmentId): array
    {
        $store = ObjectStore::getInstance();
        $attrKey = ObjectStore::assessmentAttrKey($schoolId, $teacherId, $assessmentId);
        $attrs = [];
        if ($store->exists($attrKey)) {
            try {
                $attrs = $store->getJson($attrKey)['data'] ?? [];
            } catch (Throwable $e) {
                $attrs = [];
            }
        }
        if (is_array($attrs)) {
            $attrs['id'] = $assessmentId;
            $attrs['school_id'] = $schoolId;
            $attrs['user_id'] = $teacherId;
        } else {
            $attrs = [
                'id' => $assessmentId,
                'school_id' => $schoolId,
                'user_id' => $teacherId,
            ];
        }

        return [
            'attrs' => $attrs,
            'subject' => $this->loadInputFileNodes(
                ObjectStore::assessmentSubjectFilesPrefix($schoolId, $teacherId, $assessmentId)
            ),
            'unclassified' => $this->loadInputFileNodes(
                ObjectStore::assessmentUnclassifiedFilesPrefix($schoolId, $teacherId, $assessmentId)
            ),
            'legacy' => $this->loadInputFileNodes(
                ObjectStore::assessmentFilesPrefix($schoolId, $teacherId, $assessmentId)
            ),
            'blobs' => $this->loadBlobNodes($schoolId, $teacherId, $assessmentId),
            'students' => $this->loadStudentNodes($schoolId, $teacherId, $assessmentId),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rebuildAssessmentsIndex(string $schoolId, string $teacherId): array
    {
        $store = ObjectStore::getInstance();
        $items = [];
        $prefix = ObjectStore::assessmentsPrefix($schoolId, $teacherId);
        foreach ($store->listChildPrefixes($prefix) as $assessmentId) {
            $attrKey = ObjectStore::assessmentAttrKey($schoolId, $teacherId, $assessmentId);
            if (!$store->exists($attrKey)) {
                continue;
            }
            try {
                $data = $store->getJson($attrKey)['data'] ?? [];
            } catch (Throwable $e) {
                continue;
            }
            if (!is_array($data)) {
                continue;
            }
            $data['id'] = $assessmentId;
            $data['school_id'] = $schoolId;
            $data['user_id'] = $teacherId;
            $items[] = $data;
            try {
                $this->client->sAdd($this->teacherAssessmentsSetKey($schoolId, $teacherId), $assessmentId);
            } catch (RedisException $e) {
                // Index set is best-effort for teacher-wide invalidation.
            }
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $tree
     * @return list<InputFile|S3File>
     */
    public function hydrateFileModels(array $tree, string $schoolId, string $teacherId, string $assessmentId): array
    {
        $files = [];
        $seen = [];

        foreach (['subject', 'unclassified', 'legacy'] as $bucket) {
            $nodes = $tree[$bucket] ?? [];
            if (!is_array($nodes)) {
                continue;
            }
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $file = $this->hydrateInputFileNode($node, $schoolId, $teacherId, $assessmentId);
                if ($file === null || $file->id === null || isset($seen[$file->id])) {
                    continue;
                }
                $files[] = $file;
                $seen[$file->id] = true;
            }
        }

        $students = $tree['students'] ?? [];
        if (is_array($students)) {
            foreach ($students as $studentNode) {
                if (!is_array($studentNode)) {
                    continue;
                }
                $inputFiles = $studentNode['input_files'] ?? [];
                if (is_array($inputFiles)) {
                    foreach ($inputFiles as $node) {
                        if (!is_array($node)) {
                            continue;
                        }
                        $file = $this->hydrateInputFileNode($node, $schoolId, $teacherId, $assessmentId);
                        if ($file === null || $file->id === null || isset($seen[$file->id])) {
                            continue;
                        }
                        $files[] = $file;
                        $seen[$file->id] = true;
                    }
                }
                $direct = $studentNode['direct_s3'] ?? [];
                if (is_array($direct)) {
                    foreach ($direct as $blob) {
                        if (!is_array($blob)) {
                            continue;
                        }
                        $file = S3File::fromCachedArray($blob);
                        if ($file->id === null || isset($seen[$file->id])) {
                            continue;
                        }
                        $files[] = $file;
                        $seen[$file->id] = true;
                    }
                }
            }
        }

        $blobs = $tree['blobs'] ?? [];
        if (is_array($blobs)) {
            foreach ($blobs as $blob) {
                if (!is_array($blob)) {
                    continue;
                }
                $file = S3File::fromCachedArray($blob);
                if ($file->id === null || isset($seen[$file->id])) {
                    continue;
                }
                $files[] = $file;
                $seen[$file->id] = true;
            }
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $tree
     * @return list<InputFile|S3File>
     */
    public function hydrateSubjectFileModels(
        array $tree,
        string $schoolId,
        string $teacherId,
        string $assessmentId
    ): array {
        $allowed = ['subject', 'solution', 'instructions'];
        $files = [];
        $seen = [];

        foreach (['subject', 'legacy'] as $bucket) {
            $nodes = $tree[$bucket] ?? [];
            if (!is_array($nodes)) {
                continue;
            }
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $file = $this->hydrateInputFileNode($node, $schoolId, $teacherId, $assessmentId);
                if ($file === null || $file->id === null || isset($seen[$file->id])) {
                    continue;
                }
                if (!in_array($file->role(), $allowed, true)) {
                    continue;
                }
                $files[] = $file;
                $seen[$file->id] = true;
            }
        }

        $blobs = $tree['blobs'] ?? [];
        if (is_array($blobs)) {
            foreach ($blobs as $blob) {
                if (!is_array($blob)) {
                    continue;
                }
                $file = S3File::fromCachedArray($blob);
                if ($file->id === null || isset($seen[$file->id])) {
                    continue;
                }
                if (!in_array($file->type, $allowed, true)) {
                    continue;
                }
                $files[] = $file;
                $seen[$file->id] = true;
            }
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $tree
     * @return list<InputFile|S3File>
     */
    public function hydrateStudentFileModels(
        array $tree,
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $studentId
    ): array {
        $files = [];
        $seen = [];
        $students = $tree['students'] ?? [];
        if (!is_array($students)) {
            return [];
        }

        foreach ($students as $studentNode) {
            if (!is_array($studentNode)) {
                continue;
            }
            $attrs = $studentNode['attrs'] ?? [];
            $id = is_array($attrs) ? (string) ($attrs['id'] ?? '') : '';
            if ($id !== $studentId) {
                continue;
            }

            $inputFiles = $studentNode['input_files'] ?? [];
            if (is_array($inputFiles)) {
                foreach ($inputFiles as $node) {
                    if (!is_array($node)) {
                        continue;
                    }
                    $file = $this->hydrateInputFileNode($node, $schoolId, $teacherId, $assessmentId);
                    if ($file === null || $file->id === null || isset($seen[$file->id])) {
                        continue;
                    }
                    $files[] = $file;
                    $seen[$file->id] = true;
                }
            }

            $direct = $studentNode['direct_s3'] ?? [];
            if (is_array($direct)) {
                foreach ($direct as $blob) {
                    if (!is_array($blob)) {
                        continue;
                    }
                    $file = S3File::fromCachedArray($blob);
                    if ($file->id === null || isset($seen[$file->id])) {
                        continue;
                    }
                    $files[] = $file;
                    $seen[$file->id] = true;
                }
            }
            break;
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $tree
     * @param class-string<BaseStudent> $studentClass
     * @return list<BaseStudent>
     */
    public function hydrateStudentModels(
        array $tree,
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        string $studentClass
    ): array {
        $out = [];
        $students = $tree['students'] ?? [];
        if (!is_array($students)) {
            return [];
        }

        foreach ($students as $studentNode) {
            if (!is_array($studentNode)) {
                continue;
            }
            $attrs = $studentNode['attrs'] ?? null;
            if (!is_array($attrs)) {
                continue;
            }
            $id = (string) ($attrs['id'] ?? '');
            if ($id === '') {
                continue;
            }
            try {
                /** @var BaseStudent $student */
                $student = $studentClass::from_array($attrs);
                $student->id = $id;
                $student->school_id = $schoolId;
                $student->user_id = $teacherId;
                $student->assessment_id = $assessmentId;
                $out[] = $student;
            } catch (Throwable $e) {
                continue;
            }
        }

        return $out;
    }

    public function currentGen(string $schoolId, string $teacherId, string $assessmentId): int
    {
        try {
            $raw = $this->client->get($this->assessmentGenKey($schoolId, $teacherId, $assessmentId));
        } catch (RedisException $e) {
            return 0;
        }
        if ($raw === false || $raw === null || $raw === '') {
            return 0;
        }
        return (int) $raw;
    }

    /**
     * @return list<array{attrs: array<string, mixed>, directory_s3: list<array{id: string, name: string}>}>
     */
    private function loadInputFileNodes(string $areaPrefix): array
    {
        $store = ObjectStore::getInstance();
        $nodes = [];
        foreach ($store->listChildPrefixes($areaPrefix) as $fileId) {
            $prefix = $areaPrefix . $fileId . '/';
            $attrKey = $prefix . ObjectStore::ATTR_FILE;
            if (!$store->exists($attrKey)) {
                continue;
            }
            try {
                $data = $store->getJson($attrKey)['data'] ?? [];
            } catch (Throwable $e) {
                continue;
            }
            if (!is_array($data)) {
                continue;
            }
            $data['id'] = $fileId;
            $nodes[] = [
                'attrs' => $data,
                'directory_s3' => $this->loadDirectoryS3Entries($prefix),
            ];
        }
        return $nodes;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function loadDirectoryS3Entries(string $prefix): array
    {
        $store = ObjectStore::getInstance();
        $entries = [];
        foreach ($store->listImmediateFiles($prefix) as $name) {
            if ($name === '' || $name === ObjectStore::CONTENT_FILE) {
                continue;
            }
            $entries[] = [
                'id' => $name,
                'name' => $name,
            ];
        }
        usort(
            $entries,
            static fn(array $a, array $b): int => strcmp($a['name'], $b['name'])
        );
        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadBlobNodes(string $schoolId, string $teacherId, string $assessmentId): array
    {
        $store = ObjectStore::getInstance();
        $prefix = ObjectStore::assessmentBlobsPrefix($schoolId, $teacherId, $assessmentId);
        $nodes = [];
        foreach ($store->listImmediateFiles($prefix) as $fileId) {
            try {
                $file = S3File::from_key($prefix . $fileId);
                $nodes[] = $file->toCacheArray();
            } catch (Throwable $e) {
                continue;
            }
        }
        return $nodes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadStudentNodes(string $schoolId, string $teacherId, string $assessmentId): array
    {
        $store = ObjectStore::getInstance();
        $studentsPrefix = ObjectStore::assessmentStudentsPrefix($schoolId, $teacherId, $assessmentId);
        $nodes = [];

        foreach ($store->listChildPrefixes($studentsPrefix) as $studentId) {
            $attrKey = ObjectStore::assessmentStudentAttrKey($schoolId, $teacherId, $assessmentId, $studentId);
            if (!$store->exists($attrKey)) {
                continue;
            }
            try {
                $data = $store->getJson($attrKey)['data'] ?? [];
            } catch (Throwable $e) {
                continue;
            }
            if (!is_array($data)) {
                continue;
            }
            $data['id'] = $studentId;
            $data['school_id'] = $schoolId;
            $data['user_id'] = $teacherId;
            $data['assessment_id'] = $assessmentId;

            $studentPrefix = ObjectStore::assessmentStudentPrefix($schoolId, $teacherId, $assessmentId, $studentId);
            $nodes[] = [
                'attrs' => $data,
                'input_files' => $this->loadInputFileNodes($studentPrefix),
                'direct_s3' => $this->loadDirectStudentS3($studentPrefix, $studentId),
            ];
        }

        return $nodes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadDirectStudentS3(string $studentPrefix, string $studentId): array
    {
        $store = ObjectStore::getInstance();
        $nodes = [];
        foreach ($store->listImmediateFiles($studentPrefix) as $name) {
            if ($name === ObjectStore::ATTR_FILE) {
                continue;
            }
            try {
                $file = S3File::from_key($studentPrefix . $name);
                if ($file->student === null || $file->student === '') {
                    $file->student = $studentId;
                }
                $nodes[] = $file->toCacheArray();
            } catch (Throwable $e) {
                continue;
            }
        }
        return $nodes;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hydrateInputFileNode(
        array $node,
        string $schoolId,
        string $teacherId,
        string $assessmentId
    ): ?InputFile {
        $attrs = $node['attrs'] ?? null;
        if (!is_array($attrs)) {
            return null;
        }
        $fileId = (string) ($attrs['id'] ?? '');
        if ($fileId === '') {
            return null;
        }
        $studentId = $attrs['student'] ?? null;
        $parsed = [
            'kind' => 'file',
            'school_id' => $schoolId,
            'teacher_id' => $teacherId,
            'assessment_id' => $assessmentId,
            'file_id' => $fileId,
            'student_id' => is_string($studentId) && $studentId !== '' ? $studentId : null,
        ];
        try {
            return InputFile::fromCachedAttributes($attrs, $parsed, null);
        } catch (Throwable $e) {
            return null;
        }
    }
}
