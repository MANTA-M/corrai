<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Exam;
use Corrai\HashId;
use Corrai\ObjectStore;
use Corrai\School;
use Corrai\User;
use Corrai\WSException;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for exam CRUD and unassigned file attach/detach.
 *
 * Uses the fixed "IND" (Independent) school and a disposable teacher user.
 * Requires MinIO reachable via S3_* env (docker compose php + minio).
 */
class ExamLifecycleTest extends TestCase
{
    private static School $indSchool;
    private User $user;
    /** @var string[] temp files created during the test */
    private array $tempFiles = [];

    public static function setUpBeforeClass(): void
    {
        self::$indSchool = School::ensureIndependent();
    }

    protected function setUp(): void
    {
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
        // --- Create ---
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
        $this->assertSame('Mathematics', $loaded->subject);
        $this->assertSame('2026-06-15', $loaded->date);

        // --- Listing ---
        $listed = Exam::list_for_author($this->user->id);
        $this->assertCount(1, $listed);
        $this->assertSame($examId, $listed[0]['id']);
        $this->assertSame('Math Exam', $listed[0]['name']);

        $viaUser = $this->user->exams();
        $this->assertCount(1, $viaUser);
        $this->assertSame($examId, $viaUser[0]->id);

        // --- Modification ---
        $loaded->name = 'Math Exam Updated';
        $loaded->subject = 'Algebra';
        $loaded->date = '2026-09-01';
        $loaded->save();

        $updated = Exam::from_hash($examId);
        $this->assertSame('Math Exam Updated', $updated->name);
        $this->assertSame('Algebra', $updated->subject);
        $this->assertSame('2026-09-01', $updated->date);

        $listedAfterUpdate = Exam::list_for_author($this->user->id);
        $this->assertCount(1, $listedAfterUpdate);
        $this->assertSame('Math Exam Updated', $listedAfterUpdate[0]['name']);

        // --- Add files (random dummy content) ---
        $store = ObjectStore::getInstance();
        $file1 = $this->createRandomTempFile('paper_', '.txt');
        $file2 = $this->createRandomTempFile('scan_', '.bin');
        $name1 = basename($file1);
        $name2 = basename($file2);

        $store->put($updated->unassignedFileKey($name1), $file1, 'text/plain');
        $store->put($updated->unassignedFileKey($name2), $file2, 'application/octet-stream');

        $files = $updated->list_files();
        $this->assertCount(2, $files);
        $names = array_column($files, 'name');
        sort($names);
        $expected = [$name1, $name2];
        sort($expected);
        $this->assertSame($expected, $names);

        $this->assertTrue($store->exists($updated->unassignedFileKey($name1)));
        $this->assertTrue($store->exists($updated->unassignedFileKey($name2)));

        // --- Remove one file ---
        $store->delete($updated->unassignedFileKey($name1));
        $filesAfterDelete = $updated->list_files();
        $this->assertCount(1, $filesAfterDelete);
        $this->assertSame($name2, $filesAfterDelete[0]['name']);
        $this->assertFalse($store->exists($updated->unassignedFileKey($name1)));
        $this->assertTrue($store->exists($updated->unassignedFileKey($name2)));

        // --- Delete exam (prefix + id pointer) ---
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

        // Cleanup exams explicitly (tearDown also deletes the user)
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

        $store = ObjectStore::getInstance();
        $tmp = $this->createRandomTempFile('unassigned_', '.pdf');
        $filename = basename($tmp);
        $key = $exam->unassignedFileKey($filename);

        $store->put($key, $tmp, 'application/pdf');
        $files = $exam->list_files();
        $this->assertCount(1, $files);
        $this->assertSame($filename, $files[0]['name']);
        $this->assertGreaterThan(0, $files[0]['size']);

        $store->delete($key);
        $this->assertSame([], $exam->list_files());
        $this->assertFalse($store->exists($key));

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
            $this->assertTrue($store->exists(ObjectStore::userCsvKey(School::IND_SCHOOL_ID, $user->id)));
            $this->assertTrue($store->exists(ObjectStore::idIndexKey($user->id)));
            $this->assertSame(
                ObjectStore::userPrefix(School::IND_SCHOOL_ID, $user->id),
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
                $store->exists(ObjectStore::examCsvKey(School::IND_SCHOOL_ID, $user->id, $exam->id))
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

    /**
     * Create a temp file with random bytes; tracked for tearDown cleanup.
     */
    private function createRandomTempFile(string $prefix, string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertNotFalse($path);

        // tempnam has no extension; rename so basename is meaningful for S3 keys.
        $named = $path . $suffix;
        $this->assertTrue(rename($path, $named));

        $bytes = random_bytes(random_int(32, 256));
        $this->assertNotFalse(file_put_contents($named, $bytes));

        $this->tempFiles[] = $named;
        return $named;
    }
}
