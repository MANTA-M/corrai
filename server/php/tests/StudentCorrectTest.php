<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\BaseAssessment;
use Corrai\Model\BaseStudent;
use Corrai\Model\S3File;
use Corrai\Model\School;
use Corrai\Model\User;
use Corrai\Queue\RedisConsumer;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\LawFrance\Assessment as LawAssessment;
use Corrai\Subject\LawFrance\Student as LawStudent;
use Corrai\Subject\LawFrance\StudentTask1Correcting;
use Corrai\Subject\LawFrance\Submission;
use Corrai\Subject\LawFrance\SubmissionTask1Ocr;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Store\ObjectStore;
use PHPUnit\Framework\TestCase;
use Redis;

class StudentCorrectTest extends TestCase
{
    /** @var list<string> */
    private array $queued = [];

    protected function setUp(): void
    {
        $this->installQueue();
    }

    protected function tearDown(): void
    {
        RedisQueue::setInstance(null);
    }

    public function testBaseStudentMenuHasCorrectItem(): void
    {
        $student = new LawStudent();
        $menuFr = $student->get_menu('fr');
        $this->assertSame(['view', 'transcribe', 'correct', 'rename', 'delete'], array_column($menuFr, 'key'));
        $this->assertSame('Transcription', $menuFr[1]['label']);
        $this->assertSame('text', $menuFr[1]['icon']);
        $this->assertSame('Corriger', $menuFr[2]['label']);
        $this->assertSame('check', $menuFr[2]['icon']);

        $menuEn = $student->get_menu('en');
        $this->assertSame('Transcription', $menuEn[1]['label']);
        $this->assertSame('Correct', $menuEn[2]['label']);
    }

    public function testLawFranceStudentInheritanceAndAlias(): void
    {
        $student = new LawStudent();
        $this->assertInstanceOf(BaseStudent::class, $student);
        $this->assertTrue(class_exists(\LawFrance\Student::class));
        $this->assertInstanceOf(LawStudent::class, new \LawFrance\Student());
    }

    public function testLawFranceAssessmentStudentClass(): void
    {
        $assessment = new LawAssessment();
        $this->assertSame(LawStudent::class, $assessment->studentClass());
    }

    public function testLawFranceStudentCorrectTriggersCorrectionOnSubmissions(): void
    {
        $school = new School();
        $school->name = 'Law School';
        $school->save();

        $teacher = new User();
        $teacher->school_id = $school->id;
        $teacher->name = 'Prof Law';
        $teacher->email = 'prof.law@example.com';
        $teacher->setPassword('secret123');
        $teacher->save();

        $assessment = new LawAssessment();
        $assessment->school_id = $school->id;
        $assessment->user_id = $teacher->id;
        $assessment->name = 'Law Exam';
        $assessment->save();

        $student = $assessment->createStudent('Alice');
        $this->assertInstanceOf(LawStudent::class, $student);

        // Create a submission assigned to this student
        $submission = $assessment->createFileModel(
            'copie1.png',
            'fake image content',
            'image/png',
            'submission',
            $student->id
        );

        $this->assertSame('stored', $submission->status);

        // Call correct on the student
        $this->queued = [];
        $corrected = $student->correct();
        $this->assertCount(1, $corrected);

        $reloadedStudent = $assessment->getStudent($student->id);
        $this->assertSame('under_correction', $reloadedStudent->status);

        $this->assertCount(1, $this->queued);
        $ticket = json_decode($this->queued[0], true);
        $this->assertSame($student->id, $ticket['student_id'] ?? null);
        $this->assertSame(StudentTask1Correcting::class, $ticket['task'] ?? null);
    }

    public function testLawFranceStudentTranscribeAsksCorrectionOnCopiesThatAreNotTranscribed(): void
    {
        $school = new School();
        $school->name = 'Law School Transcribe';
        $school->save();

        $teacher = new User();
        $teacher->school_id = $school->id;
        $teacher->name = 'Prof Transcribe';
        $teacher->email = 'prof.transcribe@example.com';
        $teacher->setPassword('secret123');
        $teacher->save();

        $assessment = new LawAssessment();
        $assessment->school_id = $school->id;
        $assessment->user_id = $teacher->id;
        $assessment->name = 'Law Transcribe Exam';
        $assessment->save();

        $student = $assessment->createStudent('Eve');

        $pending = $assessment->createFileModel(
            'pending.png',
            'fake image content',
            'image/png',
            'submission',
            $student->id
        );
        $done = $assessment->createFileModel(
            'done.png',
            'fake image content',
            'image/png',
            'submission',
            $student->id
        );
        $done->status = 'transcribed';
        $done->saveAttributes();

        $this->queued = [];
        $started = $student->transcribe();
        $this->assertCount(1, $started);
        $this->assertSame($pending->id, $started[0]->id);

        $reloadedPending = Submission::from_hash((string) $pending->id);
        $reloadedDone = Submission::from_hash((string) $done->id);
        $this->assertSame('transcribing', $reloadedPending->status);
        $this->assertSame('transcribed', $reloadedDone->status);

        $this->assertCount(1, $this->queued);
        $ticket = json_decode($this->queued[0], true);
        $this->assertSame($pending->id, $ticket['file_id'] ?? null);
        $this->assertSame(SubmissionTask1Ocr::class, $ticket['task'] ?? null);
    }

    public function testStudentCorrectWebService(): void
    {
        $school = new School();
        $school->name = 'Law School WS';
        $school->save();

        $teacher = new User();
        $teacher->school_id = $school->id;
        $teacher->name = 'Teacher WS';
        $teacher->email = 'teacher.ws@example.com';
        $teacher->setPassword('secret123');
        $teacher->save();

        $assessment = new LawAssessment();
        $assessment->school_id = $school->id;
        $assessment->user_id = $teacher->id;
        $assessment->name = 'Law WS Assessment';
        $assessment->save();

        $student = $assessment->createStudent('Bob');

        $submission = $assessment->createFileModel(
            'bob_copy.png',
            'fake image content',
            'image/png',
            'submission',
            $student->id
        );

        // Mock request environment for post_student_correct.php
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $teacher->id;
        $_REQUEST['id'] = $assessment->id;
        $_REQUEST['student'] = $student->id;
        $_REQUEST['locale'] = 'fr';

        $this->queued = [];
        ob_start();
        include dirname(__DIR__) . '/api/post_student_correct.php';
        $raw = ob_get_clean();

        $this->assertNotFalse($raw);
        $output = json_decode($raw, true);
        $this->assertIsArray($output);
        $this->assertSame($assessment->id, $output['id'] ?? null);
        $this->assertIsArray($output['student'] ?? null);
        $this->assertSame('Bob', $output['student']['name'] ?? null);
        $this->assertSame('under_correction', $output['student']['status'] ?? null);
        $this->assertIsArray($output['files'] ?? null);
        $this->assertIsArray($output['students'] ?? null);

        $this->assertCount(1, $this->queued);
        $ticket = json_decode($this->queued[0], true);
        $this->assertSame($student->id, $ticket['student_id'] ?? null);
        $this->assertSame(StudentTask1Correcting::class, $ticket['task'] ?? null);
    }

    public function testRedisQueueEnqueueStudentAndBlockingPop(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('lPush')
            ->with(
                RedisQueue::LIST_KEY,
                $this->callback(function (string $payload): bool {
                    $decoded = json_decode($payload, true);
                    return is_array($decoded)
                        && ($decoded['student_id'] ?? null) === 'stu-123'
                        && ($decoded['task'] ?? null) === StudentTask1Correcting::class;
                })
            );

        $redis->method('brPop')
            ->willReturn([
                RedisQueue::LIST_KEY,
                json_encode([
                    'student_id' => 'stu-123',
                    'task' => StudentTask1Correcting::class,
                ]),
            ]);

        $queue = new RedisQueue($redis);
        $queue->enqueueStudent('stu-123', StudentTask1Correcting::class);

        $popped = $queue->blockingPop(1);
        $this->assertNotNull($popped);
        $this->assertSame('stu-123', $popped['student_id']);
        $this->assertSame(StudentTask1Correcting::class, $popped['task']);
    }

    public function testRedisConsumerDispatchesStudentTask(): void
    {
        $school = new School();
        $school->name = 'Dispatch School';
        $school->save();

        $teacher = new User();
        $teacher->school_id = $school->id;
        $teacher->name = 'Teacher Dispatch';
        $teacher->email = 'teacher.dispatch@example.com';
        $teacher->setPassword('secret123');
        $teacher->save();

        $assessment = new LawAssessment();
        $assessment->school_id = $school->id;
        $assessment->user_id = $teacher->id;
        $assessment->name = 'Dispatch Assessment';
        $assessment->save();

        $student = $assessment->createStudent('Charlie');

        // Call treatStudentTask directly via handleTicket with StudentTask1Correcting
        RedisConsumer::handleTicket([
            'student_id' => $student->id,
            'task' => StudentTask1Correcting::class,
        ]);

        // Charlie has no submissions, so StudentTask1Correcting handles it without throwing uncaught exceptions
        $this->assertTrue(true);
    }

    public function testCorrectionJsonAndDisplayedStudentAttributes(): void
    {
        $school = new School();
        $school->name = 'Law School Result';
        $school->save();

        $teacher = new User();
        $teacher->school_id = $school->id;
        $teacher->name = 'Teacher Result';
        $teacher->email = 'teacher.result@example.com';
        $teacher->setPassword('secret123');
        $teacher->save();

        $assessment = new LawAssessment();
        $assessment->school_id = $school->id;
        $assessment->user_id = $teacher->id;
        $assessment->name = 'Law Result Assessment';
        $assessment->save();

        $student = $assessment->createStudent('Diane');
        $reply = json_encode([
            'mark' => 14.5,
            'appreciation' => "Bonne copie.\n\n**Le syllogisme** est clair.\nLa mineure reste à préciser.",
            'remarks' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

        $task = new StudentTask1Correcting();
        $file = $task->storeCorrectionFile($student, $reply);
        $task->storeStudentResult($student, $reply);

        $this->assertInstanceOf(S3File::class, $file);
        $this->assertSame('correction.json', $file->name);
        $this->assertSame('correction', $file->type);
        $this->assertSame($student->id, $file->student);
        $this->assertTrue($file->isDirectStudentFile());
        $this->assertSame($reply, $file->getContents());

        $listed = array_values(array_filter(
            $assessment->list_files(),
            fn($item) => ($item['name'] ?? '') === 'correction.json'
        ));
        $this->assertCount(1, $listed);
        $this->assertTrue($listed[0]['direct']);
        $this->assertSame($student->id, $listed[0]['student']);
        $this->assertSame($file->id, $listed[0]['id']);

        $again = $task->storeCorrectionFile($student, $reply);
        $this->assertSame($file->id, $again->id);

        $reloaded = $assessment->getStudent((string) $student->id);
        $this->assertSame(14.5, $reloaded->mark);
        $this->assertSame(
            "Bonne copie.\n\n**Le syllogisme** est clair.\nLa mineure reste à préciser.",
            $reloaded->appreciation
        );
        $this->assertSame('graded', $reloaded->status);
        $shown = $reloaded->to_output('fr');
        $this->assertSame(14.5, $shown['mark']);
        $this->assertSame($reloaded->appreciation, $shown['appreciation']);

        $assessment->createFile('correction.png', 'png', 'image/png', 'correction', $student->id);
        $assessment->deleteFilesOfType('correction', (string) $student->id);
        $this->assertSame($reply, S3File::from_hash((string) $file->id)->getContents());
    }

    private function installQueue(): void
    {
        $this->queued = [];
        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lPush', 'brPop', 'connect'])
            ->getMock();
        $redis->method('lPush')->willReturnCallback(function (string $key, string $payload): int {
            $this->queued[] = $payload;
            return count($this->queued);
        });
        RedisQueue::setInstance(new RedisQueue($redis));
    }
}
