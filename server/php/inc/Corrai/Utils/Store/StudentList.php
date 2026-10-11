<?php

namespace Corrai\Utils\Store;

use Corrai\Model\BaseStudent;
use Throwable;

/**
 * Per-assessment student index at …/students/list.json (id, name, mark only).
 *
 * Kept in sync from BaseStudent::save / delete. ObjectStore attributes remain the source of truth.
 */
class StudentList
{
    /**
     * @return list<array{id: string, name: string, mark: float|null}>
     */
    public static function read(string $schoolId, string $teacherId, string $assessmentId): array
    {
        $store = ObjectStore::getInstance();
        $key = ObjectStore::assessmentStudentsListKey($schoolId, $teacherId, $assessmentId);
        if (!$store->exists($key)) {
            return [];
        }
        $loaded = $store->getJson($key);
        return self::normalizeStudents($loaded['data']['students'] ?? []);
    }

    /**
     * Insert or update the indexed row for a student.
     */
    public static function upsert(BaseStudent $student): void
    {
        if (
            $student->id === null
            || $student->id === ''
            || $student->school_id === ''
            || $student->user_id === ''
            || $student->assessment_id === ''
        ) {
            return;
        }

        $entry = [
            'id' => $student->id,
            'name' => $student->name,
            'mark' => $student->mark,
        ];

        self::mutate(
            $student->school_id,
            $student->user_id,
            $student->assessment_id,
            static function (array &$students) use ($entry): void {
                foreach ($students as $i => $row) {
                    if (($row['id'] ?? '') === $entry['id']) {
                        $students[$i] = $entry;
                        return;
                    }
                }
                $students[] = $entry;
            }
        );
    }

    /**
     * Remove a student from the assessment list.
     */
    public static function remove(BaseStudent $student): void
    {
        if (
            $student->id === null
            || $student->id === ''
            || $student->school_id === ''
            || $student->user_id === ''
            || $student->assessment_id === ''
        ) {
            return;
        }

        $id = $student->id;
        self::mutate(
            $student->school_id,
            $student->user_id,
            $student->assessment_id,
            static function (array &$students) use ($id): void {
                $students = array_values(array_filter(
                    $students,
                    static fn(array $row): bool => ($row['id'] ?? '') !== $id
                ));
            }
        );
    }

    /**
     * @param callable(list<array{id: string, name: string, mark: float|null}>): void $mutator
     */
    private static function mutate(
        string $schoolId,
        string $teacherId,
        string $assessmentId,
        callable $mutator
    ): void {
        $store = ObjectStore::getInstance();
        $key = ObjectStore::assessmentStudentsListKey($schoolId, $teacherId, $assessmentId);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $etag = null;
            $students = [];
            if ($store->exists($key)) {
                try {
                    $loaded = $store->getJson($key);
                    $etag = $loaded['etag'];
                    $students = self::normalizeStudents($loaded['data']['students'] ?? []);
                } catch (Throwable $e) {
                    error_log('StudentList read failed for ' . $key . ': ' . $e->getMessage());
                    return;
                }
            }

            $mutator($students);
            $students = self::normalizeStudents($students);

            try {
                $store->putJson($key, ['students' => $students], $etag);
                return;
            } catch (StoreConflictException $e) {
                continue;
            } catch (Throwable $e) {
                error_log('StudentList write failed for ' . $key . ': ' . $e->getMessage());
                return;
            }
        }

        error_log('StudentList write gave up after conflicts for ' . $key);
    }

    /**
     * @param mixed $raw
     * @return list<array{id: string, name: string, mark: float|null}>
     */
    private static function normalizeStudents(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $mark = $row['mark'] ?? null;
            $out[] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? ''),
                'mark' => is_numeric($mark) ? (float) $mark : null,
            ];
        }
        return $out;
    }
}
