<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\StudentsBulkAction;
use Corrai\Model\User;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\LawFrance\Assessment as LawAssessment;
use Corrai\Subject\Math\Assessment as MathAssessment;
use Corrai\Model\School;
use Corrai\Utils\Http\WSException;
use PHPUnit\Framework\TestCase;
use Redis;

class StudentsBulkActionTest extends TestCase
{
    /** @var list<string> */
    private array $queued = [];

    /** @var list<string> */
    private array $queuedOcr = [];

    protected function setUp(): void
    {
        $this->installQueue();
    }

    protected function tearDown(): void
    {
        RedisQueue::setInstance(null);
    }

    public function testBulkTranscribeEnqueuesPendingCopies(): void
    {
        [$assessment, $studentA, $studentB] = $this->createAssessmentWithTwoStudents();

        StudentsBulkAction::run(
            $assessment,
            [(string) $studentA->id, (string) $studentB->id],
            StudentsBulkAction::ACTION_TRANSCRIBE
        );

        $this->assertNotEmpty($this->queued);
    }

    public function testBulkCorrectClaudeEnqueuesPythonTicket(): void
    {
        [$assessment, $studentA, $studentB] = $this->createAssessmentWithTwoStudents();
        $this->queued = [];
        $this->queuedOcr = [];

        StudentsBulkAction::run(
            $assessment,
            [(string) $studentA->id, (string) $studentB->id],
            StudentsBulkAction::ACTION_CORRECT,
            StudentsBulkAction::MODEL_CLAUDE
        );

        $this->assertSame([], $this->queued);
        $this->assertCount(1, $this->queuedOcr);
        $ticket = json_decode($this->queuedOcr[0], true);
        $this->assertSame((string) $assessment->id, $ticket['assessment_id'] ?? null);
        $this->assertSame('correct_students', $ticket['task'] ?? null);
        $this->assertSame(
            [(string) $studentA->id, (string) $studentB->id],
            $ticket['student_ids'] ?? null
        );
        $this->assertSame(StudentsBulkAction::CLAUDE_MODEL_ID, $ticket['model'] ?? null);
        $this->assertSame(
            'under_correction',
            $assessment->getStudent((string) $studentA->id)->status
        );
    }

    public function testBulkCorrectMistralAndGeminiEnqueuePythonTickets(): void
    {
        [$assessment, $student] = $this->createAssessmentWithOneStudent();
        $this->queued = [];
        $this->queuedOcr = [];

        StudentsBulkAction::run(
            $assessment,
            [(string) $student->id],
            StudentsBulkAction::ACTION_CORRECT,
            StudentsBulkAction::MODEL_MISTRAL
        );
        $this->assertSame([], $this->queued);
        $ticket = json_decode($this->queuedOcr[0], true);
        $this->assertSame('correct_students', $ticket['task'] ?? null);
        $this->assertSame(StudentsBulkAction::MISTRAL_MODEL_ID, $ticket['model'] ?? null);

        [$assessment2, $student2] = $this->createAssessmentWithOneStudent('gemini');
        $this->queuedOcr = [];
        StudentsBulkAction::run(
            $assessment2,
            [(string) $student2->id],
            StudentsBulkAction::ACTION_CORRECT,
            StudentsBulkAction::MODEL_GEMINI
        );
        $ticket2 = json_decode($this->queuedOcr[0], true);
        $this->assertSame('correct_students', $ticket2['task'] ?? null);
        $this->assertSame(StudentsBulkAction::GEMINI_MODEL_ID, $ticket2['model'] ?? null);
    }

    public function testBulkCorrectGptEnqueuesPythonTicket(): void
    {
        [$assessment, $student] = $this->createAssessmentWithOneStudent();
        $this->queued = [];
        $this->queuedOcr = [];

        StudentsBulkAction::run(
            $assessment,
            [(string) $student->id],
            StudentsBulkAction::ACTION_CORRECT,
            StudentsBulkAction::MODEL_GPT
        );

        $this->assertSame([], $this->queued);
        $this->assertCount(1, $this->queuedOcr);
        $ticket = json_decode($this->queuedOcr[0], true);
        $this->assertSame((string) $assessment->id, $ticket['assessment_id'] ?? null);
        $this->assertSame('correct_students', $ticket['task'] ?? null);
        $this->assertSame([(string) $student->id], $ticket['student_ids'] ?? null);
        $this->assertSame(StudentsBulkAction::GPT_MODEL_ID, $ticket['model'] ?? null);
        $this->assertSame(
            'under_correction',
            $assessment->getStudent((string) $student->id)->status
        );
    }

    public function testBulkCorrectJevEnqueuesPythonTicket(): void
    {
        [$assessment, $student] = $this->createAssessmentWithOneStudent();
        $this->queuedOcr = [];

        StudentsBulkAction::run(
            $assessment,
            [(string) $student->id],
            StudentsBulkAction::ACTION_CORRECT,
            StudentsBulkAction::MODEL_JEV
        );

        $this->assertCount(1, $this->queuedOcr);
        $ticket = json_decode($this->queuedOcr[0], true);
        $this->assertSame('jev_correct_students', $ticket['task'] ?? null);
        $this->assertSame([(string) $student->id], $ticket['student_ids'] ?? null);
        $this->assertArrayNotHasKey('model', $ticket);
    }

    public function testBulkCorrectMathClaudeEnqueuesPythonMathTicket(): void
    {
        [$assessment, $student] = $this->createMathAssessmentWithOneStudent();
        $this->queued = [];
        $this->queuedOcr = [];

        StudentsBulkAction::run(
            $assessment,
            [(string) $student->id],
            StudentsBulkAction::ACTION_CORRECT,
            StudentsBulkAction::MODEL_CLAUDE
        );

        $this->assertSame([], $this->queued);
        $this->assertCount(1, $this->queuedOcr);
        $ticket = json_decode($this->queuedOcr[0], true);
        $this->assertSame((string) $assessment->id, $ticket['assessment_id'] ?? null);
        $this->assertSame('math_correct_students', $ticket['task'] ?? null);
        $this->assertSame([(string) $student->id], $ticket['student_ids'] ?? null);
        $this->assertSame(StudentsBulkAction::CLAUDE_MODEL_ID, $ticket['model'] ?? null);
        $this->assertSame(
            'under_correction',
            $assessment->getStudent((string) $student->id)->status
        );
    }

    public function testBulkCorrectMathJevRejected(): void
    {
        [$assessment, $student] = $this->createMathAssessmentWithOneStudent('jev');

        $this->expectException(WSException::class);
        StudentsBulkAction::run(
            $assessment,
            [(string) $student->id],
            StudentsBulkAction::ACTION_CORRECT,
            StudentsBulkAction::MODEL_JEV
        );
    }

    public function testApiBulkCorrectClaude(): void
    {
        [$assessment, $student] = $this->createAssessmentWithOneStudent('api');
        $teacherId = $assessment->user_id;

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $teacherId;
        $_REQUEST = [
            'id' => $assessment->id,
            'students' => [(string) $student->id],
            'action' => 'correct',
            'model' => 'claude',
            'locale' => 'fr',
        ];

        $this->queued = [];
        $this->queuedOcr = [];
        ob_start();
        include dirname(__DIR__) . '/api/post_students_bulk_action.php';
        $raw = ob_get_clean();

        $this->assertNotFalse($raw);
        $output = json_decode($raw, true);
        $this->assertIsArray($output);
        $this->assertSame($assessment->id, $output['id'] ?? null);
        $this->assertIsArray($output['students'] ?? null);
        $this->assertIsArray($output['files'] ?? null);
        $this->assertSame([], $this->queued);
        $this->assertCount(1, $this->queuedOcr);
        $ticket = json_decode($this->queuedOcr[0], true);
        $this->assertSame('correct_students', $ticket['task'] ?? null);
        $this->assertSame(StudentsBulkAction::CLAUDE_MODEL_ID, $ticket['model'] ?? null);
    }

    public function testEnqueuePythonAssessmentTaskMergesExtra(): void
    {
        $captured = null;
        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lPush', 'connect'])
            ->getMock();
        $redis->expects($this->once())
            ->method('lPush')
            ->with(
                RedisQueue::OCR_LIST_KEY,
                $this->callback(function (string $payload) use (&$captured): bool {
                    $captured = $payload;
                    return true;
                })
            )
            ->willReturn(1);

        $queue = new RedisQueue($redis);
        $queue->enqueuePythonAssessmentTask('ass-1', 'correct_students', [
            'student_ids' => ['s1', 's2'],
            'model' => StudentsBulkAction::GPT_MODEL_ID,
        ]);

        $decoded = json_decode((string) $captured, true);
        $this->assertSame(
            [
                'student_ids' => ['s1', 's2'],
                'model' => StudentsBulkAction::GPT_MODEL_ID,
                'assessment_id' => 'ass-1',
                'task' => 'correct_students',
            ],
            $decoded
        );
    }

    /**
     * @return array{0: LawAssessment, 1: \Corrai\Model\BaseStudent, 2: \Corrai\Model\BaseStudent}
     */
    private function createAssessmentWithTwoStudents(): array
    {
        $assessment = $this->createAssessment('two');
        $studentA = $assessment->createStudent('Alice');
        $studentB = $assessment->createStudent('Bob');
        $assessment->createFileModel('a.png', 'img', 'image/png', 'submission', $studentA->id);
        $assessment->createFileModel('b.png', 'img', 'image/png', 'submission', $studentB->id);
        return [$assessment, $studentA, $studentB];
    }

    /**
     * @return array{0: LawAssessment, 1: \Corrai\Model\BaseStudent}
     */
    private function createAssessmentWithOneStudent(string $suffix = 'one'): array
    {
        $assessment = $this->createAssessment($suffix);
        $student = $assessment->createStudent('Solo');
        $assessment->createFileModel('solo.png', 'img', 'image/png', 'submission', $student->id);
        return [$assessment, $student];
    }

    private function createAssessment(string $suffix): LawAssessment
    {
        $school = new School();
        $school->name = 'Bulk School ' . $suffix;
        $school->save();

        $teacher = new User();
        $teacher->school_id = $school->id;
        $teacher->name = 'Bulk Teacher ' . $suffix;
        $teacher->email = 'bulk.' . $suffix . '.' . uniqid('', true) . '@example.com';
        $teacher->setPassword('secret123');
        $teacher->save();

        $assessment = new LawAssessment();
        $assessment->school_id = $school->id;
        $assessment->user_id = $teacher->id;
        $assessment->name = 'Bulk Exam ' . $suffix;
        $assessment->save();

        return $assessment;
    }

    /**
     * @return array{0: MathAssessment, 1: \Corrai\Model\BaseStudent}
     */
    private function createMathAssessmentWithOneStudent(string $suffix = 'math'): array
    {
        $school = new School();
        $school->name = 'Math Bulk School ' . $suffix;
        $school->save();

        $teacher = new User();
        $teacher->school_id = $school->id;
        $teacher->name = 'Math Bulk Teacher ' . $suffix;
        $teacher->email = 'math.bulk.' . $suffix . '.' . uniqid('', true) . '@example.com';
        $teacher->setPassword('secret123');
        $teacher->save();

        $assessment = new MathAssessment();
        $assessment->school_id = $school->id;
        $assessment->user_id = $teacher->id;
        $assessment->name = 'Math Bulk Exam ' . $suffix;
        $assessment->save();

        $student = $assessment->createStudent('Adele');
        $assessment->createFileModel('copy.png', 'img', 'image/png', 'submission', $student->id);
        return [$assessment, $student];
    }

    private function installQueue(): void
    {
        $this->queued = [];
        $this->queuedOcr = [];
        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lPush', 'brPop', 'connect'])
            ->getMock();
        $redis->method('lPush')->willReturnCallback(function (string $key, string $payload): int {
            if ($key === RedisQueue::OCR_LIST_KEY) {
                $this->queuedOcr[] = $payload;
                return count($this->queuedOcr);
            }
            $this->queued[] = $payload;
            return count($this->queued);
        });
        RedisQueue::setInstance(new RedisQueue($redis));
    }
}
