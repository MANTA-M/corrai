<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Student;
use Corrai\Subject\LawFrance\Assessment as LawAssessment;
use Corrai\Subject\LawFrance\Submission;
use Corrai\Utils\Http\WSException;
use PHPUnit\Framework\TestCase;

class LawFranceAllocateSubmissionTest extends TestCase
{
    public function testStopsWhenAnUnclassifiedSubmissionIsNotTranscribed(): void
    {
        $pending = $this->submission('page-03.png', 'transcribing', null);
        $pending->expects($this->never())->method('saveAttributes');

        $assessment = $this->assessment([$pending], []);
        $assessment->expects($this->never())->method('listStudentModels');

        $assessment->allocateSubmission();
    }

    public function testThrowsWhenTheAssessmentHasNoStudents(): void
    {
        $page = $this->submission('page-01.png', 'transcribed', null);
        $assessment = $this->assessment([$page], []);
        $assessment->id = 'ass-1';

        $this->expectException(WSException::class);
        $this->expectExceptionMessage('Cannot allocate submissions: the assessment has no students');

        $assessment->allocateSubmission();
    }

    public function testAssignsEachUnclassifiedCopyToTheStudentOfThePreviousName(): void
    {
        $before = $this->submission('page-01.png', 'transcribed', null);
        $before->expects($this->never())->method('saveAttributes');

        $middle = $this->submission('page-03.png', 'transcribed', null);
        $middle->expects($this->once())->method('saveAttributes');
        $middle->expects($this->once())->method('appendEvent')->with('Allocated to student Alice');

        $after = $this->submission('page-06.png', 'transcribed', null);
        $after->expects($this->once())->method('saveAttributes');
        $after->expects($this->once())->method('appendEvent')->with('Allocated to student Bob');

        $alicePage = $this->submission('page-02.png', 'transcribed', 'student-alice');
        $bobPage = $this->submission('page-05.png', 'transcribed', 'student-bob');

        $assessment = $this->assessment(
            [$after, $alicePage, $before, $middle, $bobPage],
            [$this->student('student-alice', 'Alice'), $this->student('student-bob', 'Bob')]
        );

        $assessment->allocateSubmission();

        $this->assertNull($before->student);
        $this->assertSame('student-alice', $middle->student);
        $this->assertSame('student-bob', $after->student);
    }

    /**
     * @param list<Submission> $files
     * @param list<Student> $students
     */
    private function assessment(array $files, array $students): LawAssessment
    {
        $assessment = $this->getMockBuilder(LawAssessment::class)
            ->onlyMethods(['listFileModels', 'listStudentModels'])
            ->getMock();
        $assessment->method('listFileModels')->willReturn($files);
        $assessment->method('listStudentModels')->willReturn($students);
        return $assessment;
    }

    private function submission(string $name, string $status, ?string $student): Submission
    {
        $submission = $this->getMockBuilder(Submission::class)
            ->onlyMethods(['saveAttributes', 'appendEvent'])
            ->getMock();
        $submission->id = $name;
        $submission->name = $name;
        $submission->status = $status;
        $submission->student = $student;
        return $submission;
    }

    private function student(string $id, string $name): Student
    {
        $student = new Student();
        $student->id = $id;
        $student->name = $name;
        return $student;
    }
}
