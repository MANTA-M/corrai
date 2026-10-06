<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Assessment;
use Corrai\Model\School;
use Corrai\Model\User;
use Corrai\Queue\RedisQueue;
use Corrai\Task\Thumbnail;
use Corrai\Utils\Store\HashId;
use Corrai\Utils\Store\ObjectStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Redis;

class ThumbnailTaskTest extends TestCase
{
    private array $tempFiles = [];

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }
    }

    protected function tearDown(): void
    {
        RedisQueue::setInstance(null);
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    public function testJpegBytesShrinksAPngSoTheLongSideFits(): void
    {
        $jpeg = Thumbnail::jpegBytes($this->pngBytes(400, 200), 256);
        $size = @getimagesizefromstring($jpeg);

        $this->assertNotFalse($size);
        $this->assertSame(IMAGETYPE_JPEG, $size[2]);
        $this->assertSame(256, $size[0]);
        $this->assertSame(128, $size[1]);
    }

    public function testJpegBytesAcceptsJpegSource(): void
    {
        $jpeg = Thumbnail::jpegBytes($this->jpegBytes(80, 40), 256);
        $size = @getimagesizefromstring($jpeg);

        $this->assertNotFalse($size);
        $this->assertSame(IMAGETYPE_JPEG, $size[2]);
        $this->assertSame(80, $size[0]);
        $this->assertSame(40, $size[1]);
    }

    public function testJpegBytesAcceptsWebpSource(): void
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            $this->markTestSkipped('GD with WebP support required');
        }

        $jpeg = Thumbnail::jpegBytes($this->webpBytes(300, 150), 200);
        $size = @getimagesizefromstring($jpeg);

        $this->assertNotFalse($size);
        $this->assertSame(IMAGETYPE_JPEG, $size[2]);
        $this->assertSame(200, $size[0]);
        $this->assertSame(100, $size[1]);
    }

    public function testJpegBytesAcceptsHeicSource(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is required to decode HEIC');
        }
        $formats = array_map('strtoupper', (new \Imagick())->queryFormats('HEI*'));
        if (!in_array('HEIC', $formats, true) && !in_array('HEIF', $formats, true)) {
            $this->markTestSkipped('Imagick has no HEIC decoder');
        }

        $heic = file_get_contents(__DIR__ . '/fixtures/capture.heic');
        $this->assertNotFalse($heic);
        $jpeg = Thumbnail::jpegBytes($heic, 256);
        $size = @getimagesizefromstring($jpeg);

        $this->assertNotFalse($size);
        $this->assertSame(IMAGETYPE_JPEG, $size[2]);
        $this->assertSame(16, $size[0]);
        $this->assertSame(10, $size[1]);
    }

    public function testJpegBytesAcceptsTiffSource(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is required to decode TIFF');
        }

        $jpeg = Thumbnail::jpegBytes($this->tiffBytes(300, 100), 150);
        $size = @getimagesizefromstring($jpeg);

        $this->assertNotFalse($size);
        $this->assertSame(IMAGETYPE_JPEG, $size[2]);
        $this->assertSame(150, $size[0]);
        $this->assertSame(50, $size[1]);
    }

    public function testJpegBytesRejectsNonImages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Thumbnail::jpegBytes('not-an-image');
    }

    public function testTaskWritesThumbnailAnnex(): void
    {
        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lPush', 'brPop', 'connect'])
            ->getMock();
        $redis->method('lPush')->willReturn(1);
        RedisQueue::setInstance(new RedisQueue($redis));

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
        $assessment->name = 'Test Assessment ' . $suffix;
        $assessment->subject = 'Dictation';
        $assessment->date = '2026-06-15';
        $assessment->id = HashId::create();
        $assessment->save();

        $path = $this->writeTemp($this->pngBytes(320, 160));
        $file = $assessment->createFileFromPath('copy.png', $path, 'image/png', 'submission', null);

        $task = new Thumbnail();
        $task->process_task((object) [
            'path' => $file->contentKey(),
            'task' => Thumbnail::class,
            'task_id' => Thumbnail::class,
            'file_id' => $file->id,
        ]);

        $store = ObjectStore::getInstance();
        $this->assertTrue($store->exists($file->thumbnailKey()));
        $this->assertContains(ObjectStore::THUMBNAIL_FILE, $file->listAnnexes());

        $size = @getimagesizefromstring($store->getContents($file->thumbnailKey()));
        $this->assertNotFalse($size);
        $this->assertSame(IMAGETYPE_JPEG, $size[2]);
        $this->assertSame(250, $size[0]);
        $this->assertSame(125, $size[1]);
        $this->assertStringEndsWith('/thumbnail', $file->thumbnailKey());

        $stored = \Corrai\Model\InputFile::from_hash((string) $file->id);
        $this->assertTrue($stored->thumbnail);

        $dirObjects = $stored->listDirectoryObjects();
        $this->assertContains('attributes.json', $dirObjects);
        $this->assertContains('thumbnail', $dirObjects);
        $this->assertNotContains('content', $dirObjects);
    }

    public function testListDirectoryObjectsOnlyIncludesImmediateFiles(): void
    {
        $school = School::ensureIndependent();
        $user = $school->addUser(
            "teacher_" . bin2hex(random_bytes(4)) . "@ind.test",
            "Teacher",
            "pass",
            User::ROLE_TEACHER
        );
        $assessment = new Assessment();
        $assessment->school_id = $user->school_id;
        $assessment->user_id = $user->id;
        $assessment->name = 'Test Directory Objects';
        $assessment->subject = 'Dictation';
        $assessment->date = '2026-06-15';
        $assessment->id = HashId::create();
        $assessment->save();

        $path = $this->writeTemp($this->pngBytes(100, 100));
        $file = $assessment->createFileFromPath('copy.png', $path, 'image/png', 'submission', null);

        $store = ObjectStore::getInstance();
        $eventKey = ObjectStore::assessmentFileEventKey(
            $file->school_id,
            $file->user_id,
            $file->assessment_id,
            (string) $file->id,
            'test-event',
            $file->type,
            $file->student
        );
        $store->putContents($eventKey, '{}', 'application/json');

        $stored = \Corrai\Model\InputFile::from_hash((string) $file->id);
        $objects = $stored->listDirectoryObjects();

        $this->assertContains('attributes.json', $objects);
        $this->assertNotContains('content', $objects);
        $this->assertNotContains('events/test-event.json', $objects);
        foreach ($objects as $obj) {
            $this->assertStringNotContainsString('/', $obj);
        }
    }

    public function testJpegBytesMaxWidthScalesWidthAndKeepsRatio(): void
    {
        $wide = Thumbnail::jpegBytesMaxWidth($this->pngBytes(500, 200));
        $wideSize = @getimagesizefromstring($wide);
        $this->assertNotFalse($wideSize);
        $this->assertSame(250, $wideSize[0]);
        $this->assertSame(100, $wideSize[1]);

        $tall = Thumbnail::jpegBytesMaxWidth($this->pngBytes(100, 400));
        $tallSize = @getimagesizefromstring($tall);
        $this->assertNotFalse($tallSize);
        $this->assertSame(100, $tallSize[0]);
        $this->assertSame(400, $tallSize[1]);
    }

    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $red = imagecolorallocate($image, 200, 40, 40);
        imagefilledrectangle($image, 0, 0, $width, $height, $red);
        ob_start();
        imagepng($image);
        imagedestroy($image);
        $bytes = ob_get_clean();
        $this->assertNotFalse($bytes);
        return $bytes;
    }

    private function jpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $blue = imagecolorallocate($image, 40, 80, 200);
        imagefilledrectangle($image, 0, 0, $width, $height, $blue);
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);
        $bytes = ob_get_clean();
        $this->assertNotFalse($bytes);
        return $bytes;
    }

    private function webpBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $green = imagecolorallocate($image, 40, 200, 80);
        imagefilledrectangle($image, 0, 0, $width, $height, $green);
        ob_start();
        imagewebp($image, null, 90);
        imagedestroy($image);
        $bytes = ob_get_clean();
        $this->assertNotFalse($bytes);
        return $bytes;
    }

    private function tiffBytes(int $width, int $height): string
    {
        $imagick = new \Imagick();
        $imagick->newImage($width, $height, new \ImagickPixel('#228B22'));
        $imagick->setImageFormat('tiff');
        $bytes = $imagick->getImageBlob();
        $imagick->clear();
        $this->assertNotSame('', $bytes);
        return $bytes;
    }

    private function writeTemp(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test_thumb_');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }
}
