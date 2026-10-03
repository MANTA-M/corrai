<?php

namespace Corrai\Utils;

/**
 * Copy the legacy CSV object tree into the schools/... JSON tree.
 *
 * Idempotent: existing destination attributes / same-size content are skipped.
 */
class CsvTreeMigrator
{
    private ObjectStore $store;

    /** @var list<string> */
    private array $messages = [];

    public function __construct(?ObjectStore $store = null)
    {
        $this->store = $store ?? ObjectStore::getInstance();
    }

    /**
     * @param list<string>|null $onlySchoolIds When set, only migrate these legacy school ids
     * @return list<string> Log lines
     */
    public function run(?array $onlySchoolIds = null): array
    {
        $this->messages = [];
        $this->store->ensureBucket();

        $filter = null;
        if ($onlySchoolIds !== null) {
            $filter = array_fill_keys($onlySchoolIds, true);
        }

        foreach ($this->store->listChildPrefixes('') as $child) {
            if ($child === '_id' || $child === 'schools') {
                continue;
            }
            if ($filter !== null && !isset($filter[$child])) {
                continue;
            }
            $csvKey = ObjectStore::legacySchoolCsvKey($child);
            if (!$this->store->exists($csvKey)) {
                continue;
            }
            $this->migrateSchool($child);
        }

        return $this->messages;
    }

    private function log(string $message): void
    {
        $this->messages[] = $message;
    }

    private function migrateSchool(string $schoolId): void
    {
        $this->log("School $schoolId");
        $csvKey = ObjectStore::legacySchoolCsvKey($schoolId);
        $data = CsvStore::decode($this->store->getContents($csvKey));

        $attrKey = ObjectStore::schoolAttrKey($schoolId);
        if (!$this->store->exists($attrKey)) {
            $this->store->putJson($attrKey, [
                'name' => $data['name'] ?? $schoolId,
                'created_at' => $data['created_at'] ?? gmdate('c'),
            ]);
            $this->log("  wrote $attrKey");
        } else {
            $this->log("  skip existing $attrKey");
        }
        $this->store->setIdPointer($schoolId, ObjectStore::schoolPrefix($schoolId));

        $legacyPrefix = ObjectStore::legacySchoolPrefix($schoolId);
        foreach ($this->store->listChildPrefixes($legacyPrefix) as $userId) {
            $userCsv = ObjectStore::legacyUserCsvKey($schoolId, $userId);
            if (!$this->store->exists($userCsv)) {
                continue;
            }
            $this->migrateTeacher($schoolId, $userId);
        }

        $this->store->deletePrefix($legacyPrefix);
        $this->log("  deleted legacy prefix $legacyPrefix");
    }

    private function migrateTeacher(string $schoolId, string $userId): void
    {
        $this->log("  Teacher $userId");
        $data = CsvStore::decode($this->store->getContents(ObjectStore::legacyUserCsvKey($schoolId, $userId)));
        $attrKey = ObjectStore::teacherAttrKey($schoolId, $userId);
        if (!$this->store->exists($attrKey)) {
            $this->store->putJson($attrKey, [
                'email' => $data['email'] ?? ($userId . '@ind.local'),
                'name' => $data['name'] ?? $userId,
                'role' => $data['role'] ?? 'teacher',
                'password_hash' => $data['password_hash'] ?? '',
                'created_at' => $data['created_at'] ?? gmdate('c'),
            ]);
            $this->log("    wrote $attrKey");
        } else {
            $this->log("    skip existing $attrKey");
        }
        $this->store->setIdPointer($userId, ObjectStore::teacherPrefix($schoolId, $userId));

        $legacyUserPrefix = ObjectStore::legacyUserPrefix($schoolId, $userId);
        foreach ($this->store->listChildPrefixes($legacyUserPrefix) as $assessmentId) {
            $assessmentCsv = ObjectStore::legacyAssessmentCsvKey($schoolId, $userId, $assessmentId);
            if (!$this->store->exists($assessmentCsv)) {
                continue;
            }
            $this->migrateAssessment($schoolId, $userId, $assessmentId);
        }
    }

    private function migrateAssessment(string $schoolId, string $userId, string $assessmentId): void
    {
        $this->log("    Assessment $assessmentId");
        $data = CsvStore::decode($this->store->getContents(ObjectStore::legacyAssessmentCsvKey($schoolId, $userId, $assessmentId)));
        $attrKey = ObjectStore::assessmentAttrKey($schoolId, $userId, $assessmentId);
        if (!$this->store->exists($attrKey)) {
            $country = $data['country'] ?? null;
            $level = $data['level'] ?? null;
            if (is_string($country) && trim($country) === '') {
                $country = null;
            }
            if (is_string($level) && trim($level) === '') {
                $level = null;
            }
            $this->store->putJson($attrKey, [
                'name' => $data['name'] ?? $assessmentId,
                'subject' => $data['subject'] ?? 'Other',
                'country' => $country,
                'level' => $level,
                'date' => $data['date'] ?? gmdate('Y-m-d'),
                'created_at' => $data['created_at'] ?? gmdate('c'),
            ]);
            $this->log("      wrote $attrKey");
        } else {
            $this->log("      skip existing $attrKey");
        }
        $this->store->setIdPointer($assessmentId, ObjectStore::assessmentPrefix($schoolId, $userId, $assessmentId));

        $tags = $this->loadLegacyFileTags($schoolId, $userId, $assessmentId);
        $studentIds = $this->migrateStudents($schoolId, $userId, $assessmentId, $tags);
        $this->migrateBinaries($schoolId, $userId, $assessmentId, $tags, $studentIds);
    }

    /**
     * @return array<string, array{type: string, student: string}>
     */
    private function loadLegacyFileTags(string $schoolId, string $userId, string $assessmentId): array
    {
        $key = ObjectStore::legacyAssessmentFilesCsvKey($schoolId, $userId, $assessmentId);
        if (!$this->store->exists($key)) {
            return [];
        }
        $tags = [];
        foreach (CsvStore::decodeRows($this->store->getContents($key)) as $row) {
            $name = $row['name'] ?? '';
            if ($name === '') {
                continue;
            }
            $tags[$name] = [
                'type' => $row['type'] ?? '',
                'student' => $row['student'] ?? $row['author'] ?? '',
            ];
        }
        return $tags;
    }

    /**
     * @param array<string, array{type: string, student: string}> $tags
     * @return array<string, string> display name (lower) => student hash
     */
    private function migrateStudents(
        string $schoolId,
        string $userId,
        string $assessmentId,
        array $tags
    ): array {
        $names = [];
        foreach ($tags as $tag) {
            $name = trim($tag['student'] ?? '');
            if ($name !== '') {
                $names[strtolower($name)] = $name;
            }
        }

        // Also discover names already migrated
        $map = [];
        $studentsPrefix = ObjectStore::assessmentStudentsPrefix($schoolId, $userId, $assessmentId);
        foreach ($this->store->listChildPrefixes($studentsPrefix) as $studentId) {
            $attrKey = ObjectStore::assessmentStudentAttrKey($schoolId, $userId, $assessmentId, $studentId);
            if (!$this->store->exists($attrKey)) {
                continue;
            }
            $loaded = $this->store->getJson($attrKey);
            $display = (string) ($loaded['data']['name'] ?? '');
            if ($display !== '') {
                $map[strtolower($display)] = $studentId;
            }
        }

        foreach ($names as $lower => $display) {
            if (isset($map[$lower])) {
                continue;
            }
            $studentId = HashId::create();
            $attrKey = ObjectStore::assessmentStudentAttrKey($schoolId, $userId, $assessmentId, $studentId);
            $this->store->putJson($attrKey, [
                'name' => $display,
                'status' => '',
                'mark' => null,
            ]);
            $this->store->setIdPointer(
                $studentId,
                ObjectStore::assessmentStudentPrefix($schoolId, $userId, $assessmentId, $studentId)
            );
            $map[$lower] = $studentId;
            $this->log("      student $studentId ($display)");
        }

        return $map;
    }

    /**
     * @param array<string, array{type: string, student: string}> $tags
     * @param array<string, string> $studentIds
     */
    private function migrateBinaries(
        string $schoolId,
        string $userId,
        string $assessmentId,
        array $tags,
        array $studentIds
    ): void {
        $sources = [];
        foreach ([
            ObjectStore::legacyAssessmentFilesPrefix($schoolId, $userId, $assessmentId),
            ObjectStore::legacyAssessmentUnassignedPrefix($schoolId, $userId, $assessmentId),
            ObjectStore::legacyAssessmentSubjectPrefix($schoolId, $userId, $assessmentId),
        ] as $prefix) {
            foreach ($this->store->list($prefix) as $item) {
                $key = $item['key'] ?? ($prefix . $item['name']);
                if (substr($key, -1) === '/' || $item['name'] === 'files.csv') {
                    continue;
                }
                $sources[$item['name']] = [
                    'key' => $key,
                    'size' => (int) $item['size'],
                    'created' => (int) $item['created'],
                ];
            }
        }

        // Existing destinations by display name
        $existingByName = [];
        $areas = [
            ObjectStore::assessmentSubjectFilesPrefix($schoolId, $userId, $assessmentId),
            ObjectStore::assessmentUnclassifiedFilesPrefix($schoolId, $userId, $assessmentId),
            ObjectStore::assessmentFilesPrefix($schoolId, $userId, $assessmentId),
        ];
        $studentsPrefix = ObjectStore::assessmentStudentsPrefix($schoolId, $userId, $assessmentId);
        foreach ($this->store->listChildPrefixes($studentsPrefix) as $studentId) {
            $areas[] = $studentsPrefix . $studentId . '/';
        }
        foreach ($areas as $filesPrefix) {
            foreach ($this->store->listChildPrefixes($filesPrefix) as $fileId) {
                $pointerKey = ObjectStore::idIndexKey($fileId);
                if (!$this->store->exists($pointerKey)) {
                    continue;
                }
                $attrKey = rtrim($this->store->resolveIdPointer($fileId), '/') . '/' . ObjectStore::ATTR_FILE;
                if (!$this->store->exists($attrKey)) {
                    continue;
                }
                $loaded = $this->store->getJson($attrKey);
                $name = (string) ($loaded['data']['name'] ?? '');
                if ($name !== '') {
                    $existingByName[strtolower($name)] = [
                        'id' => $fileId,
                        'type' => (string) ($loaded['data']['type'] ?? ''),
                        'student' => $loaded['data']['student'] ?? null,
                    ];
                }
            }
        }

        foreach ($sources as $filename => $source) {
            $lower = strtolower($filename);
            if (isset($existingByName[$lower])) {
                $fileId = $existingByName[$lower]['id'];
                $contentKey = ObjectStore::assessmentFileContentKey(
                    $schoolId,
                    $userId,
                    $assessmentId,
                    $fileId,
                    $existingByName[$lower]['type'],
                    is_string($existingByName[$lower]['student']) ? $existingByName[$lower]['student'] : null
                );
                if ($this->store->exists($contentKey)) {
                    $head = $this->store->head($contentKey);
                    if ($head['ContentLength'] === $source['size']) {
                        $this->log("      skip existing file $filename");
                        continue;
                    }
                    $this->log("      SIZE MISMATCH for $filename — left in place");
                    continue;
                }
            }

            $tag = $tags[$filename] ?? ['type' => '', 'student' => ''];
            $studentName = trim($tag['student'] ?? '');
            $studentHash = null;
            if ($studentName !== '') {
                $studentHash = $studentIds[strtolower($studentName)] ?? null;
            }

            $fileId = HashId::create();
            $fileType = (string) ($tag['type'] ?? '');
            $contentKey = ObjectStore::assessmentFileContentKey(
                $schoolId,
                $userId,
                $assessmentId,
                $fileId,
                $fileType,
                $studentHash
            );
            $this->store->copy($source['key'], $contentKey);
            $head = $this->store->head($contentKey);

            $attrKey = ObjectStore::assessmentFileAttrKey(
                $schoolId,
                $userId,
                $assessmentId,
                $fileId,
                $fileType,
                $studentHash
            );
            $this->store->putJson($attrKey, [
                'name' => $filename,
                'type' => $fileType,
                'student' => $studentHash,
                'status' => 'stored',
                'content_type' => $head['ContentType'] ?: 'application/octet-stream',
                'size' => $head['ContentLength'] ?: $source['size'],
                'created' => $source['created'] ?: time(),
            ]);
            $this->store->setIdPointer(
                $fileId,
                ObjectStore::assessmentFilePrefix(
                    $schoolId,
                    $userId,
                    $assessmentId,
                    $fileId,
                    $fileType,
                    $studentHash
                )
            );
            $eventId = sprintf('%d-%s', time(), bin2hex(random_bytes(4)));
            $this->store->putJson(
                ObjectStore::assessmentFileEventKey(
                    $schoolId,
                    $userId,
                    $assessmentId,
                    $fileId,
                    $eventId,
                    $fileType,
                    $studentHash
                ),
                [
                    'timestamp' => time(),
                    'name' => 'Migrated',
                ]
            );
            $this->log("      migrated file $filename -> $fileId");
        }
    }
}
