<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Assessment;
use Corrai\Model\BaseAssessment;
use Corrai\Model\InputFile;
use Corrai\Subject\Dictation\Submission as DictationSubmission;
use Corrai\Model\School;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Model\User;
use Corrai\Queue\RedisConsumer;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Store\HashId;
use Corrai\Utils\Http\SSEvent;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;

class ConcreteTaskForTest extends PathQueueItemTask
{
    public bool $processed = false;
    public ?InputFile $fileDuringProcess = null;

    protected function process(object $queue_item_data, string $s3_path): void
    {
        $this->processed = true;
        $file = $this->loadFile($s3_path);
        $this->fileDuringProcess = $file;
        $file->status = 'errors_found';
        $file->saveAttributes();
    }
}

class FailingTaskForTest extends PathQueueItemTask
{
    protected function process(object $queue_item_data, string $s3_path): void
    {
        throw new RuntimeException('Task execution failed');
    }
}

class TaskLifecycleEventTest extends TestCase
{
    private static School $school;
    private User $user;
    private Assessment $assessment;
    private array $tempFiles = [];

    public static function setUpBeforeClass(): void
    {
        self::$school = School::ensureIndependent();
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
        $this->user = self::$school->addUser(
            "teacher_{$suffix}@ind.test",
            "Teacher {$suffix}",
            "pass-{$suffix}",
            User::ROLE_TEACHER
        );
        $this->assessment = new Assessment();
        $this->assessment->school_id = $this->user->school_id;
        $this->assessment->user_id = $this->user->id;
        $this->assessment->name = 'Test Assessment ' . $suffix;
        $this->assessment->subject = 'Dictation';
        $this->assessment->date = '2026-06-15';
        $this->assessment->id = HashId::create();
        $this->assessment->save();
    }

    protected function tearDown(): void
    {
        SSEvent::$publisher = null;
        RedisQueue::setInstance(null);
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    private function createTempFile(string $content = 'dummy'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test_task_');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }

    public function testTaskPublishesLoadingTrueAtStartAndLoadingFalseAtEnd(): void
    {
        $filePath = $this->createTempFile('file content');
        $file = $this->assessment->createFileFromPath('copy.png', $filePath, 'image/png', 'submission', null);

        $events = [];
        SSEvent::$publisher = function (string $channel, array $payload) use (&$events): void {
            $events[] = ['channel' => $channel, 'payload' => $payload];
        };

        $task = new ConcreteTaskForTest();
        $task->process_task((object) [
            'path' => $file->contentKey(),
            'task' => ConcreteTaskForTest::class,
            'task_id' => ConcreteTaskForTest::class,
            'file_id' => $file->id,
        ]);

        $this->assertTrue($task->processed);
        $this->assertInstanceOf(DictationSubmission::class, $task->fileDuringProcess);
        $this->assertInstanceOf(DictationSubmission::class, InputFile::from_path($file->attrKey()));
        $this->assertInstanceOf(DictationSubmission::class, InputFile::from_path($file->prefix()));
        $this->assertCount(2, $events);

        // First event: start of task, loading: true
        $startEvent = $events[0];
        $this->assertSame(SSEvent::assessmentChannel((string) $this->assessment->id), $startEvent['channel']);
        $this->assertSame('assessment', $startEvent['payload']['scope']);
        $this->assertSame($file->id, $startEvent['payload']['file']['id']);
        $this->assertTrue($startEvent['payload']['file']['loading']);

        // Second event: end of task, loading: false and updated status
        $endEvent = $events[1];
        $this->assertSame(SSEvent::assessmentChannel((string) $this->assessment->id), $endEvent['channel']);
        $this->assertSame('assessment', $endEvent['payload']['scope']);
        $this->assertSame($file->id, $endEvent['payload']['file']['id']);
        $this->assertFalse($endEvent['payload']['file']['loading']);
        $this->assertSame('errors_found', $endEvent['payload']['file']['status']);
    }

    public function testFailingTaskStillPublishesLoadingFalseInFinally(): void
    {
        $filePath = $this->createTempFile('file content');
        $file = $this->assessment->createFileFromPath('failing.png', $filePath, 'image/png', 'submission', null);

        $events = [];
        SSEvent::$publisher = function (string $channel, array $payload) use (&$events): void {
            $events[] = ['channel' => $channel, 'payload' => $payload];
        };

        $task = new FailingTaskForTest();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Task execution failed');

        try {
            $task->process_task((object) [
                'path' => $file->contentKey(),
                'task' => FailingTaskForTest::class,
                'task_id' => FailingTaskForTest::class,
                'file_id' => $file->id,
            ]);
        } finally {
            $this->assertCount(2, $events);
            $this->assertTrue($events[0]['payload']['file']['loading']);
            $this->assertFalse($events[1]['payload']['file']['loading']);
        }
    }

    public function testRedisConsumerDoesNotPublishSSEventDirectly(): void
    {
        $filePath = $this->createTempFile('file content');
        $file = $this->assessment->createFileFromPath('notask.png', $filePath, 'image/png', 'submission', null);

        $events = [];
        SSEvent::$publisher = function (string $channel, array $payload) use (&$events): void {
            $events[] = ['channel' => $channel, 'payload' => $payload];
        };

        // Ticket without task triggers treat($fileId) -> dispatch, not a task
        RedisConsumer::handleTicket(['file_id' => $file->id]);

        // RedisConsumer itself must not publish any events
        $this->assertCount(0, $events);
    }

    public function testTaskPublishesToStudentChannelWhenFileHasStudent(): void
    {
        $filePath = $this->createTempFile('file content');
        $file = $this->assessment->createFileFromPath('copy.png', $filePath, 'image/png', 'submission', null);

        $student = $this->assessment->findOrCreateStudentByName('Marie Curie');
        $file->student = $student->id;
        $file->saveAttributes();

        $events = [];
        SSEvent::$publisher = function (string $channel, array $payload) use (&$events): void {
            $events[] = ['channel' => $channel, 'payload' => $payload];
        };

        $task = new ConcreteTaskForTest();
        $task->process_task((object) [
            'path' => $file->contentKey(),
            'task' => ConcreteTaskForTest::class,
            'task_id' => ConcreteTaskForTest::class,
            'file_id' => $file->id,
        ]);

        $this->assertTrue($task->processed);

        // We expect assessment and student channel events at start, and both at end
        $channels = array_column($events, 'channel');
        $studentChannel = SSEvent::studentChannel((string) $student->id);
        $assessmentChannel = SSEvent::assessmentChannel((string) $this->assessment->id);

        $this->assertContains($studentChannel, $channels);
        $this->assertContains($assessmentChannel, $channels);

        $studentEvents = array_values(array_filter($events, fn($e) => $e['channel'] === $studentChannel));
        $this->assertCount(2, $studentEvents);

        $this->assertTrue($studentEvents[0]['payload']['file']['loading']);
        $this->assertFalse($studentEvents[1]['payload']['file']['loading']);
    }
}
