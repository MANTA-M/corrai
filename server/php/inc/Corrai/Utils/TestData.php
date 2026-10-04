<?php

namespace Corrai\Utils;

use Corrai\Model\School;
use Throwable;

/**
 * Names created by tests include this marker so they can be deleted together.
 */
class TestData
{
    public const MARKER = '[Test]';

    public static function isMarked(string $name): bool
    {
        return str_contains($name, self::MARKER);
    }

    /**
     * Delete schools, users, assessments and students whose name contains [Test].
     * The Independent school itself is kept. Deleting a parent removes its children,
     * which are not counted again.
     *
     * @return array{schools: int, users: int, assessments: int, students: int, errors: list<string>}
     */
    public static function deleteAll(): array
    {
        $deleted = [
            'schools' => 0,
            'users' => 0,
            'assessments' => 0,
            'students' => 0,
            'errors' => [],
        ];

        foreach (School::all() as $school) {
            if ($school->id === School::IND_SCHOOL_ID || !self::isMarked($school->name)) {
                continue;
            }
            try {
                $school->delete();
                $deleted['schools']++;
            } catch (Throwable $e) {
                $deleted['errors'][] = 'school ' . ($school->id ?? '') . ': ' . $e->getMessage();
            }
        }

        foreach (School::all() as $school) {
            foreach ($school->users() as $user) {
                if (!self::isMarked($user->name)) {
                    continue;
                }
                try {
                    $user->delete();
                    $deleted['users']++;
                } catch (Throwable $e) {
                    $deleted['errors'][] = 'user ' . ($user->id ?? '') . ': ' . $e->getMessage();
                }
            }
        }

        foreach (School::all() as $school) {
            foreach ($school->users() as $user) {
                foreach ($user->assessments() as $assessment) {
                    if (self::isMarked($assessment->name)) {
                        try {
                            $assessment->delete();
                            $deleted['assessments']++;
                        } catch (Throwable $e) {
                            $deleted['errors'][] = 'assessment ' . ($assessment->id ?? '') . ': ' . $e->getMessage();
                        }
                        continue;
                    }

                    foreach ($assessment->listStudentModels() as $student) {
                        if (!self::isMarked($student->name) || $student->id === null) {
                            continue;
                        }
                        try {
                            $assessment->deleteStudent($student->id);
                            $deleted['students']++;
                        } catch (Throwable $e) {
                            $deleted['errors'][] = 'student ' . $student->id . ': ' . $e->getMessage();
                        }
                    }
                }
            }
        }

        return $deleted;
    }
}
