<?php

namespace Corrai\Utils;

use Corrai\Model\BaseAssessment;
use Corrai\Model\BaseFile;
use Corrai\Model\File;
use Corrai\Subject\AssessmentFactory;
use Throwable;

/**
 * Write the concrete assessment and file class onto existing S3 attribute documents.
 *
 * Idempotent. The class is derived from subject, country, and level. Country and
 * level codes match regardless of case. Assessments are updated before files.
 */
class ClassAttributeMigrator
{
    private ObjectStore $store;

    /** @var list<string> */
    private array $messages = [];

    public function __construct(?ObjectStore $store = null)
    {
        $this->store = $store ?? ObjectStore::getInstance();
    }

    public static function isAssessmentAttributeKey(string $key): bool
    {
        return preg_match(
            '#^schools/[^/]+/teachers/[^/]+/assessments/[^/]+/attributes\.json$#',
            $key
        ) === 1;
    }

    public static function isFileAttributeKey(string $key): bool
    {
        if (!str_ends_with($key, '/attributes.json')) {
            return false;
        }
        return preg_match(
            '#^schools/[^/]+/teachers/[^/]+/assessments/[^/]+/(?:subject|unclassified|files)/[^/]+/attributes\.json$#',
            $key
        ) === 1
            || preg_match(
                '#^schools/[^/]+/teachers/[^/]+/assessments/[^/]+/students/[^/]+/[^/]+/attributes\.json$#',
                $key
            ) === 1;
    }

    /**
     * Concrete assessment class for an attribute document.
     *
     * @param array<string, mixed> $data
     * @return class-string<BaseAssessment>
     */
    public static function assessmentClassFor(array $data): string
    {
        $country = $data['country'] ?? null;
        $level = $data['level'] ?? null;
        return AssessmentFactory::assessmentClass(
            (string) ($data['subject'] ?? ''),
            is_string($country) ? trim($country) : '',
            is_string($level) ? trim($level) : ''
        );
    }

    /**
     * Concrete file class for documents that belong to $assessment.
     *
     * @return class-string<File>
     */
    public static function fileClassFor(BaseAssessment $assessment): string
    {
        $class = $assessment->fileClass();
        if (BaseFile::isFileClass($class)) {
            return $class;
        }
        return File::class;
    }

    /**
     * @return list<string> Log lines
     */
    public function run(): array
    {
        $this->messages = [];
        $this->store->ensureBucket();

        $assessmentKeys = [];
        $fileKeys = [];
        foreach ($this->store->listKeys('schools/') as $key) {
            if (self::isAssessmentAttributeKey($key)) {
                $assessmentKeys[] = $key;
            } elseif (self::isFileAttributeKey($key)) {
                $fileKeys[] = $key;
            }
        }

        $assessmentUpdates = 0;
        foreach ($assessmentKeys as $key) {
            if ($this->migrateAssessment($key)) {
                $assessmentUpdates++;
            }
        }

        $fileUpdates = 0;
        foreach ($fileKeys as $key) {
            if ($this->migrateFile($key)) {
                $fileUpdates++;
            }
        }

        $this->log($assessmentUpdates === 0 && $fileUpdates === 0
            ? 'No class attributes to migrate.'
            : 'Class attributes: ' . $assessmentUpdates . ' assessment update(s), '
                . $fileUpdates . ' file update(s).');

        return $this->messages;
    }

    private function migrateAssessment(string $key): bool
    {
        try {
            $loaded = $this->store->getJson($key);
        } catch (Throwable $e) {
            $this->log("  skip unreadable $key");
            return false;
        }

        $data = $loaded['data'];
        if (!is_array($data)) {
            $this->log("  skip unreadable $key");
            return false;
        }
        $class = self::assessmentClassFor($data);
        if (($data['class'] ?? null) === $class) {
            return false;
        }

        $data['class'] = $class;
        return $this->write($key, $data, $loaded['etag'], 'assessment');
    }

    private function migrateFile(string $key): bool
    {
        try {
            $loaded = $this->store->getJson($key);
            $assessment = $this->assessmentForFileKey($key);
        } catch (Throwable $e) {
            $this->log('  skip ' . $key . ' (' . $e->getMessage() . ')');
            return false;
        }

        $data = $loaded['data'];
        if (!is_array($data)) {
            $this->log("  skip unreadable $key");
            return false;
        }
        $class = self::fileClassFor($assessment);
        if (($data['class'] ?? null) === $class) {
            return false;
        }

        $data['class'] = $class;
        return $this->write($key, $data, $loaded['etag'], 'file');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $key, array $data, ?string $etag, string $kind): bool
    {
        try {
            $this->store->putJson($key, $data, $etag);
        } catch (StoreConflictException $e) {
            $this->log("  conflict $key");
            return false;
        }
        $this->log('  ' . $kind . ' ' . $key . ' -> ' . $data['class']);
        return true;
    }

    private function assessmentForFileKey(string $fileKey): BaseAssessment
    {
        $parsed = ObjectStore::parseNodePrefix(dirname($fileKey) . '/');
        $schoolId = $parsed['school_id'];
        $teacherId = $parsed['teacher_id'] ?? '';
        $assessmentId = $parsed['assessment_id'] ?? '';
        $attrKey = ObjectStore::assessmentAttrKey($schoolId, $teacherId, $assessmentId);
        $loaded = $this->store->getJson($attrKey);
        return AssessmentFactory::fromAttributes($loaded['data'], $schoolId, $teacherId, $assessmentId);
    }

    private function log(string $message): void
    {
        $this->messages[] = $message;
    }
}
