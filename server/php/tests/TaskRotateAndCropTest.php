<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Assessment;
use Corrai\Model\InputFile;
use Corrai\Model\OCRResult;
use Corrai\Model\School;
use Corrai\Model\User;
use Corrai\Queue\RedisQueue;
use Corrai\Task\TaskRotateAndCrop;
use Corrai\Utils\Store\HashId;
use Corrai\Utils\Store\ObjectStore;
use PHPUnit\Framework\TestCase;
use Redis;

class TaskRotateAndCropTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }
        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lPush', 'brPop', 'connect'])
            ->getMock();
        $redis->method('lPush')->willReturn(1);
        RedisQueue::setInstance(new RedisQueue($redis));
    }

    protected function tearDown(): void
    {
        RedisQueue::setInstance(null);
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    public function testQuarterTurnIsUprightThenCroppedToTheText(): void
    {
        $width = 400;
        $height = 200;
        $file = $this->storedPng($width, $height);
        $ocr = OCRResult::from_google([
            'text' => 'Une makine',
            'bounding_boxes' => [
                ['text' => 'Une', 'left' => 0.40, 'top' => 0.10, 'width' => 0.08, 'height' => 0.20],
                ['text' => 'makine', 'left' => 0.40, 'top' => 0.32, 'width' => 0.08, 'height' => 0.23],
            ],
        ]);
        $store = ObjectStore::getInstance();
        $store->putContents($file->ocrResultKey(), $ocr->to_json(true), 'application/json');

        $expected = OCRResult::from_json($ocr->to_json());
        $expected->detectRotation();
        $this->assertSame(90, $expected->rotation);
        $expected->rotate_upright();
        $box = $expected->get_global_box(TaskRotateAndCrop::MARGIN);
        $expected->resize_to_global($box);

        $task = new TaskRotateAndCrop();
        $task->process_task((object) [
            'path' => $file->contentKey(),
            'task' => TaskRotateAndCrop::class,
            'task_id' => TaskRotateAndCrop::class,
            'file_id' => $file->id,
        ]);

        $stored = OCRResult::from_json($store->getContents($file->ocrResultKey()));
        $this->assertSame(0, $stored->rotation);
        $this->assertCount(2, $stored->words);
        foreach ($expected->words as $index => $word) {
            $this->assertEqualsWithDelta($word->left, $stored->words[$index]->left, 1e-9);
            $this->assertEqualsWithDelta($word->top, $stored->words[$index]->top, 1e-9);
            $this->assertEqualsWithDelta($word->right, $stored->words[$index]->right, 1e-9);
            $this->assertEqualsWithDelta($word->bottom, $stored->words[$index]->bottom, 1e-9);
        }

        $image = @getimagesizefromstring($store->getContents($file->contentKey()));
        $this->assertNotFalse($image);
        $this->assertSame(IMAGETYPE_PNG, $image[2]);
        $x0 = (int) round($box['left'] * $height);
        $y0 = (int) round($box['top'] * $width);
        $x1 = (int) round($box['right'] * $height);
        $y1 = (int) round($box['bottom'] * $width);
        $this->assertSame(max(1, $x1 - $x0), $image[0]);
        $this->assertSame(max(1, $y1 - $y0), $image[1]);

        $reloaded = InputFile::from_hash((string) $file->id);
        $this->assertSame(strlen($store->getContents($file->contentKey())), $reloaded->size);
        $names = array_column($reloaded->listEvents(), 'name');
        $this->assertContains(TaskRotateAndCrop::rotationEvent(90), $names);
        $this->assertContains(TaskRotateAndCrop::CROP_EVENT, $names);
    }

    public function testAFileThatIsNotAnImageIsLeftAlone(): void
    {
        $file = $this->storedPng(40, 20);
        $store = ObjectStore::getInstance();
        $store->putContents($file->contentKey(), 'not an image', 'text/plain');
        $ocr = OCRResult::from_google([
            'text' => 'Une',
            'bounding_boxes' => [
                ['text' => 'Une', 'left' => 0.10, 'top' => 0.10, 'width' => 0.20, 'height' => 0.05],
                ['text' => 'makine', 'left' => 0.32, 'top' => 0.10, 'width' => 0.20, 'height' => 0.05],
            ],
        ]);
        $json = $ocr->to_json(true);
        $store->putContents($file->ocrResultKey(), $json, 'application/json');

        $task = new TaskRotateAndCrop();
        $task->process_task((object) [
            'path' => $file->contentKey(),
            'task' => TaskRotateAndCrop::class,
            'task_id' => TaskRotateAndCrop::class,
            'file_id' => $file->id,
        ]);

        $this->assertSame('not an image', $store->getContents($file->contentKey()));
        $this->assertSame($json, $store->getContents($file->ocrResultKey()));
    }

    private function storedPng(int $width, int $height): InputFile
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
        $assessment->name = 'Rotate ' . $suffix;
        $assessment->subject = 'Dictation';
        $assessment->date = '2026-06-15';
        $assessment->id = HashId::create();
        $assessment->save();

        $path = $this->writeTemp($this->pngBytes($width, $height));
        $file = $assessment->createFileFromPath('copy.png', $path, 'image/png', 'submission', null);
        $this->assertInstanceOf(InputFile::class, $file);
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
        $bytes = ob_get_clean();
        $this->assertNotFalse($bytes);
        return $bytes;
    }

    private function writeTemp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rotate_');
        $this->assertNotFalse($path);
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;
        return $path;
    }
}
