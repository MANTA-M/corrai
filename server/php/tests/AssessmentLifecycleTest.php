<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Assessment;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Store\CsvStore;
use Corrai\Utils\CsvTreeMigrator;
use Corrai\Utils\Store\HashId;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Model\School;
use Corrai\Model\User;
use Corrai\Utils\Store\StoreConflictException;
use Corrai\Utils\Http\WSException;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * Integration tests for assessment CRUD and file attach/detach.
 *
 * Uses the fixed "IND" (Independent) school and a disposable teacher user.
 * Requires SeaweedFS reachable via S3_* env (docker compose php + seaweedfs).
 */
class AssessmentLifecycleTest extends TestCase
{
    private static School $indSchool;
    private User $user;
    /** @var string[] temp files created during the test */
    private array $tempFiles = [];

    public static function setUpBeforeClass(): void
    {
        // Move any leftover CSV tree into the JSON layout before tests create data.
        (new CsvTreeMigrator())->run();
        self::$indSchool = School::ensureIndependent();
    }

    protected function setUp(): void
    {
        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lPush', 'brPop', 'connect'])
            ->getMock();
        $redis->method('lPush')->willReturn(1);
        RedisQueue::setInstance(new RedisQueue($redis));

        $suffix = bin2hex(random_bytes(4));
        $this->user = self::$indSchool->addUser(
            "teacher_{$suffix}@ind.test",
            "[Test] Teacher {$suffix}",
            'test-password-' . $suffix,
            User::ROLE_TEACHER
        );
        $this->assertNotNull($this->user->id);
        $this->assertSame(School::IND_SCHOOL_ID, $this->user->school_id);
    }

    protected function tearDown(): void
    {
        RedisQueue::setInstance(null);

        foreach ($this->tempFiles as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];

        if (isset($this->user) && $this->user->id !== null) {
            try {
                $this->user->delete();
            } catch (\Throwable $e) {
                // Best-effort cleanup
            }
        }
    }

    public function testAssessmentCreateModifyListAddAndRemoveFileThenDelete(): void
    {
        $assessment = new Assessment();
        $assessment->school_id = $this->user->school_id;
        $assessment->user_id = $this->user->id;
        $assessment->name = '[Test] Math Assessment';
        $assessment->subject = 'Mathematics';
        $assessment->date = '2026-06-15';
        $assessment->id = HashId::create();
        $assessment->save();

        $assessmentId = $assessment->id;
        $this->assertNotNull($assessmentId);
        $this->assertTrue(HashId::isValid($assessmentId));

        $loaded = Assessment::from_hash($assessmentId);
        $this->assertSame($assessmentId, $loaded->id);
        $this->assertSame(School::IND_SCHOOL_ID, $loaded->school_id);
        $this->assertSame($this->user->id, $loaded->user_id);
        $this->assertSame('[Test] Math Assessment', $loaded->name);

        $listed = Assessment::list_for_author($this->user->id);
        $this->assertCount(1, $listed);
        $this->assertSame($assessmentId, $listed[0]['id']);

        $viaUser = $this->user->assessments();
        $this->assertCount(1, $viaUser);
        $this->assertSame($assessmentId, $viaUser[0]->id);

        $loaded->name = '[Test] Math Assessment Updated';
        $loaded->subject = 'Algebra';
        $loaded->date = '2026-09-01';
        $loaded->save();

        $updated = Assessment::from_hash($assessmentId);
        $this->assertSame('[Test] Math Assessment Updated', $updated->name);
        $this->assertSame('Algebra', $updated->subject);

        $store = ObjectStore::getInstance();
        $file1 = $this->createRandomTempFile('paper_', '.txt');
        $file2 = $this->createRandomTempFile('scan_', '.bin');
        $name1 = basename($file1);
        $name2 = basename($file2);

        $created1 = $updated->createFileFromPath($name1, $file1, 'text/plain', null, null);
        $created2 = $updated->createFileFromPath($name2, $file2, 'application/octet-stream', null, null);

        $files = $updated->list_files();
        $this->assertCount(2, $files);
        $names = array_column($files, 'name');
        sort($names);
        $expected = [$name1, $name2];
        sort($expected);
        $this->assertSame($expected, $names);

        $this->assertTrue($store->exists($created1->contentKey()));
        $this->assertTrue($store->exists($created2->contentKey()));

        $updated->deleteFile($created1->id);
        $filesAfterDelete = $updated->list_files();
        $this->assertCount(1, $filesAfterDelete);
        $this->assertSame($name2, $filesAfterDelete[0]['name']);
        $this->assertFalse($store->exists($created1->contentKey()));
        $this->assertTrue($store->exists($created2->contentKey()));

        $updated->delete();

        $this->assertSame([], Assessment::list_for_author($this->user->id));
        $this->assertFalse(
            ObjectStore::getInstance()->exists(ObjectStore::idIndexKey($assessmentId))
        );

        try {
            Assessment::from_hash($assessmentId);
            $this->fail('Expected Assessment::from_hash to throw after delete');
        } catch (\Exception $e) {
            $this->assertStringContainsString($assessmentId, $e->getMessage());
        }
    }

    public function testListingEmptyThenMultipleAssessments(): void
    {
        $this->assertSame([], Assessment::list_for_author($this->user->id));

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $assessment = new Assessment();
            $assessment->school_id = $this->user->school_id;
            $assessment->user_id = $this->user->id;
            $assessment->name = "[Test] Assessment $i";
            $assessment->subject = 'History';
            $assessment->date = sprintf('2026-01-%02d', $i + 1);
            $assessment->id = HashId::create();
            $assessment->save();
            $ids[] = $assessment->id;
        }

        $listed = Assessment::list_for_author($this->user->id);
        $this->assertCount(3, $listed);
        $listedIds = array_column($listed, 'id');
        sort($listedIds);
        $expectedIds = $ids;
        sort($expectedIds);
        $this->assertSame($expectedIds, $listedIds);

        foreach ($ids as $id) {
            Assessment::from_hash($id)->delete();
        }
        $this->assertSame([], Assessment::list_for_author($this->user->id));
    }

    public function testAddAndRemoveFileOnAssessment(): void
    {
        $assessment = new Assessment();
        $assessment->school_id = $this->user->school_id;
        $assessment->user_id = $this->user->id;
        $assessment->name = '[Test] File Ops Assessment';
        $assessment->subject = 'Physics';
        $assessment->date = '2026-03-20';
        $assessment->id = HashId::create();
        $assessment->save();

        $this->assertSame([], $assessment->list_files());

        $tmp = $this->createRandomTempFile('unassigned_', '.pdf');
        $filename = basename($tmp);
        $file = $assessment->createFileFromPath($filename, $tmp, 'application/pdf', null, null);
        $files = $assessment->list_files();
        $this->assertCount(1, $files);
        $this->assertSame($filename, $files[0]['name']);
        $this->assertSame($file->id, $files[0]['id']);
        $this->assertGreaterThan(0, $files[0]['size']);

        $assessment->deleteFile($file->id);
        $this->assertSame([], $assessment->list_files());
        $this->assertFalse(ObjectStore::getInstance()->exists($file->contentKey()));

        $assessment->delete();
    }

    public function testFileTagsTypeAndStudent(): void
    {
        $assessment = new Assessment();
        $assessment->school_id = $this->user->school_id;
        $assessment->user_id = $this->user->id;
        $assessment->name = '[Test] Tagged Files Assessment';
        $assessment->subject = 'Biology';
        $assessment->date = '2026-04-10';
        $assessment->id = HashId::create();
        $assessment->save();

        $tmp = $this->createRandomTempFile('tagged_', '.pdf');
        $filename = basename($tmp);
        $file = $assessment->createFileFromPath($filename, $tmp, 'application/pdf', null, null);
        $alice = $assessment->createStudent('[Test] Alice');

        $files = $assessment->list_files();
        $this->assertCount(1, $files);
        $this->assertSame('', $files[0]['type']);
        $this->assertNull($files[0]['student']);

        $assessment->setFileTags($file->id, 'submission', $alice->id);
        $tagged = $assessment->list_files();
        $this->assertSame('submission', $tagged[0]['type']);
        $this->assertSame($alice->id, $tagged[0]['student']);
        $this->assertSame('[Test] Alice', $tagged[0]['student_name']);

        $assessment->setFileTags($file->id, 'subject', null);
        $retyped = $assessment->list_files();
        $this->assertSame('subject', $retyped[0]['type']);
        $this->assertSame($alice->id, $retyped[0]['student']);

        $assessment->setFileTags($file->id, 'unknown', '');
        $cleared = $assessment->list_files();
        $this->assertSame('', $cleared[0]['type']);
        $this->assertNull($cleared[0]['student']);

        try {
            $assessment->setFileTags($file->id, 'not-a-type', null);
            $this->fail('Expected invalid file type to throw');
        } catch (WSException $e) {
            $this->assertSame(400, $e->getCode());
        }

        $assessment->delete();
    }

    public function testRenameFileUpdatesDisplayNameOnly(): void
    {
        $assessment = new Assessment();
        $assessment->school_id = $this->user->school_id;
        $assessment->user_id = $this->user->id;
        $assessment->name = '[Test] Rename File Assessment';
        $assessment->subject = 'Chemistry';
        $assessment->date = '2026-07-12';
        $assessment->id = HashId::create();
        $assessment->save();

        $tmp = $this->createRandomTempFile('rename_', '.png');
        $oldName = basename($tmp);
        $newName = 'renamed-scan.png';
        $file = $assessment->createFileFromPath($oldName, $tmp, 'image/png', null, null);
        $bob = $assessment->createStudent('[Test] Bob');
        $assessment->setFileTags($file->id, 'submission', $bob->id);
        $stored = $assessment->getFile($file->id);

        $contentBefore = ObjectStore::getInstance()->getContents($stored->contentKey());
        $files = $assessment->renameFile($file->id, $newName);
        $this->assertCount(1, $files);
        $this->assertSame($newName, $files[0]['name']);
        $this->assertSame($file->id, $files[0]['id']);
        $this->assertSame('submission', $files[0]['type']);
        $this->assertSame($bob->id, $files[0]['student']);
        $this->assertSame(
            $contentBefore,
            ObjectStore::getInstance()->getContents($assessment->getFile($file->id)->contentKey())
        );

        try {
            $assessment->renameFile($file->id, $newName . '/evil');
            $this->fail('Expected invalid renamed path to throw');
        } catch (WSException $e) {
            $this->assertSame(400, $e->getCode());
        }

        $assessment->delete();
    }

    public function testWriteFileContentsOverwritesExistingFile(): void
    {
        $assessment = new Assessment();
        $assessment->school_id = $this->user->school_id;
        $assessment->user_id = $this->user->id;
        $assessment->name = '[Test] Instruction File Assessment';
        $assessment->subject = 'Physics';
        $assessment->date = '2026-08-20';
        $assessment->id = HashId::create();
        $assessment->save();

        $tmp = $this->createRandomTempFile('instructions_', '.md');
        $filename = basename($tmp);
        $file = $assessment->createFileFromPath($filename, $tmp, 'text/plain', 'instructions', null);

        $files = $assessment->writeFileContents($file->id, "Bring a calculator.\n");
        $this->assertCount(1, $files);
        $this->assertSame($filename, $files[0]['name']);
        $this->assertSame('instructions', $files[0]['type']);
        $this->assertSame(
            "Bring a calculator.\n",
            ObjectStore::getInstance()->getContents($file->contentKey())
        );

        try {
            $assessment->writeFileContents('missing1', 'nope');
            $this->fail('Expected missing file to throw');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }

        $assessment->delete();
    }

    public function testCreateCorrectionFilesWithStudentHashAndUniqueNames(): void
    {
        $assessment = new Assessment();
        $assessment->school_id = $this->user->school_id;
        $assessment->user_id = $this->user->id;
        $assessment->name = '[Test] Correction Files Assessment';
        $assessment->subject = 'History';
        $assessment->date = '2026-09-21';
        $assessment->id = HashId::create();
        $assessment->save();

        $tmp = $this->createRandomTempFile('copy_', '.png');
        $filename = basename($tmp);
        $submission = $assessment->createFileFromPath($filename, $tmp, 'image/png', 'submission', null);
        $carol = $assessment->createStudent('[Test] Carol');
        $dan = $assessment->createStudent('[Test] Dan');
        $assessment->setFileTags($submission->id, 'submission', $carol->id);

        $files = $assessment->createFile(
            'copy_correction.txt',
            "Mark: 14/20\nGood work.",
            'text/plain; charset=utf-8',
            'correction',
            $carol->id
        );
        $this->assertCount(2, $files);
        $text = array_values(array_filter($files, fn($f) => $f['name'] === 'copy_correction.txt'))[0];
        $this->assertSame('correction', $text['type']);
        $this->assertSame($carol->id, $text['student']);
        $store = ObjectStore::getInstance();
        $this->assertTrue($store->exists(ObjectStore::assessmentBlobKey(
            $assessment->school_id,
            $assessment->user_id,
            $assessment->id,
            $text['id']
        )));
        $this->assertFalse($store->exists(ObjectStore::assessmentFileAttrKey(
            $assessment->school_id,
            $assessment->user_id,
            $assessment->id,
            $text['id'],
            'correction',
            $carol->id
        )));

        $again = $assessment->createFile(
            'copy_correction.txt',
            "Mark: 15/20",
            'text/plain; charset=utf-8',
            'correction',
            $carol->id
        );
        $names = array_column($again, 'name');
        $this->assertContains('copy_correction.txt', $names);
        $this->assertContains('copy_correction_1.txt', $names);

        $assessment->createFile(
            'copy_annotations.php',
            '<?php $GD_annotations = [];',
            'text/plain; charset=utf-8',
            'debug',
            $carol->id
        );
        $assessment->createFile(
            'other_correction.txt',
            'other student',
            'text/plain; charset=utf-8',
            'correction',
            $dan->id
        );

        $assessment->deleteFilesOfType('correction', $carol->id);
        $left = $assessment->list_files();
        $leftNames = array_column($left, 'name');
        $this->assertNotContains('copy_correction.txt', $leftNames);
        $this->assertNotContains('copy_correction_1.txt', $leftNames);
        $this->assertContains('copy_annotations.php', $leftNames);
        $this->assertContains('other_correction.txt', $leftNames);
        $this->assertContains($filename, $leftNames);

        $assessment->deleteFilesOfType('debug', $carol->id);
        $afterDebug = array_column($assessment->list_files(), 'name');
        $this->assertNotContains('copy_annotations.php', $afterDebug);
        $this->assertContains('other_correction.txt', $afterDebug);

        $assessment->delete();
    }

    public function testAddIndependentUserCreatesS3DirectoryAndCanOwnAssessment(): void
    {
        $user = School::addIndependentUser('[Test] Profile Teacher');

        try {
            $this->assertNotNull($user->id);
            $this->assertTrue(HashId::isValid($user->id));
            $this->assertSame(School::IND_SCHOOL_ID, $user->school_id);
            $this->assertSame(User::ROLE_TEACHER, $user->role);
            $this->assertSame('[Test] Profile Teacher', $user->name);
            $this->assertSame($user->id . '@ind.local', $user->email);

            $store = ObjectStore::getInstance();
            $this->assertTrue($store->exists(ObjectStore::teacherAttrKey(School::IND_SCHOOL_ID, $user->id)));
            $this->assertTrue($store->exists(ObjectStore::idIndexKey($user->id)));
            $this->assertSame(
                ObjectStore::teacherPrefix(School::IND_SCHOOL_ID, $user->id),
                $store->resolveIdPointer($user->id)
            );

            $assessment = new Assessment();
            $assessment->school_id = $user->school_id;
            $assessment->user_id = $user->id;
            $assessment->name = '[Test] Independent Assessment';
            $assessment->subject = 'Science';
            $assessment->date = '2026-05-01';
            $assessment->id = HashId::create();
            $assessment->save();

            $this->assertTrue(
                $store->exists(ObjectStore::assessmentAttrKey(School::IND_SCHOOL_ID, $user->id, $assessment->id))
            );

            $listed = Assessment::list_for_author($user->id);
            $this->assertCount(1, $listed);
            $this->assertSame($assessment->id, $listed[0]['id']);

            $assessment->delete();
        } finally {
            if ($user->id !== null) {
                try {
                    $user->delete();
                } catch (\Throwable $e) {
                    // Best-effort cleanup
                }
            }
        }
    }

    public function testFromHashRejectsPublicKeyToken(): void
    {
        $this->expectException(WSException::class);
        $this->expectExceptionCode(401);
        User::from_hash(
            'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEO2M68bVhh9hununNXtZsqFnJeMjxIC+Vk5l7ncoMlXCrfFxkMp6OFEffvL/aiOyL1ND+rJZY+sfvEM1qaxNPug=='
        );
    }

    public function testListForAuthorUnknownUserThrowsUnauthorized(): void
    {
        $unknownId = '0' . bin2hex(random_bytes(3));
        $this->assertTrue(HashId::isValid($unknownId));
        $this->assertFalse(ObjectStore::getInstance()->exists(ObjectStore::idIndexKey($unknownId)));

        $this->expectException(WSException::class);
        $this->expectExceptionCode(401);
        Assessment::list_for_author($unknownId);
    }

    public function testIfMatchConditionalPut(): void
    {
        $store = ObjectStore::getInstance();
        $key = 'schools/' . School::IND_SCHOOL_ID . '/_if_match_test_' . bin2hex(random_bytes(4)) . '.json';

        try {
            $etag = $store->putJson($key, ['probe' => 1]);
            $this->assertNotNull($etag);

            $store->putJson($key, ['probe' => 2], $etag);
            $fresh = $store->getJson($key);
            $this->assertSame(2, $fresh['data']['probe']);

            try {
                $store->putJson($key, ['probe' => 3], $etag);
                $this->markTestIncomplete(
                    'SeaweedFS accepted a stale If-Match; conditional writes are not enforced'
                );
            } catch (StoreConflictException $e) {
                $this->assertSame(412, $e->getCode());
            }
        } finally {
            if ($store->exists($key)) {
                $store->delete($key);
            }
        }
    }

    public function testCsvTreeMigratorMovesLegacySchool(): void
    {
        $store = ObjectStore::getInstance();
        $suffix = bin2hex(random_bytes(3));
        $schoolId = 'Mig' . $suffix; // 9 chars; not under schools/
        $userId = HashId::create();
        $assessmentId = HashId::create();

        $store->putContents(
            ObjectStore::legacySchoolCsvKey($schoolId),
            CsvStore::encode([
                'id' => $schoolId,
                'name' => '[Test] Migrated School',
                'created_at' => '2026-01-01T00:00:00+00:00',
            ]),
            'text/csv'
        );
        $store->setIdPointer($schoolId, ObjectStore::legacySchoolPrefix($schoolId));

        $store->putContents(
            ObjectStore::legacyUserCsvKey($schoolId, $userId),
            CsvStore::encode([
                'id' => $userId,
                'school_id' => $schoolId,
                'email' => "$userId@mig.test",
                'name' => '[Test] Mig Teacher',
                'role' => 'teacher',
                'password_hash' => password_hash('x', PASSWORD_DEFAULT),
                'created_at' => '2026-01-01T00:00:00+00:00',
            ]),
            'text/csv'
        );
        $store->setIdPointer($userId, ObjectStore::legacyUserPrefix($schoolId, $userId));

        $store->putContents(
            ObjectStore::legacyAssessmentCsvKey($schoolId, $userId, $assessmentId),
            CsvStore::encode([
                'id' => $assessmentId,
                'school_id' => $schoolId,
                'user_id' => $userId,
                'name' => '[Test] Mig Assessment',
                'subject' => 'Other',
                'country' => '',
                'level' => '',
                'date' => '2026-02-02',
                'created_at' => '2026-01-01T00:00:00+00:00',
            ]),
            'text/csv'
        );
        $store->setIdPointer($assessmentId, ObjectStore::legacyAssessmentPrefix($schoolId, $userId, $assessmentId));

        $filename = 'scan.pdf';
        $store->putContents(
            ObjectStore::legacyAssessmentFileKey($schoolId, $userId, $assessmentId, $filename),
            '%PDF-mig',
            'application/pdf'
        );
        $store->putContents(
            ObjectStore::legacyAssessmentFilesCsvKey($schoolId, $userId, $assessmentId),
            CsvStore::encodeRows([
                ['name' => $filename, 'type' => 'submission', 'student' => '[Test] Eve'],
            ]),
            'text/csv'
        );

        $logs = (new CsvTreeMigrator($store))->run([$schoolId]);
        $this->assertNotEmpty($logs);

        $this->assertFalse($store->exists(ObjectStore::legacySchoolCsvKey($schoolId)));
        $this->assertTrue($store->exists(ObjectStore::schoolAttrKey($schoolId)));

        $school = School::from_hash($schoolId);
        $this->assertSame('[Test] Migrated School', $school->name);

        $teacher = User::from_hash($userId);
        $this->assertSame('[Test] Mig Teacher', $teacher->name);
        $this->assertSame($schoolId, $teacher->school_id);

        $assessment = Assessment::from_hash($assessmentId);
        $this->assertSame('[Test] Mig Assessment', $assessment->name);
        $files = $assessment->list_files();
        $this->assertCount(1, $files);
        $this->assertSame($filename, $files[0]['name']);
        $this->assertSame('submission', $files[0]['type']);
        $this->assertSame('[Test] Eve', $files[0]['student_name']);
        $this->assertNotEmpty($files[0]['student']);

        $students = $assessment->list_students();
        $this->assertCount(1, $students);
        $this->assertSame('[Test] Eve', $students[0]['name']);

        $school->delete();
    }

    public function testStudentCreateAndMarkChangeStoreAssessmentStats(): void
    {
        $assessment = new Assessment();
        $assessment->school_id = $this->user->school_id;
        $assessment->user_id = $this->user->id;
        $assessment->name = '[Test] Stats Assessment';
        $assessment->subject = 'Mathematics';
        $assessment->date = '2026-06-20';
        $assessment->id = HashId::create();
        $assessment->save();

        $alice = $assessment->createStudent('[Test] Alice', '', 10);
        $assessment->createStudent('[Test] Bob', '', 20);
        $assessment->createStudent('[Test] Cara');

        $loaded = Assessment::from_hash((string) $assessment->id);
        $this->assertSame(3, $loaded->assessed_students_number);
        $this->assertEquals(15.0, $loaded->mark_average);
        $this->assertEquals(10.0, $loaded->mark_min);
        $this->assertEquals(20.0, $loaded->mark_max);

        $output = $loaded->to_output();
        $this->assertSame(3, $output['assessed_students_number']);
        $this->assertEquals(15.0, $output['mark_average']);
        $this->assertEquals(10.0, $output['mark_min']);
        $this->assertEquals(20.0, $output['mark_max']);

        $bob = null;
        foreach ($loaded->listStudentModels() as $student) {
            if ($student->name === '[Test] Bob') {
                $bob = $student;
            }
        }
        $this->assertNotNull($bob);
        $bob->mark = 12.0;
        $bob->save();

        $reloaded = Assessment::from_hash((string) $assessment->id);
        $this->assertSame(3, $reloaded->assessed_students_number);
        $this->assertEquals(11.0, $reloaded->mark_average);
        $this->assertEquals(10.0, $reloaded->mark_min);
        $this->assertEquals(12.0, $reloaded->mark_max);

        $alice->mark = null;
        $alice->save();
        $cleared = Assessment::from_hash((string) $assessment->id);
        $this->assertEquals(12.0, $cleared->mark_average);
        $this->assertEquals(12.0, $cleared->mark_min);
        $this->assertEquals(12.0, $cleared->mark_max);

        $stale = Assessment::from_hash((string) $assessment->id);
        $stale->mark_average = null;
        $stale->mark_min = null;
        $stale->mark_max = null;
        $stale->assessed_students_number = null;
        $stale->save();
        $repaired = Assessment::from_hash((string) $assessment->id);
        $this->assertSame(3, $repaired->assessed_students_number);
        $this->assertEquals(12.0, $repaired->mark_average);
        $this->assertEquals(12.0, $repaired->mark_min);
        $this->assertEquals(12.0, $repaired->mark_max);

        $assessment->deleteStudent((string) $bob->id);
        $afterDelete = Assessment::from_hash((string) $assessment->id);
        $this->assertSame(2, $afterDelete->assessed_students_number);
        $this->assertNull($afterDelete->mark_average);
        $this->assertNull($afterDelete->mark_min);
        $this->assertNull($afterDelete->mark_max);

        $assessment->delete();
    }

    public function testDeleteStudentUnassignsFiles(): void
    {
        $assessment = new Assessment();
        $assessment->school_id = $this->user->school_id;
        $assessment->user_id = $this->user->id;
        $assessment->name = '[Test] Delete Student Assessment';
        $assessment->subject = 'History';
        $assessment->date = '2026-05-02';
        $assessment->id = HashId::create();
        $assessment->save();

        $tmp = $this->createRandomTempFile('copy_', '.png');
        $file = $assessment->createFileFromPath(basename($tmp), $tmp, 'image/png', 'submission', null);
        $alice = $assessment->createStudent('[Test] Alice');
        $assessment->setFileTags($file->id, 'submission', $alice->id);

        $files = $assessment->deleteStudent($alice->id);
        $this->assertCount(1, $files);
        $this->assertSame('submission', $files[0]['type']);
        $this->assertNull($files[0]['student']);
        $this->assertSame([], $assessment->list_students());

        $assessment->delete();
    }

    public function testTestCorrectionStartsUnclassifiedCopies(): void
    {
        $assessment = new \Corrai\Subject\Dictation\Assessment();
        $assessment->school_id = $this->user->school_id;
        $assessment->user_id = $this->user->id;
        $assessment->name = '[Test] Test Correction Assessment';
        $assessment->date = '2026-10-04';
        $assessment->id = HashId::create();
        $assessment->save();

        try {
            $assessment->testCorrection();
            $this->fail('Expected WSException when no copies are present');
        } catch (WSException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertSame('No copies to correct', $e->getMessage());
        }

        $tmp = $this->createRandomTempFile('copy_', '.txt');
        $file = $assessment->createFileFromPath(basename($tmp), $tmp, 'text/plain', 'submission', null);

        $menu = $assessment->to_output('fr')['menu'];
        $this->assertContains('test_correction', array_column($menu, 'key'));
        $testItem = array_values(array_filter($menu, fn($item) => $item['key'] === 'test_correction'))[0];
        $this->assertSame('Tester la correction', $testItem['label']);

        $files = $assessment->testCorrection();
        $this->assertNotEmpty($files);
        $reloaded = $assessment->getFile($file->id);
        $eventNames = array_column($reloaded->listEvents(), 'name');
        $this->assertContains('OCR queued', $eventNames);

        $assessment->delete();
    }

    public function testDictationStartCorrectionCoversEveryCopyAndTestCoversOne(): void
    {
        $assessment = new \Corrai\Subject\Dictation\Assessment();
        $assessment->school_id = $this->user->school_id;
        $assessment->user_id = $this->user->id;
        $assessment->name = '[Test] Dictation Full Correction Assessment';
        $assessment->date = '2026-10-04';
        $assessment->id = HashId::create();
        $assessment->save();

        $tmp1 = $this->createRandomTempFile('copy1_', '.txt');
        $file1 = $assessment->createFileFromPath(basename($tmp1), $tmp1, 'text/plain', 'submission', null);

        $tmp2 = $this->createRandomTempFile('copy2_', '.txt');
        $file2 = $assessment->createFileFromPath(basename($tmp2), $tmp2, 'text/plain', 'submission', null);

        $files = $assessment->startCorrection();
        $this->assertNotEmpty($files);

        $reloaded1 = $assessment->getFile($file1->id);
        $events1 = array_column($reloaded1->listEvents(), 'name');
        $reloaded2 = $assessment->getFile($file2->id);
        $events2 = array_column($reloaded2->listEvents(), 'name');

        $this->assertContains('OCR queued', $events1);
        $this->assertContains('OCR queued', $events2);

        $assessment->delete();

        $sample = new \Corrai\Subject\Dictation\Assessment();
        $sample->school_id = $this->user->school_id;
        $sample->user_id = $this->user->id;
        $sample->name = '[Test] Dictation Test Correction Assessment';
        $sample->date = '2026-10-04';
        $sample->id = HashId::create();
        $sample->save();

        $sample1 = $this->createRandomTempFile('sample1_', '.txt');
        $sampleFile1 = $sample->createFileFromPath(basename($sample1), $sample1, 'text/plain', 'submission', null);
        $sample2 = $this->createRandomTempFile('sample2_', '.txt');
        $sampleFile2 = $sample->createFileFromPath(basename($sample2), $sample2, 'text/plain', 'submission', null);

        $sample->testCorrection();

        $sampleEvents1 = array_column($sample->getFile($sampleFile1->id)->listEvents(), 'name');
        $sampleEvents2 = array_column($sample->getFile($sampleFile2->id)->listEvents(), 'name');
        $queuedCount = (in_array('OCR queued', $sampleEvents1, true) ? 1 : 0)
            + (in_array('OCR queued', $sampleEvents2, true) ? 1 : 0);
        $this->assertSame(1, $queuedCount, 'Test correction queues a single copy');

        $sample->delete();
    }

    /**
     * Create a temp file with random bytes; tracked for tearDown cleanup.
     */
    private function createRandomTempFile(string $prefix, string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertNotFalse($path);

        $named = $path . $suffix;
        $this->assertTrue(rename($path, $named));

        $bytes = random_bytes(random_int(32, 256));
        $this->assertNotFalse(file_put_contents($named, $bytes));

        $this->tempFiles[] = $named;
        return $named;
    }
}
