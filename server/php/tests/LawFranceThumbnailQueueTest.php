<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\School;
use Corrai\Model\SubjectFile;
use Corrai\Model\User;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\LawFrance\Assessment;
use Corrai\Subject\LawFrance\Submission;
use Corrai\Subject\LawFrance\Task1Transcribing;
use Corrai\Task\Thumbnail;
use Corrai\Utils\Store\HashId;
use PHPUnit\Framework\TestCase;
use Redis;

class LawFranceThumbnailQueueTest extends TestCase
{
    /** @var list<string> */
    private array $queued = [];

    protected function tearDown(): void
    {
        RedisQueue::setInstance(null);
    }

    public function testStoredImageCopyAndSubjectAreQueued(): void
    {
        $this->installQueue();

        $copy = new Submission();
        $copy->id = 'copy-1';
        $copy->name = 'copie.png';
        $copy->content_type = 'image/png';
        $copy->on_stored();

        $subject = new SubjectFile();
        $subject->id = 'subject-1';
        $subject->name = 'sujet.tiff';
        $subject->content_type = 'application/octet-stream';
        Submission::queueThumbnail($subject);

        $this->assertSame([
            Thumbnail::class,
            Thumbnail::class,
        ], $this->tasks());
    }

    public function testNonImageAndExistingThumbnailAreSkipped(): void
    {
        $this->installQueue();

        $pdf = new Submission();
        $pdf->id = 'pdf-1';
        $pdf->name = 'notes.pdf';
        $pdf->content_type = 'application/pdf';
        $pdf->on_stored();

        $done = new SubjectFile();
        $done->id = 'done-1';
        $done->name = 'page.jpg';
        $done->content_type = 'image/jpeg';
        $done->thumbnail = true;
        Submission::queueThumbnail($done);

        $this->assertSame([], $this->queued);
    }

    public function testUploadTaskQueuesASubjectImageWithoutTranscribing(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }
        $this->installQueue();

        $school = School::ensureIndependent();
        $suffix = bin2hex(random_bytes(4));
        $user = $school->addUser(
            "teacher_{$suffix}@ind.test",
            "Teacher {$suffix}",
            "pass-{$suffix}",
            User::ROLE_TEACHER
        );
        $assessment = new Assessment();
        $assessment->school_id = $user->school_id;
        $assessment->user_id = $user->id;
        $assessment->name = 'Law France ' . $suffix;
        $assessment->subject = 'Law';
        $assessment->country = 'fr';
        $assessment->date = '2026-06-15';
        $assessment->id = HashId::create();
        $assessment->save();

        $path = tempnam(sys_get_temp_dir(), 'law_thumb_');
        $this->assertNotFalse($path);
        $image = imagecreatetruecolor(40, 20);
        $this->assertNotFalse($image);
        ob_start();
        imagepng($image);
        imagedestroy($image);
        file_put_contents($path, (string) ob_get_clean());

        $file = $assessment->createFileFromPath('sujet.png', $path, 'image/png', 'subject', null);
        @unlink($path);

        $before = count($this->queued);
        $task = new Task1Transcribing();
        $task->process_task((object) [
            'path' => $file->contentKey(),
            'task' => Task1Transcribing::class,
            'task_id' => Task1Transcribing::class,
            'file_id' => $file->id,
        ]);

        $this->assertSame(Thumbnail::class, $this->tasks()[$before] ?? null);
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
     * @return list<string>
     */
    private function tasks(): array
    {
        $tasks = [];
        foreach ($this->queued as $payload) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded) && isset($decoded['task']) && is_string($decoded['task'])) {
                $tasks[] = $decoded['task'];
            }
        }
        return $tasks;
    }
}
