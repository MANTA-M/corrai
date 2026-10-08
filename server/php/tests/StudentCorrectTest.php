<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\BaseAssessment;
use Corrai\Model\BaseStudent;
use Corrai\Model\School;
use Corrai\Model\User;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\LawFrance\Assessment as LawAssessment;
use Corrai\Subject\LawFrance\Student as LawStudent;
use Corrai\Subject\LawFrance\Submission;
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
        $this->assertSame(['view', 'correct', 'rename', 'delete'], array_column($menuFr, 'key'));
        $this->assertSame('Corriger', $menuFr[1]['label']);
        $this->assertSame('check', $menuFr[1]['icon']);

        $menuEn = $student->get_menu('en');
        $this->assertSame('Correct', $menuEn[1]['label']);
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
        $corrected = $student->correct();
        $this->assertCount(1, $corrected);

        $reloadedStudent = $assessment->getStudent($student->id);
        $this->assertSame('pending', $reloadedStudent->status);

        $reloadedSubmission = $assessment->getFile($submission->id);
        $this->assertSame('transcribing', $reloadedSubmission->status);
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

        ob_start();
        include dirname(__DIR__) . '/api/post_student_correct.php';
        $raw = ob_get_clean();

        $this->assertNotFalse($raw);
        $output = json_decode($raw, true);
        $this->assertIsArray($output);
        $this->assertSame($assessment->id, $output['id'] ?? null);
        $this->assertIsArray($output['student'] ?? null);
        $this->assertSame('Bob', $output['student']['name'] ?? null);
        $this->assertSame('pending', $output['student']['status'] ?? null);
        $this->assertIsArray($output['files'] ?? null);
        $this->assertIsArray($output['students'] ?? null);

        $reloadedSubmission = $assessment->getFile($submission->id);
        $this->assertSame('transcribing', $reloadedSubmission->status);
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
