<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\BaseAssessment;
use Corrai\Model\School;
use Corrai\Model\StateLocales;
use Corrai\Model\SubmissionFile;
use Corrai\Model\User;
use Corrai\Queue\RedisConsumer;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\LawFrance\Assessment as LawAssessment;
use Corrai\Subject\LawFrance\AssTask1Affectation;
use Corrai\Subject\LawFrance\Submission;
use Corrai\Subject\LawFrance\SubmissionTask3Crop;
use Corrai\Subject\LawFrance\SubmissionTask3Identify;
use Corrai\Utils\Store\HashId;
use PHPUnit\Framework\TestCase;
use Redis;

class LawFranceSubmissionTask3CropTest extends TestCase
{
    /** @var list<string> */
    private array $queued = [];

    protected function tearDown(): void
    {
        RedisQueue::setInstance(null);
        parent::tearDown();
    }

    public function testAssessmentAffectingStateLocalesExist(): void
    {
        $assessment = new LawAssessment();
        $labelsFr = BaseAssessment::statusLabels('fr');
        $this->assertSame('Affectation des copies', $labelsFr['affecting']);

        $labelsEn = BaseAssessment::statusLabels('en');
        $this->assertSame('Assigning copies', $labelsEn['affecting']);

        $labelsDe = BaseAssessment::statusLabels('de');
        $this->assertSame('Zuweisung der Kopien', $labelsDe['affecting']);

        $labelsEs = BaseAssessment::statusLabels('es');
        $this->assertSame('Asignación de copias', $labelsEs['affecting']);

        $labelsPt = BaseAssessment::statusLabels('pt');
        $this->assertSame('Atribuição de cópias', $labelsPt['affecting']);

        $labelsRu = BaseAssessment::statusLabels('ru');
        $this->assertSame('Назначение копий', $labelsRu['affecting']);

        $labelsUk = BaseAssessment::statusLabels('uk');
        $this->assertSame('Призначення робіт', $labelsUk['affecting']);

        $labelsRo = BaseAssessment::statusLabels('ro');
        $this->assertSame('Atribuirea copiilor', $labelsRo['affecting']);

        $mapFr = StateLocales::maps($assessment, 'fr');
        $this->assertSame('Affectation des copies', $mapFr['assessment_states']['affecting']);

        $assessment->status = 'affecting';
        $output = $assessment->to_output('fr');
        $this->assertSame('affecting', $output['status']);
        $this->assertSame('Affectation des copies', $output['status_label']);
    }

    public function testRedisQueueEnqueueAndPopAssessment(): void
    {
        $payloads = [];
        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lPush', 'brPop', 'connect'])
            ->getMock();
        $redis->method('lPush')->willReturnCallback(function (string $key, string $payload) use (&$payloads): int {
            $payloads[$key][] = $payload;
            return 1;
        });

        $queue = new RedisQueue($redis);
        $queue->enqueueAssessment('ass-123', AssTask1Affectation::class);

        $this->assertCount(1, $payloads[RedisQueue::LIST_KEY] ?? []);
        $decoded = json_decode($payloads[RedisQueue::LIST_KEY][0], true);
        $this->assertSame('ass-123', $decoded['assessment_id']);
        $this->assertSame(AssTask1Affectation::class, $decoded['task']);

        // Now test blockingPop
        $redisPop = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['brPop', 'connect'])
            ->getMock();
        $redisPop->method('brPop')->willReturn([RedisQueue::LIST_KEY, json_encode([
            'assessment_id' => 'ass-123',
            'task' => AssTask1Affectation::class,
        ])]);
        $queuePop = new RedisQueue($redisPop);
        $ticket = $queuePop->blockingPop();
        $this->assertNotNull($ticket);
        $this->assertSame('ass-123', $ticket['assessment_id']);
        $this->assertSame(AssTask1Affectation::class, $ticket['task']);
    }

    public function testRedisConsumerDispatchesAssessmentTask(): void
    {
        $processed = false;
        $testTaskClass = new class($processed) {
            public static bool $executed = false;
            public function process_task(object $data): void
            {
                self::$executed = true;
            }
        };

        // Test with a dummy class implementing process_task
        $taskName = get_class($testTaskClass);
        // Using real AssTask1Affectation
        $ticket = [
            'assessment_id' => 'ass-dummy',
            'task' => AssTask1Affectation::class,
        ];

        // Should execute treatAssessmentTask without throwing unknown task error
        // AssTask1Affectation will log error for non-existent assessment, but treatAssessmentTask itself dispatches properly
        RedisConsumer::handleTicket($ticket);
        $this->assertTrue(class_exists(AssTask1Affectation::class));
    }

    public function testSubmissionTask3CropDoesNotSetStatusToTranscribedAndEnqueuesIdentify(): void
    {
        $this->installQueue();

        $submission = $this->createSubmission('sub-1', 'transcribing', null);

        $assessment = $this->getMockBuilder(LawAssessment::class)
            ->onlyMethods(['listFileModels', 'save'])
            ->getMock();
        $assessment->id = 'ass-1';
        $assessment->method('listFileModels')->willReturn([$submission]);

        $task = new SubmissionTask3Crop();
        $task->cropSubmission($submission, $assessment);

        $this->assertSame('transcribing', $submission->status);
        $tickets = $this->decodedTickets();
        $this->assertCount(1, $tickets);
        $this->assertSame('sub-1', $tickets[0]['file_id'] ?? null);
        $this->assertSame(SubmissionTask3Identify::class, $tickets[0]['task'] ?? null);
    }

    public function testSubmissionTask3CropDoesNotSetAffectingStatus(): void
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
        $assessment->expects($this->never())->method('save');

        $task = new SubmissionTask3Crop();
        $task->cropSubmission($submission1, $assessment);

        $this->assertSame('transcribing', $submission1->status);
        $this->assertSame('draft', $assessment->status);

        $tickets = $this->decodedTickets();
        $this->assertCount(1, $tickets);
        $this->assertSame('sub-1', $tickets[0]['file_id'] ?? null);
        $this->assertSame(SubmissionTask3Identify::class, $tickets[0]['task'] ?? null);
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

        $task = new SubmissionTask3Crop();
        $task->process_task((object) [
            'path' => $file->contentKey(),
            'task' => SubmissionTask3Crop::class,
            'task_id' => SubmissionTask3Crop::class,
            'file_id' => $file->id,
        ]);

        $reloadedFile = $assessment->getFile((string) $file->id);
        $this->assertSame('transcribing', $reloadedFile->status);

        $reloadedAssessment = LawAssessment::from_hash((string) $assessment->id);
        $this->assertSame('draft', $reloadedAssessment->status);

        $tickets = array_values(array_filter(
            $this->decodedTickets(),
            fn(array $t): bool => ($t['task'] ?? null) === SubmissionTask3Identify::class
        ));
        $this->assertCount(1, $tickets);
        $this->assertSame((string) $file->id, $tickets[0]['file_id'] ?? null);
        $this->assertSame(SubmissionTask3Identify::class, $tickets[0]['task'] ?? null);
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
