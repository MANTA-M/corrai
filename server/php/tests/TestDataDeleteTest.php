<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Assessment;
use Corrai\Model\School;
use Corrai\Model\User;
use Corrai\Utils\HashId;
use Corrai\Utils\TestData;
use Corrai\Utils\WSException;
use PHPUnit\Framework\TestCase;

class TestDataDeleteTest extends TestCase
{
    public function testDeleteAllRemovesOnlyMarkedNames(): void
    {
        $markedSchool = new School();
        $markedSchool->id = HashId::create();
        $markedSchool->name = '[Test] School';
        $markedSchool->save();

        $keptSchool = new School();
        $keptSchool->id = HashId::create();
        $keptSchool->name = 'Real School';
        $keptSchool->save();

        $ind = School::ensureIndependent();

        try {
            $markedTeacher = $markedSchool->addTeacher('[Test] Teacher');
            $keptTeacher = $keptSchool->addTeacher('Real Teacher');
            $markedUser = $ind->addUser(
                'marked_' . bin2hex(random_bytes(3)) . '@ind.test',
                '[Test] Independent',
                'secret',
                User::ROLE_TEACHER
            );
            $keptUser = $ind->addUser(
                'kept_' . bin2hex(random_bytes(3)) . '@ind.test',
                'Real Independent',
                'secret',
                User::ROLE_TEACHER
            );

            $orphanAssessment = new Assessment();
            $orphanAssessment->school_id = $keptUser->school_id;
            $orphanAssessment->user_id = $keptUser->id;
            $orphanAssessment->name = '[Test] Orphan Assessment';
            $orphanAssessment->subject = 'Math';
            $orphanAssessment->date = '2026-01-01';
            $orphanAssessment->id = HashId::create();
            $orphanAssessment->save();

            $keptAssessment = new Assessment();
            $keptAssessment->school_id = $keptUser->school_id;
            $keptAssessment->user_id = $keptUser->id;
            $keptAssessment->name = 'Real Assessment';
            $keptAssessment->subject = 'Math';
            $keptAssessment->date = '2026-01-02';
            $keptAssessment->id = HashId::create();
            $keptAssessment->save();
            $markedStudent = $keptAssessment->createStudent('[Test] Pupil');
            $keptStudent = $keptAssessment->createStudent('Real Pupil');

            $deleted = TestData::deleteAll();

            $this->assertSame(1, $deleted['schools']);
            $this->assertSame(1, $deleted['users']);
            $this->assertSame(1, $deleted['assessments']);
            $this->assertSame(1, $deleted['students']);
            $this->assertSame([], $deleted['errors']);

            $this->assertFalse($this->exists(fn () => School::from_hash((string) $markedSchool->id)));
            $this->assertFalse($this->exists(fn () => User::from_hash((string) $markedTeacher->id)));
            $this->assertFalse($this->exists(fn () => User::from_hash((string) $markedUser->id)));
            $this->assertFalse($this->exists(fn () => Assessment::from_hash((string) $orphanAssessment->id)));

            $reloadedSchool = School::from_hash((string) $keptSchool->id);
            $this->assertSame('Real School', $reloadedSchool->name);
            $this->assertSame('Real Teacher', User::from_hash((string) $keptTeacher->id)->name);
            $this->assertSame('Real Independent', User::from_hash((string) $keptUser->id)->name);

            $reloadedAssessment = Assessment::from_hash((string) $keptAssessment->id);
            $names = array_column($reloadedAssessment->list_students(), 'name');
            $this->assertSame(['Real Pupil'], $names);
            $this->assertNotContains('[Test] Pupil', $names);
            $this->assertSame($keptStudent->id, $reloadedAssessment->list_students()[0]['id']);
            $this->assertNotSame($markedStudent->id, $reloadedAssessment->list_students()[0]['id']);

            $indSchool = School::from_hash(School::IND_SCHOOL_ID);
            $this->assertFalse(TestData::isMarked($indSchool->name));
        } finally {
            foreach ([$keptSchool] as $school) {
                try {
                    $school->delete();
                } catch (\Throwable $e) {
                }
            }
            foreach ([$keptUser] as $user) {
                if ($user->id === null) {
                    continue;
                }
                try {
                    User::from_hash($user->id)->delete();
                } catch (\Throwable $e) {
                }
            }
        }
    }

    public function testDeleteEndpoint(): void
    {
        $school = new School();
        $school->id = HashId::create();
        $school->name = '[Test] Endpoint School';
        $school->save();

        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        unset($_REQUEST['output']);

        ob_start();
        include dirname(__DIR__) . '/api/delete_test_data.php';
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertSame('Test data deleted', $data['message'] ?? null);
        $this->assertGreaterThanOrEqual(1, $data['schools'] ?? 0);
        $this->assertFalse($this->exists(fn () => School::from_hash((string) $school->id)));
    }

    private function exists(callable $load): bool
    {
        try {
            $load();
            return true;
        } catch (WSException | \Exception $e) {
            return false;
        }
    }
}
