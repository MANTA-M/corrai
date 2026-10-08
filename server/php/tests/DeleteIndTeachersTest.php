<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\School;
use Corrai\Model\User;
use Corrai\Utils\Http\WSException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/cli/delete_ind_teachers.php';

class DeleteIndTeachersTest extends TestCase
{
    public function testDeleteIndTeachersDryRunDoesNotDelete(): void
    {
        $uniqueSuffix = bin2hex(random_bytes(4));
        $prefix = "Teacher_UnitTest_{$uniqueSuffix}";

        $teacher = School::addIndependentUser("{$prefix} Target");
        $teacherId = (string) $teacher->id;

        try {
            $result = delete_ind_teachers($prefix, true, fn () => null);

            $this->assertSame(1, $result['matched']);
            $this->assertSame(0, $result['deleted']);
            $this->assertEmpty($result['errors']);

            // Teacher must still exist
            $loaded = User::from_hash($teacherId);
            $this->assertSame("{$prefix} Target", $loaded->name);
        } finally {
            try {
                $teacher = User::from_hash($teacherId);
                $teacher->delete();
            } catch (\Throwable $e) {
                // Ignore cleanup error if already deleted
            }
        }
    }

    public function testDeleteIndTeachersDeletesOnlyMatchingPrefix(): void
    {
        $uniqueSuffix = bin2hex(random_bytes(4));
        $prefix = "Teacher_UnitTest_{$uniqueSuffix}";

        $matchingTeacher = School::addIndependentUser("{$prefix} Target");
        $nonMatchingTeacher = School::addIndependentUser("Other_UnitTest_{$uniqueSuffix} Kept");

        $matchingId = (string) $matchingTeacher->id;
        $nonMatchingId = (string) $nonMatchingTeacher->id;

        try {
            $result = delete_ind_teachers($prefix, false, fn () => null);

            $this->assertSame(1, $result['matched']);
            $this->assertSame(1, $result['deleted']);
            $this->assertEmpty($result['errors']);

            // Matching teacher must be deleted
            $deleted = false;
            try {
                User::from_hash($matchingId);
            } catch (WSException $e) {
                $deleted = true;
            }
            $this->assertTrue($deleted, 'Matching teacher should have been deleted');

            // Non-matching teacher must still exist
            $kept = User::from_hash($nonMatchingId);
            $this->assertSame("Other_UnitTest_{$uniqueSuffix} Kept", $kept->name);
        } finally {
            try {
                $nonMatchingTeacher = User::from_hash($nonMatchingId);
                $nonMatchingTeacher->delete();
            } catch (\Throwable $e) {
                // Ignore
            }
        }
    }
}
