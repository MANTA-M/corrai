<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Exam;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\CsvStore;
use Corrai\Utils\CsvTreeMigrator;
use Corrai\Utils\HashId;
use Corrai\Utils\ObjectStore;
use Corrai\Model\School;
use Corrai\Model\User;
use Corrai\Utils\StoreConflictException;
use Corrai\Utils\WSException;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * Integration tests for exam CRUD and file attach/detach.
 *
 * Uses the fixed "IND" (Independent) school and a disposable teacher user.
 * Requires SeaweedFS reachable via S3_* env (docker compose php + seaweedfs).
 */
class ExamLifecycleTest extends TestCase
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
            "Test Teacher {$suffix}",
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

    public function testExamCreateModifyListAddAndRemoveFileThenDelete(): void
    {
        $exam = new Exam();
        $exam->school_id = $this->user->school_id;
        $exam->user_id = $this->user->id;
        $exam->name = 'Math Exam';
        $exam->subject = 'Mathematics';
        $exam->date = '2026-06-15';
        $exam->id = HashId::create();
        $exam->save();

        $examId = $exam->id;
        $this->assertNotNull($examId);
        $this->assertTrue(HashId::isValid($examId));

        $loaded = Exam::from_hash($examId);
        $this->assertSame($examId, $loaded->id);
        $this->assertSame(School::IND_SCHOOL_ID, $loaded->school_id);
        $this->assertSame($this->user->id, $loaded->user_id);
        $this->assertSame('Math Exam', $loaded->name);

        $listed = Exam::list_for_author($this->user->id);
        $this->assertCount(1, $listed);
        $this->assertSame($examId, $listed[0]['id']);

        $viaUser = $this->user->exams();
        $this->assertCount(1, $viaUser);
        $this->assertSame($examId, $viaUser[0]->id);

        $loaded->name = 'Math Exam Updated';
        $loaded->subject = 'Algebra';
        $loaded->date = '2026-09-01';
        $loaded->save();

        $updated = Exam::from_hash($examId);
        $this->assertSame('Math Exam Updated', $updated->name);
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

        $this->assertSame([], Exam::list_for_author($this->user->id));
        $this->assertFalse(
            ObjectStore::getInstance()->exists(ObjectStore::idIndexKey($examId))
        );

        try {
            Exam::from_hash($examId);
            $this->fail('Expected Exam::from_hash to throw after delete');
        } catch (\Exception $e) {
            $this->assertStringContainsString($examId, $e->getMessage());
        }
    }

    public function testListingEmptyThenMultipleExams(): void
    {
        $this->assertSame([], Exam::list_for_author($this->user->id));

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $exam = new Exam();
            $exam->school_id = $this->user->school_id;
            $exam->user_id = $this->user->id;
            $exam->name = "Exam $i";
            $exam->subject = 'History';
            $exam->date = sprintf('2026-01-%02d', $i + 1);
            $exam->id = HashId::create();
            $exam->save();
            $ids[] = $exam->id;
        }

        $listed = Exam::list_for_author($this->user->id);
        $this->assertCount(3, $listed);
        $listedIds = array_column($listed, 'id');
        sort($listedIds);
        $expectedIds = $ids;
        sort($expectedIds);
        $this->assertSame($expectedIds, $listedIds);

        foreach ($ids as $id) {
            Exam::from_hash($id)->delete();
        }
        $this->assertSame([], Exam::list_for_author($this->user->id));
    }

    public function testAddAndRemoveFileOnExam(): void
    {
        $exam = new Exam();
        $exam->school_id = $this->user->school_id;
        $exam->user_id = $this->user->id;
        $exam->name = 'File Ops Exam';
        $exam->subject = 'Physics';
        $exam->date = '2026-03-20';
        $exam->id = HashId::create();
        $exam->save();

        $this->assertSame([], $exam->list_files());

        $tmp = $this->createRandomTempFile('unassigned_', '.pdf');
        $filename = basename($tmp);
        $file = $exam->createFileFromPath($filename, $tmp, 'application/pdf', null, null);
        $files = $exam->list_files();
        $this->assertCount(1, $files);
        $this->assertSame($filename, $files[0]['name']);
        $this->assertSame($file->id, $files[0]['id']);
        $this->assertGreaterThan(0, $files[0]['size']);

        $exam->deleteFile($file->id);
        $this->assertSame([], $exam->list_files());
        $this->assertFalse(ObjectStore::getInstance()->exists($file->contentKey()));

        $exam->delete();
    }

    public function testFileTagsTypeAndStudent(): void
    {
        $exam = new Exam();
        $exam->school_id = $this->user->school_id;
        $exam->user_id = $this->user->id;
        $exam->name = 'Tagged Files Exam';
        $exam->subject = 'Biology';
        $exam->date = '2026-04-10';
        $exam->id = HashId::create();
        $exam->save();

        $tmp = $this->createRandomTempFile('tagged_', '.pdf');
        $filename = basename($tmp);
        $file = $exam->createFileFromPath($filename, $tmp, 'application/pdf', null, null);
        $alice = $exam->createStudent('Alice');

        $files = $exam->list_files();
        $this->assertCount(1, $files);
        $this->assertSame('', $files[0]['type']);
        $this->assertNull($files[0]['student']);

        $exam->setFileTags($file->id, 'submission', $alice->id);
        $tagged = $exam->list_files();
        $this->assertSame('submission', $tagged[0]['type']);
        $this->assertSame($alice->id, $tagged[0]['student']);
        $this->assertSame('Alice', $tagged[0]['student_name']);

        $exam->setFileTags($file->id, 'subject', null);
        $retyped = $exam->list_files();
        $this->assertSame('subject', $retyped[0]['type']);
        $this->assertSame($alice->id, $retyped[0]['student']);

        $exam->setFileTags($file->id, 'unknown', '');
        $cleared = $exam->list_files();
        $this->assertSame('', $cleared[0]['type']);
        $this->assertNull($cleared[0]['student']);

        try {
            $exam->setFileTags($file->id, 'not-a-type', null);
            $this->fail('Expected invalid file type to throw');
        } catch (WSException $e) {
            $this->assertSame(400, $e->getCode());
        }

        $exam->delete();
    }

    public function testRenameFileUpdatesDisplayNameOnly(): void
    {
        $exam = new Exam();
        $exam->school_id = $this->user->school_id;
        $exam->user_id = $this->user->id;
        $exam->name = 'Rename File Exam';
        $exam->subject = 'Chemistry';
        $exam->date = '2026-07-12';
        $exam->id = HashId::create();
        $exam->save();

        $tmp = $this->createRandomTempFile('rename_', '.png');
        $oldName = basename($tmp);
        $newName = 'renamed-scan.png';
        $file = $exam->createFileFromPath($oldName, $tmp, 'image/png', null, null);
        $bob = $exam->createStudent('Bob');
        $exam->setFileTags($file->id, 'submission', $bob->id);

        $contentBefore = ObjectStore::getInstance()->getContents($file->contentKey());
        $files = $exam->renameFile($file->id, $newName);
        $this->assertCount(1, $files);
        $this->assertSame($newName, $files[0]['name']);
        $this->assertSame($file->id, $files[0]['id']);
        $this->assertSame('submission', $files[0]['type']);
        $this->assertSame($bob->id, $files[0]['student']);
        $this->assertSame(
            $contentBefore,
            ObjectStore::getInstance()->getContents($file->contentKey())
        );

        try {
            $exam->renameFile($file->id, $newName . '/evil');
            $this->fail('Expected invalid renamed path to throw');
        } catch (WSException $e) {
            $this->assertSame(400, $e->getCode());
        }

        $exam->delete();
    }

    public function testWriteFileContentsOverwritesExistingFile(): void
    {
        $exam = new Exam();
        $exam->school_id = $this->user->school_id;
        $exam->user_id = $this->user->id;
        $exam->name = 'Instruction File Exam';
        $exam->subject = 'Physics';
        $exam->date = '2026-08-20';
        $exam->id = HashId::create();
        $exam->save();

        $tmp = $this->createRandomTempFile('consigne_', '.txt');
        $filename = basename($tmp);
        $file = $exam->createFileFromPath($filename, $tmp, 'text/plain', 'instructions', null);

        $files = $exam->writeFileContents($file->id, "Bring a calculator.\n");
        $this->assertCount(1, $files);
        $this->assertSame($filename, $files[0]['name']);
        $this->assertSame('instructions', $files[0]['type']);
        $this->assertSame(
            "Bring a calculator.\n",
            ObjectStore::getInstance()->getContents($file->contentKey())
        );

        try {
            $exam->writeFileContents('missing1', 'nope');
            $this->fail('Expected missing file to throw');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }

        $exam->delete();
    }

    public function testCreateCorrectionFilesWithStudentHashAndUniqueNames(): void
    {
        $exam = new Exam();
        $exam->school_id = $this->user->school_id;
        $exam->user_id = $this->user->id;
        $exam->name = 'Correction Files Exam';
        $exam->subject = 'History';
        $exam->date = '2026-09-21';
        $exam->id = HashId::create();
        $exam->save();

        $tmp = $this->createRandomTempFile('copy_', '.png');
        $filename = basename($tmp);
        $submission = $exam->createFileFromPath($filename, $tmp, 'image/png', 'submission', null);
        $carol = $exam->createStudent('Carol');
        $dan = $exam->createStudent('Dan');
        $exam->setFileTags($submission->id, 'submission', $carol->id);

        $files = $exam->createFile(
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

        $again = $exam->createFile(
            'copy_correction.txt',
            "Mark: 15/20",
            'text/plain; charset=utf-8',
            'correction',
            $carol->id
        );
        $names = array_column($again, 'name');
        $this->assertContains('copy_correction.txt', $names);
        $this->assertContains('copy_correction_1.txt', $names);

        $exam->createFile(
            'copy_directives.php',
            '<?php $GD_directives = [];',
            'text/plain; charset=utf-8',
            'debug',
            $carol->id
        );
        $exam->createFile(
            'other_correction.txt',
            'other student',
            'text/plain; charset=utf-8',
            'correction',
            $dan->id
        );

        $exam->deleteFilesOfType('correction', $carol->id);
        $left = $exam->list_files();
        $leftNames = array_column($left, 'name');
        $this->assertNotContains('copy_correction.txt', $leftNames);
        $this->assertNotContains('copy_correction_1.txt', $leftNames);
        $this->assertContains('copy_directives.php', $leftNames);
        $this->assertContains('other_correction.txt', $leftNames);
        $this->assertContains($filename, $leftNames);

        $exam->deleteFilesOfType('debug', $carol->id);
        $afterDebug = array_column($exam->list_files(), 'name');
        $this->assertNotContains('copy_directives.php', $afterDebug);
        $this->assertContains('other_correction.txt', $afterDebug);

        $exam->delete();
    }

    public function testAddIndependentUserCreatesS3DirectoryAndCanOwnExam(): void
    {
        $user = School::addIndependentUser('Profile Teacher');

        try {
            $this->assertNotNull($user->id);
            $this->assertTrue(HashId::isValid($user->id));
            $this->assertSame(School::IND_SCHOOL_ID, $user->school_id);
            $this->assertSame(User::ROLE_TEACHER, $user->role);
            $this->assertSame('Profile Teacher', $user->name);
            $this->assertSame($user->id . '@ind.local', $user->email);

            $store = ObjectStore::getInstance();
            $this->assertTrue($store->exists(ObjectStore::teacherAttrKey(School::IND_SCHOOL_ID, $user->id)));
            $this->assertTrue($store->exists(ObjectStore::idIndexKey($user->id)));
            $this->assertSame(
                ObjectStore::teacherPrefix(School::IND_SCHOOL_ID, $user->id),
                $store->resolveIdPointer($user->id)
            );

            $exam = new Exam();
            $exam->school_id = $user->school_id;
            $exam->user_id = $user->id;
            $exam->name = 'Independent Exam';
            $exam->subject = 'Science';
            $exam->date = '2026-05-01';
            $exam->id = HashId::create();
            $exam->save();

            $this->assertTrue(
                $store->exists(ObjectStore::examAttrKey(School::IND_SCHOOL_ID, $user->id, $exam->id))
            );

            $listed = Exam::list_for_author($user->id);
            $this->assertCount(1, $listed);
            $this->assertSame($exam->id, $listed[0]['id']);

            $exam->delete();
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
        Exam::list_for_author($unknownId);
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
        $examId = HashId::create();

        $store->putContents(
            ObjectStore::legacySchoolCsvKey($schoolId),
            CsvStore::encode([
                'id' => $schoolId,
                'name' => 'Migrated School',
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
                'name' => 'Mig Teacher',
                'role' => 'teacher',
                'password_hash' => password_hash('x', PASSWORD_DEFAULT),
                'created_at' => '2026-01-01T00:00:00+00:00',
            ]),
            'text/csv'
        );
        $store->setIdPointer($userId, ObjectStore::legacyUserPrefix($schoolId, $userId));

        $store->putContents(
            ObjectStore::legacyExamCsvKey($schoolId, $userId, $examId),
            CsvStore::encode([
                'id' => $examId,
                'school_id' => $schoolId,
                'user_id' => $userId,
                'name' => 'Mig Exam',
                'subject' => 'Other',
                'country' => '',
                'level' => '',
                'date' => '2026-02-02',
                'created_at' => '2026-01-01T00:00:00+00:00',
            ]),
            'text/csv'
        );
        $store->setIdPointer($examId, ObjectStore::legacyExamPrefix($schoolId, $userId, $examId));

        $filename = 'scan.pdf';
        $store->putContents(
            ObjectStore::legacyExamFileKey($schoolId, $userId, $examId, $filename),
            '%PDF-mig',
            'application/pdf'
        );
        $store->putContents(
            ObjectStore::legacyExamFilesCsvKey($schoolId, $userId, $examId),
            CsvStore::encodeRows([
                ['name' => $filename, 'type' => 'submission', 'student' => 'Eve'],
            ]),
            'text/csv'
        );

        $logs = (new CsvTreeMigrator($store))->run([$schoolId]);
        $this->assertNotEmpty($logs);

        $this->assertFalse($store->exists(ObjectStore::legacySchoolCsvKey($schoolId)));
        $this->assertTrue($store->exists(ObjectStore::schoolAttrKey($schoolId)));

        $school = School::from_hash($schoolId);
        $this->assertSame('Migrated School', $school->name);

        $teacher = User::from_hash($userId);
        $this->assertSame('Mig Teacher', $teacher->name);
        $this->assertSame($schoolId, $teacher->school_id);

        $exam = Exam::from_hash($examId);
        $this->assertSame('Mig Exam', $exam->name);
        $files = $exam->list_files();
        $this->assertCount(1, $files);
        $this->assertSame($filename, $files[0]['name']);
        $this->assertSame('submission', $files[0]['type']);
        $this->assertSame('Eve', $files[0]['student_name']);
        $this->assertNotEmpty($files[0]['student']);

        $students = $exam->list_students();
        $this->assertCount(1, $students);
        $this->assertSame('Eve', $students[0]['name']);

        $school->delete();
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
