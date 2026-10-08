<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Clients\Openrouter\GeminiFlashLiteClient;
use Corrai\Model\BaseAssessment;
use Corrai\Model\OCRResult;
use Corrai\Model\School;
use Corrai\Model\SubmissionFile;
use Corrai\Model\User;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\LawFrance\Assessment as LawAssessment;
use Corrai\Subject\LawFrance\AssTask1Affectation;
use Corrai\Subject\LawFrance\Submission;
use Corrai\Subject\LawFrance\SubmissionTask3Identify;
use Corrai\Utils\Store\HashId;
use Corrai\Utils\Store\ObjectStore;
use PHPUnit\Framework\TestCase;
use Redis;

class LawFranceSubmissionTask3IdentifyTest extends TestCase
{
    /** @var list<string> */
    private array $queued = [];

    protected function tearDown(): void
    {
        RedisQueue::setInstance(null);
        parent::tearDown();
    }

    public function testSubmissionTask3IdentifySetsSubmissionToTranscribed(): void
    {
        $this->installQueue();

        $submission = $this->createSubmission('sub-1', 'transcribing', null);

        $assessment = $this->getMockBuilder(LawAssessment::class)
            ->onlyMethods(['listFileModels', 'save'])
            ->getMock();
        $assessment->id = 'ass-1';
        $assessment->method('listFileModels')->willReturn([$submission]);

        $task = new SubmissionTask3Identify();
        $task->identifySubmission($submission, $assessment);

        $this->assertSame('transcribed', $submission->status);
    }

    public function testTaskSetsAssessmentToAffectingAndQueuesAssTask1WhenNoTranscribingSubmissionsRemain(): void
    {
        $this->installQueue();

        $submission1 = $this->createSubmission('sub-1', 'transcribing', null);
        $submission2 = $this->createSubmission('sub-2', 'transcribed', null);

        $assessment = $this->getMockBuilder(LawAssessment::class)
            ->onlyMethods(['listFileModels', 'save'])
            ->getMock();
        $assessment->id = 'ass-test-1';
        $assessment->status = 'draft';

        $assessment->method('listFileModels')->willReturn([$submission1, $submission2]);
        $assessment->expects($this->once())->method('save');

        $task = new SubmissionTask3Identify();
        $task->identifySubmission($submission1, $assessment);

        $this->assertSame('transcribed', $submission1->status);
        $this->assertSame('affecting', $assessment->status);

        $tickets = $this->decodedTickets();
        $this->assertCount(1, $tickets);
        $this->assertSame('ass-test-1', $tickets[0]['assessment_id'] ?? null);
        $this->assertSame(AssTask1Affectation::class, $tickets[0]['task'] ?? null);
    }

    public function testTaskDoesNotSetAffectingWhenOtherUnassignedSubmissionsAreStillTranscribing(): void
    {
        $this->installQueue();

        $submission1 = $this->createSubmission('sub-1', 'transcribing', null);
        $submission2 = $this->createSubmission('sub-2', 'transcribing', null);

        $assessment = $this->getMockBuilder(LawAssessment::class)
            ->onlyMethods(['listFileModels', 'save'])
            ->getMock();
        $assessment->id = 'ass-test-2';
        $assessment->status = 'draft';

        $assessment->method('listFileModels')->willReturn([$submission1, $submission2]);
        $assessment->expects($this->never())->method('save');

        $task = new SubmissionTask3Identify();
        $task->identifySubmission($submission1, $assessment);

        $this->assertSame('transcribed', $submission1->status);
        $this->assertSame('draft', $assessment->status);
        $this->assertSame([], $this->decodedTickets());
    }

    public function testAssignedSubmissionsTranscribingDoNotBlockAffecting(): void
    {
        $this->installQueue();

        $submission1 = $this->createSubmission('sub-1', 'transcribing', null);
        $assignedSubmission = $this->createSubmission('sub-assigned', 'transcribing', 'student-123');

        $assessment = $this->getMockBuilder(LawAssessment::class)
            ->onlyMethods(['listFileModels', 'save'])
            ->getMock();
        $assessment->id = 'ass-test-3';
        $assessment->status = 'draft';

        $assessment->method('listFileModels')->willReturn([$submission1, $assignedSubmission]);

        $task = new SubmissionTask3Identify();
        $task->identifySubmission($submission1, $assessment);

        $this->assertSame('transcribed', $submission1->status);
        $this->assertSame('affecting', $assessment->status);

        $tickets = $this->decodedTickets();
        $this->assertCount(1, $tickets);
        $this->assertSame('ass-test-3', $tickets[0]['assessment_id'] ?? null);
        $this->assertSame(AssTask1Affectation::class, $tickets[0]['task'] ?? null);
    }

    public function testProcessTaskEndToEndWithObjectStore(): void
    {
        $this->installQueue();

        $school = School::ensureIndependent();
        $suffix = bin2hex(random_bytes(4));
        $user = $school->addUser(
            "teacher_{$suffix}@ind.test",
            "Teacher {$suffix}",
            "pass-{$suffix}",
            User::ROLE_TEACHER
        );

        $assessment = new LawAssessment();
        $assessment->school_id = $user->school_id;
        $assessment->user_id = $user->id;
        $assessment->name = 'Law Exam ' . $suffix;
        $assessment->subject = 'Law';
        $assessment->country = 'fr';
        $assessment->date = '2026-10-07';
        $assessment->id = HashId::create();
        $assessment->save();

        $path = tempnam(sys_get_temp_dir(), 'law_sub_');
        file_put_contents($path, 'dummy content');
        $file = $assessment->createFileFromPath('copie.png', $path, 'image/png', 'submission', null);
        @unlink($path);

        $file->status = 'transcribing';
        $file->saveAttributes();

        $task = new SubmissionTask3Identify();
        $task->process_task((object) [
            'path' => $file->contentKey(),
            'task' => SubmissionTask3Identify::class,
            'task_id' => SubmissionTask3Identify::class,
            'file_id' => $file->id,
        ]);

        $reloadedFile = $assessment->getFile((string) $file->id);
        $this->assertSame('transcribed', $reloadedFile->status);

        $reloadedAssessment = LawAssessment::from_hash((string) $assessment->id);
        $this->assertSame('affecting', $reloadedAssessment->status);

        $tickets = array_values(array_filter(
            $this->decodedTickets(),
            fn(array $t): bool => isset($t['assessment_id'])
        ));
        $this->assertCount(1, $tickets);
        $this->assertSame((string) $assessment->id, $tickets[0]['assessment_id'] ?? null);
        $this->assertSame(AssTask1Affectation::class, $tickets[0]['task'] ?? null);
    }

    public function testIdentifyReadsInstructionAndFirstOcrWordsThenStoresStudentIdentifier(): void
    {
        $this->installQueue();

        $school = School::ensureIndependent();
        $suffix = bin2hex(random_bytes(4));
        $user = $school->addUser(
            "teacher_{$suffix}@ind.test",
            "Teacher {$suffix}",
            "pass-{$suffix}",
            User::ROLE_TEACHER
        );

        $assessment = new LawAssessment();
        $assessment->school_id = $user->school_id;
        $assessment->user_id = $user->id;
        $assessment->name = 'Law Exam ' . $suffix;
        $assessment->subject = 'Law';
        $assessment->country = 'fr';
        $assessment->date = '2026-10-07';
        $assessment->id = HashId::create();
        $assessment->save();

        $instruction = "Le chiffre rouge en haut de la copie est l'identifiant de l'élève.";
        $instructionPath = tempnam(sys_get_temp_dir(), 'law_ins_');
        file_put_contents($instructionPath, $instruction);
        $assessment->createFileFromPath('instructions.md', $instructionPath, 'text/markdown', 'instructions', null);
        @unlink($instructionPath);

        $path = tempnam(sys_get_temp_dir(), 'law_sub_');
        file_put_contents($path, 'dummy content');
        $file = $assessment->createFileFromPath('copie.png', $path, 'image/png', 'submission', null);
        @unlink($path);
        $this->assertInstanceOf(Submission::class, $file);

        $boxes = [];
        for ($i = 0; $i < 35; $i++) {
            $boxes[] = [
                'text' => sprintf('mot-%02d', $i),
                'left' => 0.05,
                'top' => 0.01 * $i,
                'width' => 0.08,
                'height' => 0.02,
            ];
        }
        $ocr = OCRResult::from_google([
            'text' => implode(' ', array_column($boxes, 'text')),
            'bounding_boxes' => $boxes,
        ]);
        ObjectStore::getInstance()->putContents($file->ocrResultKey(), $ocr->to_json(true), 'application/json');

        $file->status = 'transcribing';
        $file->saveAttributes();

        $client = new IdentifyCapturingGemini();
        $client->id = '17';
        $task = new SubmissionTask3Identify($client);
        $task->process_task((object) [
            'path' => $file->contentKey(),
            'task' => SubmissionTask3Identify::class,
            'task_id' => SubmissionTask3Identify::class,
            'file_id' => $file->id,
        ]);

        $prompt = $client->promptText();
        $this->assertStringContainsString($instruction, $prompt);
        $this->assertStringContainsString('mot-00', $prompt);
        $this->assertStringContainsString('mot-29', $prompt);
        $this->assertStringNotContainsString('mot-30', $prompt);
        $this->assertStringContainsString('"left"', $prompt);
        $schema = $client->responseFormat();
        $this->assertSame('student_identifier', $schema['json_schema']['name'] ?? null);

        $reloaded = $assessment->getFile((string) $file->id);
        $this->assertInstanceOf(Submission::class, $reloaded);
        $this->assertSame('17', $reloaded->student_identifier);
        $this->assertSame('transcribed', $reloaded->status);
    }

    private function createSubmission(string $id, string $status, ?string $student): Submission
    {
        $submission = new Submission();
        $submission->id = $id;
        $submission->name = $id . '.png';
        $submission->school_id = 'school-1';
        $submission->user_id = 'user-1';
        $submission->assessment_id = 'ass-1';
        $submission->status = $status;
        $submission->student = $student;
        return $submission;
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
            return 1;
        });
        RedisQueue::setInstance(new RedisQueue($redis));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodedTickets(): array
    {
        $tickets = [];
        foreach ($this->queued as $payload) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $tickets[] = $decoded;
            }
        }
        return $tickets;
    }
}

class IdentifyCapturingGemini extends GeminiFlashLiteClient
{
    public ?string $id = null;

    private string $promptText = '';

    public function add_text(string $text): void
    {
        $this->promptText .= $text . "\n";
        parent::add_text($text);
    }

    public function promptText(): string
    {
        return $this->promptText;
    }

    /**
     * @return array<string, mixed>
     */
    public function responseFormat(): array
    {
        $format = $this->payload['response_format'] ?? null;
        return is_array($format) ? $format : [];
    }

    public function call(): array
    {
        return ['response' => ['id' => $this->id]];
    }
}
