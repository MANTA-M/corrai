<?php

namespace Corrai\Stream;

use Corrai\Model\BaseAssessment;
use Corrai\Model\BaseFile;

/**
 * Snapshots pushed over SSE while an assessment or student page stays open.
 *
 * The assessment scope carries unassigned files (including their status), every
 * student (status and mark), and the assessment mark statistics. The student
 * scope carries that student's files and attributes.
 */
class AssessmentEventFeed
{
    private const SUBJECT_TYPES = ['subject', 'solution', 'instructions'];

    /**
     * @return array<string, mixed>
     */
    public static function assessmentSnapshot(BaseAssessment $assessment, ?string $locale): array
    {
        return self::assessmentPayload(
            $assessment->list_files($locale),
            $assessment->list_students($locale),
            [
                'assessed_students_number' => $assessment->assessed_students_number,
                'mark_average' => $assessment->mark_average,
                'mark_min' => $assessment->mark_min,
                'mark_max' => $assessment->mark_max,
            ]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $students
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    public static function assessmentPayload(array $files, array $students, array $stats): array
    {
        return [
            'scope' => 'assessment',
            'files' => self::unassignedFiles($files),
            'students' => array_values($students),
            'assessed_students_number' => $stats['assessed_students_number'] ?? null,
            'mark_average' => $stats['mark_average'] ?? null,
            'mark_min' => $stats['mark_min'] ?? null,
            'mark_max' => $stats['mark_max'] ?? null,
        ];
    }

    /**
     * Fields of one file that a pipeline step can change.
     *
     * @return array<string, mixed>
     */
    public static function changedFile(BaseFile $file, ?string $studentName): array
    {
        return [
            'id' => $file->id,
            'status' => $file->status,
            'status_label' => $file->get_status_label(null),
            'type' => $file->type,
            'student' => $file->student,
            'student_name' => $studentName,
        ];
    }

    /**
     * File, linked student and mark statistics, for a before/after comparison.
     *
     * @return array{file: array<string, mixed>, student: array<string, mixed>|null, stats: array<string, mixed>}
     */
    public static function state(BaseAssessment $assessment, BaseFile $file): array
    {
        $student = self::changedStudent($assessment, $file, true);
        return [
            'file' => self::changedFile($file, is_array($student) ? (string) $student['name'] : null),
            'student' => $student,
            'stats' => [
                'assessed_students_number' => $assessment->assessed_students_number,
                'mark_average' => $assessment->mark_average,
                'mark_min' => $assessment->mark_min,
                'mark_max' => $assessment->mark_max,
            ],
        ];
    }

    /**
     * Event carrying only the fields that differ. Null when nothing changed.
     *
     * @param array{file?: array<string, mixed>, student?: array<string, mixed>|null, stats?: array<string, mixed>}|null $before
     * @param array{file: array<string, mixed>, student: array<string, mixed>|null, stats: array<string, mixed>} $after
     * @return array<string, mixed>|null
     */
    public static function delta(string $scope, ?array $before, array $after): ?array
    {
        $payload = ['scope' => $scope];
        $beforeFile = is_array($before) && is_array($before['file'] ?? null) ? $before['file'] : null;
        $fileDiff = self::diffMap($beforeFile, $after['file'], ['status', 'status_label', 'type', 'student', 'student_name']);
        if ($fileDiff !== []) {
            $fileDiff = ['id' => $after['file']['id']] + $fileDiff;
            $payload['file'] = $fileDiff;
        }

        $afterStudent = $after['student'] ?? null;
        if (is_array($afterStudent)) {
            $beforeStudent = is_array($before) && is_array($before['student'] ?? null) ? $before['student'] : null;
            $sameStudent = is_array($beforeStudent) && ($beforeStudent['id'] ?? null) === ($afterStudent['id'] ?? null);
            $studentKeys = ['name', 'status', 'mark'];
            if ($scope === 'student') {
                $studentKeys[] = 'appreciation';
            }
            $studentDiff = self::diffMap($sameStudent ? $beforeStudent : null, $afterStudent, $studentKeys);
            if ($studentDiff !== []) {
                $payload['student'] = ['id' => $afterStudent['id']] + $studentDiff;
            }
        }

        $beforeStats = is_array($before) && is_array($before['stats'] ?? null) ? $before['stats'] : null;
        if ($scope === 'assessment' && is_array($beforeStats)) {
            foreach (self::diffMap($beforeStats, $after['stats'], array_keys($after['stats'])) as $key => $value) {
                $payload[$key] = $value;
            }
        }

        return count($payload) === 1 ? null : $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function changedStudent(BaseAssessment $assessment, BaseFile $file, bool $withAppreciation): ?array
    {
        $studentId = trim((string) ($file->student ?? ''));
        if ($studentId === '') {
            return null;
        }
        try {
            $student = $assessment->getStudent($studentId);
        } catch (\Throwable $e) {
            return null;
        }
        $payload = [
            'id' => $student->id,
            'name' => $student->name,
            'status' => $student->status,
            'mark' => $student->mark,
        ];
        if ($withAppreciation) {
            $payload['appreciation'] = $student->appreciation;
        }
        return $payload;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed> $after
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function diffMap(?array $before, array $after, array $keys): array
    {
        $diff = [];
        foreach ($keys as $key) {
            $next = $after[$key] ?? null;
            if ($before === null || !self::sameValue($before[$key] ?? null, $next)) {
                $diff[$key] = $next;
            }
        }
        return $diff;
    }

    private static function sameValue(mixed $left, mixed $right): bool
    {
        if ($left === $right) {
            return true;
        }
        if (is_numeric($left) && is_numeric($right)) {
            return abs((float) $left - (float) $right) < 0.0000001;
        }
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function studentSnapshot(BaseAssessment $assessment, string $studentId, ?string $locale): array
    {
        $student = null;
        foreach ($assessment->list_students($locale) as $item) {
            if (($item['id'] ?? '') === $studentId) {
                $student = $item;
                break;
            }
        }

        return self::studentPayload($assessment->list_files($locale), $student, $studentId);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<string, mixed>|null $student
     * @return array<string, mixed>
     */
    public static function studentPayload(array $files, ?array $student, string $studentId): array
    {
        return [
            'scope' => 'student',
            'student' => $student,
            'files' => self::studentFiles($files, $studentId),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    public static function unassignedFiles(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            if (self::isUnassigned($file)) {
                $out[] = $file;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $file
     */
    public static function isUnassigned(array $file): bool
    {
        $type = (string) ($file['type'] ?? '');
        if (in_array($type, self::SUBJECT_TYPES, true)) {
            return false;
        }
        return trim((string) ($file['student'] ?? '')) === '';
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    public static function studentFiles(array $files, string $studentId): array
    {
        $out = [];
        foreach ($files as $file) {
            if ((string) ($file['student'] ?? '') === $studentId) {
                $out[] = $file;
            }
        }
        return $out;
    }
}
