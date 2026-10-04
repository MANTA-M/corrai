<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\School;
use Corrai\Model\User;
use Corrai\Utils\HashId;
use Corrai\Utils\WSException;
use PHPUnit\Framework\TestCase;

class TeacherDeleteTest extends TestCase
{
    public function testDeleteTeacher(): void
    {
        $school = new School();
        $school->id = HashId::create();
        $school->name = '[Test] School For Delete';
        $school->save();

        try {
            $teacher = $school->addTeacher('[Test] Teacher');
            $teacherId = $teacher->id;
            $this->assertNotEmpty($teacherId);

            // Check teacher exists
            $loadedTeacher = User::from_hash($teacherId);
            $this->assertSame('[Test] Teacher', $loadedTeacher->name);

            // Delete teacher
            $loadedTeacher->delete();

            // Check teacher no longer exists
            $deleted = false;
            try {
                User::from_hash($teacherId);
            } catch (WSException $e) {
                $deleted = true;
            }
            $this->assertTrue($deleted);
        } finally {
            $school->delete();
        }
    }

    public function testDeleteTeacherEndpoint(): void
    {
        $school = new School();
        $school->id = HashId::create();
        $school->name = '[Test] School For Endpoint Delete';
        $school->save();

        try {
            $teacher = $school->addTeacher('[Test] Endpoint Teacher');
            $teacherId = $teacher->id;

            $_SERVER['REQUEST_METHOD'] = 'DELETE';
            $_REQUEST['hash'] = $teacherId;

            ob_start();
            include dirname(__DIR__) . '/api/delete_teacher.php';
            $output = ob_get_clean();

            $data = json_decode($output, true);
            $this->assertIsArray($data);
            $this->assertSame('Teacher deleted successfully', $data['message'] ?? null);

            $deleted = false;
            try {
                User::from_hash($teacherId);
            } catch (WSException $e) {
                $deleted = true;
            }
            $this->assertTrue($deleted);
        } finally {
            $school->delete();
        }
    }
}
