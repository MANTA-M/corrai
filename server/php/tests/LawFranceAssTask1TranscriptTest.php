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
use Corrai\Subject\LawFrance\AssTask1Transcript;
use Corrai\Subject\LawFrance\Submission;
use Corrai\Subject\LawFrance\SubmissionTask2RotateAndCrop;
use Corrai\Subject\LawFrance\SubmissionTask3Identify;
use Corrai\Task\Thumbnail;
use Corrai\Utils\Store\HashId;
use Corrai\Utils\Store\ObjectStore;
use PHPUnit\Framework\TestCase;
use Redis;

class LawFranceAssTask1TranscriptTest extends TestCase
{
    /** @var list<string> */
    private array $queued = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->installQueue();
    }

    protected function tearDown(): void
    {
        RedisQueue::setInstance(null);
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    public function testCorrectUnclassifiedFilesMarksTranscribingAndEnqueuesAssTask1Transcript(): void
    {
        $file = $this->storedPng();
        $assessment = Assessment::from_hash((string) $file->assessment_id);

        $assessment->correctUnclassifiedFiles();

        $reloaded = InputFile::from_hash((string) $file->id);
        $this->assertSame('transcribing', $reloaded->status);
        $this->assertContains(AssTask1Transcript::class, $this->tasks());
        $this->assertTrue(class_exists(\LawFrance\AssTask1Transcript::class));
    }

    public function testProcessAssessmentRunsOcrRotateAndEnqueuesIdentify(): void
    {
        $file = $this->storedPng();
        $file->status = 'transcribing';
        $file->saveAttributes();
        $assessment = Assessment::from_hash((string) $file->assessment_id);

        $ocrOutput = [
            'text' => 'Article 1er',
            'bounding_boxes' => [
                ['text' => 'Article', 'left' => 0.40, 'top' => 0.10, 'width' => 0.08, 'height' => 0.20],
                ['text' => '1er', 'left' => 0.40, 'top' => 0.32, 'width' => 0.08, 'height' => 0.23],
            ],
            'usage' => null,
        ];

        $mockVision = $this->createMock(Vision::class);
        $mockVision->expects($this->once())->method('authorize');
        $mockVision->method('buildImagesAnnotatePostData')->willReturn(['requests' => [['image' => ['content' => 'x']]]]);
        $mockVision->method('outputFromImagesAnnotateBody')->willReturn($ocrOutput);
        $mockVision->expects($this->once())
            ->method('QueryMulti')
            ->willReturnCallback(function (array $requests, callable $onResponse): void {
                $this->assertCount(1, $requests);
                $this->assertSame((string) $requests[0]['file_id'], $requests[0]['file_id']);
                $onResponse($requests[0], '{}', 200);
            });

        $rotate = $this->createMock(SubmissionTask2RotateAndCrop::class);
        $rotate->expects($this->once())
            ->method('apply')
            ->willReturnCallback(function (InputFile $copy): void {
                $copy->status = 'transcribed';
                $copy->saveAttributes();
                RedisQueue::getInstance()->enqueueFile((string) $copy->id, Thumbnail::class);
                RedisQueue::getInstance()->enqueueFile((string) $copy->id, SubmissionTask3Identify::class);
            });

        $task = new AssTask1Transcript($mockVision, null, null, $rotate);
        $task->processAssessment($assessment);

        $store = ObjectStore::getInstance();
        $this->assertTrue($store->exists($file->ocrResultKey()));
        $this->assertSame('Article 1er', OCRResult::from_json($store->getContents($file->ocrResultKey()))->text);

        $reloaded = InputFile::from_hash((string) $file->id);
        $this->assertSame('transcribed', $reloaded->status);
        $events = array_column($reloaded->listEvents(), 'name');
        $this->assertContains('OCR started', $events);
        $this->assertContains('OCR ended', $events);

        $tasks = $this->tasks();
        $this->assertContains(Thumbnail::class, $tasks);
        $this->assertContains(SubmissionTask3Identify::class, $tasks);
    }

    public function testSkipsUnclassifiedFilesThatAlreadyHaveOcrResult(): void
    {
        $file = $this->storedPng();
        $file->status = 'transcribing';
        $file->saveAttributes();
        $store = ObjectStore::getInstance();
        $ocr = OCRResult::from_google([
            'text' => 'Already done',
            'bounding_boxes' => [
                ['text' => 'Already', 'left' => 0.1, 'top' => 0.1, 'width' => 0.2, 'height' => 0.1],
            ],
        ]);
        $store->putContents($file->ocrResultKey(), $ocr->to_json(true), 'application/json');

        $mockVision = $this->createMock(Vision::class);
        $mockVision->expects($this->never())->method('QueryMulti');
        $mockVision->expects($this->never())->method('authorize');

        $assessment = Assessment::from_hash((string) $file->assessment_id);
        (new AssTask1Transcript($mockVision))->processAssessment($assessment);

        $this->assertSame('Already done', OCRResult::from_json($store->getContents($file->ocrResultKey()))->text);
        $this->assertNotContains(SubmissionTask3Identify::class, $this->tasks());
    }

    public function testProcessesEveryUnclassifiedFileWithoutOcr(): void
    {
        $first = $this->storedPng();
        $assessment = Assessment::from_hash((string) $first->assessment_id);
        $path = $this->writeTemp($this->pngBytes());
        $second = $assessment->createFileFromPath('copie-b.png', $path, 'image/png', 'submission', null);
        $this->assertInstanceOf(Submission::class, $second);

        $first->status = 'transcribing';
        $first->saveAttributes();
        $second->status = 'transcribing';
        $second->saveAttributes();

        $ids = $assessment->unclassifiedFileIds();
        $this->assertContains((string) $first->id, $ids);
        $this->assertContains((string) $second->id, $ids);

        $ocrOutput = [
            'text' => 'Page',
            'bounding_boxes' => [
                ['text' => 'Page', 'left' => 0.1, 'top' => 0.1, 'width' => 0.2, 'height' => 0.1],
            ],
            'usage' => null,
        ];
        $mockVision = $this->createMock(Vision::class);
        $mockVision->method('authorize');
        $mockVision->method('buildImagesAnnotatePostData')->willReturn(['requests' => []]);
        $mockVision->method('outputFromImagesAnnotateBody')->willReturn($ocrOutput);
        $mockVision->expects($this->once())
            ->method('QueryMulti')
            ->willReturnCallback(function (array $requests, callable $onResponse) use ($first, $second): void {
                $this->assertCount(2, $requests);
                $fileIds = array_column($requests, 'file_id');
                $this->assertContains((string) $first->id, $fileIds);
                $this->assertContains((string) $second->id, $fileIds);
                foreach ($requests as $params) {
                    $onResponse($params, '{}', 200);
                }
            });

        $rotate = $this->createMock(SubmissionTask2RotateAndCrop::class);
        $rotate->expects($this->exactly(2))->method('apply');

        (new AssTask1Transcript($mockVision, null, null, $rotate))->processAssessment($assessment);

        $store = ObjectStore::getInstance();
        $this->assertTrue($store->exists($first->ocrResultKey()));
        $this->assertTrue($store->exists($second->ocrResultKey()));
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

    private function storedPng(?int $width = null, ?int $height = null): Submission
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

    private function pngBytes(?int $width = null, ?int $height = null): string
    {
        if ($width === null || $height === null) {
            // 1x1 PNG — no GD required
            return base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                true
            ) ?: '';
        }
        if (!extension_loaded('gd')) {
            $this->fail('GD extension required to build a sized PNG');
        }
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
        $path = tempnam(sys_get_temp_dir(), 'law_ass1_');
        $this->assertNotFalse($path);
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;
        return $path;
    }
}
