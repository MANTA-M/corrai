<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Clients\Openrouter\ClaudeSonnetClient;
use Corrai\Model\BaseAssessment;
use Corrai\Model\BaseStudent;
use Corrai\Model\OCRResult;
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

    public function testStoreCompiledSubmissionFile(): void
    {
        $school = new School();
        $school->name = 'Law School Compile';
        $school->save();

        $teacher = new User();
        $teacher->school_id = $school->id;
        $teacher->name = 'Teacher Compile';
        $teacher->email = 'teacher.compile@example.com';
        $teacher->setPassword('secret123');
        $teacher->save();

        $assessment = new LawAssessment();
        $assessment->school_id = $school->id;
        $assessment->user_id = $teacher->id;
        $assessment->name = 'Law Compile Assessment';
        $assessment->save();

        $student = $assessment->createStudent('Emma');
        $pages = ['Page 1 content here', 'Page 2 content here'];

        $task = new StudentTask1Correcting();
        $file = $task->storeCompiledSubmissionFile($student, $pages);

        $this->assertInstanceOf(S3File::class, $file);
        $this->assertSame('compiled_submission.json', $file->name);
        $this->assertSame($student->id, $file->student);
        $this->assertTrue($file->isDirectStudentFile());

        $content = json_decode($file->getContents(), true);
        $this->assertSame($pages, $content['pages'] ?? null);

        $listed = array_values(array_filter(
            $assessment->list_files(),
            fn($item) => ($item['name'] ?? '') === 'compiled_submission.json'
        ));
        $this->assertCount(1, $listed);
        $this->assertSame($file->id, $listed[0]['id']);
    }

    public function testCompiledSubmissionSavedBeforeCallingLlmDuringStudentCorrection(): void
    {
        $school = new School();
        $school->name = 'Law School LLM Order';
        $school->save();

        $teacher = new User();
        $teacher->school_id = $school->id;
        $teacher->name = 'Teacher LLM Order';
        $teacher->email = 'teacher.llm.order@example.com';
        $teacher->setPassword('secret123');
        $teacher->save();

        $assessment = new LawAssessment();
        $assessment->school_id = $school->id;
        $assessment->user_id = $teacher->id;
        $assessment->name = 'Law LLM Order Assessment';
        $assessment->save();

        $student = $assessment->createStudent('Felix');

        $store = ObjectStore::getInstance();
        $subjectCompileKey = ObjectStore::assessmentSubjectCompileKey(
            $assessment->school_id,
            $assessment->user_id,
            (string) $assessment->id
        );
        $gridKey = ObjectStore::assessmentSubjectCorrectionGridKey(
            $assessment->school_id,
            $assessment->user_id,
            (string) $assessment->id
        );
        $store->putContents($subjectCompileKey, '{"pages":["Sujet de droit civil"]}', 'application/json');
        $store->putContents($gridKey, '{"parties":[]}', 'application/json');

        $copy1 = $assessment->createFileModel('copie_a.png', 'content a', 'image/png', 'submission', $student->id);
        $copy2 = $assessment->createFileModel('copie_b.png', 'content b', 'image/png', 'submission', $student->id);

        $ocr1 = OCRResult::from_google(['text' => 'Page A text', 'bounding_boxes' => []]);
        $ocr2 = OCRResult::from_google(['text' => 'Page B text', 'bounding_boxes' => []]);
        $store->putContents($copy1->ocrResultKey(), $ocr1->to_json(true), 'application/json');
        $store->putContents($copy2->ocrResultKey(), $ocr2->to_json(true), 'application/json');

        $compiledAtCallTime = null;
        $mockClaude = new class($compiledAtCallTime, $student) extends ClaudeSonnetClient {
            public function __construct(public &$captured, public BaseStudent $targetStudent)
            {
                parent::__construct();
            }

            public function call_text(): string
            {
                $key = ObjectStore::assessmentStudentCompiledSubmissionKey(
                    $this->targetStudent->school_id,
                    $this->targetStudent->user_id,
                    $this->targetStudent->assessment_id,
                    (string) $this->targetStudent->id
                );
                $store = ObjectStore::getInstance();
                if ($store->exists($key)) {
                    $this->captured = S3File::from_key($key);
                }
                return json_encode([
                    'modificateurs_généraux' => [],
                    'parties' => [],
                    'mark' => 16,
                    'appreciation' => 'Très bonne copie.',
                    'remarks' => [],
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
        };

        $task = new StudentTask1Correcting($mockClaude);
        $task->correctStudent($assessment, $student);

        $this->assertNotNull($compiledAtCallTime, 'compiled_submission.json must exist in S3 before the LLM is called');
        $this->assertSame('compiled_submission.json', $compiledAtCallTime->name);
        $this->assertSame($student->id, $compiledAtCallTime->student);
        $this->assertSame(['Page A text', 'Page B text'], json_decode($compiledAtCallTime->getContents(), true)['pages'] ?? null);

        $correctionKey = ObjectStore::assessmentStudentPrefix(
            $student->school_id,
            $student->user_id,
            $student->assessment_id,
            (string) $student->id
        ) . 'correction.json';
        $this->assertTrue($store->exists($correctionKey));
    }

    public function testCorrectionSchemaIncludesConfidenceScore(): void
    {
        $reflection = new \ReflectionClass(StudentTask1Correcting::class);
        $method = $reflection->getMethod('correctionSchema');
        $method->setAccessible(true);
        $schema = $method->invoke(null, 'français');

        $this->assertIsArray($schema);
        $modCriterion = $schema['properties']['modificateurs_généraux']['items'] ?? [];
        $this->assertContains('score_de_confiance', $modCriterion['required'] ?? []);
        $this->assertSame('number', $modCriterion['properties']['score_de_confiance']['type'] ?? null);

        $questionProperties = $schema['properties']['parties']['items']['properties']['questions']['items']['properties'] ?? [];
        $this->assertArrayNotHasKey('critères_proposés', $questionProperties);
        $this->assertArrayNotHasKey('aspects', $questionProperties);
        $this->assertArrayHasKey('critères', $questionProperties);

        $partCriterion = $questionProperties['critères']['items'] ?? [];
        $this->assertSame(['description', 'ponderation', 'note', 'score_de_confiance', 'commentaire'], $partCriterion['required'] ?? []);
        $this->assertSame('string', $partCriterion['properties']['description']['type'] ?? null);
        $this->assertSame('number', $partCriterion['properties']['ponderation']['type'] ?? null);
        $this->assertSame('number', $partCriterion['properties']['note']['type'] ?? null);
        $this->assertSame('number', $partCriterion['properties']['score_de_confiance']['type'] ?? null);
        $this->assertSame('string', $partCriterion['properties']['commentaire']['type'] ?? null);
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
