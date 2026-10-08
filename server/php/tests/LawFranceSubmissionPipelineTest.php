<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Clients\Google\Vision;
use Corrai\Model\InputFile;
use Corrai\Model\OCRResult;
use Corrai\Model\School;
use Corrai\Model\User;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\LawFrance\Assessment;
use Corrai\Subject\LawFrance\AssTask1Affectation;
use Corrai\Subject\LawFrance\Submission;
use Corrai\Subject\LawFrance\SubmissionTask1Ocr;
use Corrai\Subject\LawFrance\SubmissionTask2Rotate;
use Corrai\Subject\LawFrance\SubmissionTask3Crop;
use Corrai\Subject\LawFrance\SubmissionTask3Identify;
use Corrai\Subject\LawFrance\SubmissionTask3Thumbnail;
use Corrai\Subject\LawFrance\SubmissionTask4Thumbnail;
use Corrai\Task\TaskRotateAndCrop;
use Corrai\Utils\Store\HashId;
use Corrai\Utils\Store\ObjectStore;
use PHPUnit\Framework\TestCase;
use Redis;

class LawFranceSubmissionPipelineTest extends TestCase
{
    /** @var list<string> */
    private array $queued = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }
        $this->installQueue();
    }

    protected function tearDown(): void
    {
        RedisQueue::setInstance(null);
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    public function testOnCorrectionAskedSetsStatusTranscribingAndEnqueuesTask1(): void
    {
        $copy = new Submission();
        $copy->id = 'copy-law-1';
        $copy->name = 'copie.png';
        $copy->content_type = 'image/png';

        $copy->on_correction_asked();

        $this->assertSame('transcribing', $copy->status);
        $this->assertSame([SubmissionTask1Ocr::class], $this->tasks());
    }

    public function testSubmissionTask1OcrRunsVisionAndEnqueuesTask2Rotate(): void
    {
        $file = $this->storedPng(400, 200);

        $mockVision = $this->createMock(Vision::class);
        $mockVision->expects($this->once())
            ->method('process')
            ->willReturn([
                'text' => 'Article 1er',
                'bounding_boxes' => [
                    ['text' => 'Article', 'left' => 0.40, 'top' => 0.10, 'width' => 0.08, 'height' => 0.20],
                    ['text' => '1er', 'left' => 0.40, 'top' => 0.32, 'width' => 0.08, 'height' => 0.23],
                ],
            ]);

        $task = new SubmissionTask1Ocr($mockVision);
        $task->process_task((object) [
            'path' => $file->contentKey(),
            'task' => SubmissionTask1Ocr::class,
            'task_id' => SubmissionTask1Ocr::class,
            'file_id' => $file->id,
        ]);

        $store = ObjectStore::getInstance();
        $this->assertTrue($store->exists($file->ocrResultKey()));

        $ocrData = json_decode($store->getContents($file->ocrResultKey()), true);
        $this->assertSame('Article 1er', $ocrData['text']);

        $this->assertContains(SubmissionTask2Rotate::class, $this->tasks());
    }

    public function testSubmissionTask2RotateRotatesImageAndEnqueuesTask3Crop(): void
    {
        $file = $this->storedPng(400, 200);

        $ocr = OCRResult::from_google([
            'text' => 'Article 1er',
            'bounding_boxes' => [
                ['text' => 'Article', 'left' => 0.40, 'top' => 0.10, 'width' => 0.08, 'height' => 0.20],
                ['text' => '1er', 'left' => 0.40, 'top' => 0.32, 'width' => 0.08, 'height' => 0.23],
            ],
        ]);
        $store = ObjectStore::getInstance();
        $store->putContents($file->ocrResultKey(), $ocr->to_json(true), 'application/json');

        $beforeCount = count($this->queued);
        $task = new SubmissionTask2Rotate();
        $task->process_task((object) [
            'path' => $file->contentKey(),
            'task' => SubmissionTask2Rotate::class,
            'task_id' => SubmissionTask2Rotate::class,
            'file_id' => $file->id,
        ]);

        $storedOcr = OCRResult::from_json($store->getContents($file->ocrResultKey()));
        $this->assertSame(0, $storedOcr->rotation);

        $reloaded = InputFile::from_hash((string) $file->id);
        $events = array_column($reloaded->listEvents(), 'name');
        $this->assertContains(TaskRotateAndCrop::rotationEvent(90), $events);

        $tasks = $this->tasks();
        $this->assertSame(SubmissionTask3Crop::class, end($tasks));
    }

    public function testSubmissionTask3CropCropsImageAndEnqueuesSubmissionTask3Identify(): void
    {
        $file = $this->storedPng(200, 400);
        $file->status = 'transcribing';
        $file->saveAttributes();

        $ocr = OCRResult::from_google([
            'text' => 'Article 1er',
            'bounding_boxes' => [
                ['text' => 'Article', 'left' => 0.10, 'top' => 0.10, 'width' => 0.20, 'height' => 0.08],
                ['text' => '1er', 'left' => 0.32, 'top' => 0.10, 'width' => 0.23, 'height' => 0.08],
            ],
        ]);
        $store = ObjectStore::getInstance();
        $store->putContents($file->ocrResultKey(), $ocr->to_json(true), 'application/json');

        $task = new SubmissionTask3Crop();
        $task->process_task((object) [
            'path' => $file->contentKey(),
            'task' => SubmissionTask3Crop::class,
            'task_id' => SubmissionTask3Crop::class,
            'file_id' => $file->id,
        ]);

        $reloaded = InputFile::from_hash((string) $file->id);
        $this->assertSame('transcribing', $reloaded->status);
        $events = array_column($reloaded->listEvents(), 'name');
        $this->assertContains(TaskRotateAndCrop::CROP_EVENT, $events);

        $assessment = Assessment::from_hash((string) $file->assessment_id);
        $this->assertSame('draft', $assessment->status);

        $tasks = $this->tasks();
        $this->assertSame(SubmissionTask3Identify::class, end($tasks));
    }

    public function testSubmissionTask3IdentifySetsStatusTranscribedAndEnqueuesAssTask1Affectation(): void
    {
        $file = $this->storedPng(200, 400);

        $task = new SubmissionTask3Identify();
        $task->process_task((object) [
            'path' => $file->contentKey(),
            'task' => SubmissionTask3Identify::class,
            'task_id' => SubmissionTask3Identify::class,
            'file_id' => $file->id,
        ]);

        $reloaded = InputFile::from_hash((string) $file->id);
        $this->assertSame('transcribed', $reloaded->status);
        $events = array_column($reloaded->listEvents(), 'name');
        $this->assertContains(SubmissionTask3Identify::IDENTIFY_EVENT, $events);

        $assessment = Assessment::from_hash((string) $file->assessment_id);
        $this->assertSame('affecting', $assessment->status);

        $tasks = $this->tasks();
        $this->assertSame(AssTask1Affectation::class, end($tasks));
    }

    public function testSubmissionTask3ThumbnailCreatesThumbnail(): void
    {
        $file = $this->storedPng(300, 150);

        $task = new SubmissionTask3Thumbnail();
        $task->process_task((object) [
            'path' => $file->contentKey(),
            'task' => SubmissionTask3Thumbnail::class,
            'task_id' => SubmissionTask3Thumbnail::class,
            'file_id' => $file->id,
        ]);

        $store = ObjectStore::getInstance();
        $this->assertTrue($store->exists($file->thumbnailKey()));

        $stored = InputFile::from_hash((string) $file->id);
        $this->assertTrue($stored->thumbnail);

        $events = array_column($stored->listEvents(), 'name');
        $this->assertContains('Thumbnail created', $events);
    }

    public function testAliasesExist(): void
    {
        $this->assertTrue(class_exists(\LawFrance\Submission::class));
        $this->assertTrue(class_exists(\LawFrance\SubmissionTask1Ocr::class));
        $this->assertTrue(class_exists(\LawFrance\SubmissionTask2Rotate::class));
        $this->assertTrue(class_exists(\LawFrance\SubmissionTask3Crop::class));
        $this->assertTrue(class_exists(\LawFrance\SubmissionTask3Identify::class));
        $this->assertTrue(class_exists(SubmissionTask3Identify::class));
        $this->assertTrue(class_exists(\LawFrance\AssTask1Affectation::class));
        $this->assertTrue(class_exists(AssTask1Affectation::class));
        $this->assertTrue(class_exists(\LawFrance\SubmissionTask3Thumbnail::class));
        $this->assertTrue(class_exists(\LawFrance\SubmissionTask4Thumbnail::class));
        $this->assertTrue(class_exists(SubmissionTask4Thumbnail::class));
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

    private function storedPng(int $width, int $height): Submission
    {
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

        $path = $this->writeTemp($this->pngBytes($width, $height));
        $file = $assessment->createFileFromPath('copie.png', $path, 'image/png', 'submission', null);
        $this->assertInstanceOf(Submission::class, $file);
        return $file;
    }

    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $red = imagecolorallocate($image, 200, 40, 40);
        imagefilledrectangle($image, 0, 0, $width, $height, $red === false ? 0 : $red);
        ob_start();
        imagepng($image);
        imagedestroy($image);
        return (string) ob_get_clean();
    }

    private function writeTemp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'law_pipe_');
        $this->assertNotFalse($path);
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;
        return $path;
    }
}
