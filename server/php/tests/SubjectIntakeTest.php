<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Assessment;
use Corrai\Model\BaseAssessment;
use Corrai\Model\InputFile;
use Corrai\Model\School;
use Corrai\Model\User;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\Catalog;
use Corrai\Subject\SubjectImageOcr;
use Corrai\Subject\SubjectIntake;
use Corrai\Subject\SubjectPageReader;
use Corrai\Utils\Http\WSException;
use Corrai\Utils\Store\ObjectStore;
use PHPUnit\Framework\TestCase;
use Redis;

class SubjectIntakeTest extends TestCase
{
    private static School $indSchool;
    private User $user;

    /** @var list<string> */
    private array $queued = [];

    public static function setUpBeforeClass(): void
    {
        self::$indSchool = School::ensureIndependent();
    }

    protected function setUp(): void
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

        $suffix = bin2hex(random_bytes(4));
        $this->user = self::$indSchool->addUser(
            "intake_{$suffix}@ind.test",
            "[Test] Intake {$suffix}",
            'test-password-' . $suffix,
            User::ROLE_TEACHER
        );
    }

    protected function tearDown(): void
    {
        RedisQueue::setInstance(null);
        if (isset($this->user) && $this->user->id !== null) {
            try {
                $this->user->delete();
            } catch (\Throwable $e) {
                // Best-effort cleanup
            }
        }
    }

    public function testSubjectFileIsStoredAndAttributesComeFromTheReader(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $path = $this->pngFile();
        $ocr = $this->ocr('Contrôle de fractions');
        $reader = new class implements SubjectPageReader {
            public bool $sawText = false;

            public function read(string $pagePath, string $pageName, array $tree): array
            {
                $this->sawText = $pageName === 'page.txt'
                    && is_file($pagePath)
                    && str_contains((string) file_get_contents($pagePath), 'Contrôle de fractions')
                    && $tree !== [];
                return [
                    'name' => '[Test] Contrôle de fractions',
                    'subject' => 'Math',
                    'level' => 'cm2',
                    'date' => '2026-04-02',
                ];
            }
        };

        $assessment = SubjectIntake::create(
            $this->user,
            [['path' => $path, 'name' => 'fractions.png', 'contentType' => 'image/png']],
            'fr',
            $reader,
            $ocr
        );
        @unlink($path);

        $this->assertTrue($reader->sawText);
        $this->assertSame(['fractions.png'], $ocr->names);
        $loaded = Assessment::from_hash((string) $assessment->id);
        $this->assertSame('[Test] Contrôle de fractions', $loaded->name);
        $this->assertSame('Math', $loaded->subject);
        $this->assertSame('cm2', $loaded->level);
        $this->assertSame('2026-04-02', $loaded->date);
        $this->assertSame('Contrôle de fractions', $this->ocrText($loaded, 'fractions.png'));

        $files = $loaded->list_files();
        $this->assertCount(1, $files);
        $this->assertSame('fractions.png', $files[0]['name']);
        $this->assertSame('subject', $files[0]['type']);
        $this->assertSame([], $this->fileIds($this->queued));
    }

    public function testTextFileIsAnalyzedFromItsContents(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'intake_txt_');
        $this->assertNotFalse($path);
        file_put_contents($path, "Dictée\nCM1\n12 mars 2026");

        $ocr = $this->ocr('unused');
        $reader = new class implements SubjectPageReader {
            public bool $sawText = false;

            public function read(string $pagePath, string $pageName, array $tree): array
            {
                $this->sawText = $pageName === 'page.txt'
                    && is_file($pagePath)
                    && str_contains((string) file_get_contents($pagePath), 'Dictée');
                return [
                    'name' => '[Test] Dictée',
                    'subject' => 'Dictation',
                    'level' => 'cm1',
                    'date' => '2026-03-12',
                ];
            }
        };

        $assessment = SubjectIntake::create(
            $this->user,
            [['path' => $path, 'name' => 'dictee.txt', 'contentType' => 'text/plain']],
            'fr',
            $reader,
            $ocr
        );
        @unlink($path);

        $this->assertTrue($reader->sawText);
        $this->assertSame([], $ocr->names);
        $loaded = Assessment::from_hash((string) $assessment->id);
        $this->assertSame('[Test] Dictée', $loaded->name);
        $files = $loaded->list_files();
        $this->assertSame('dictee.txt', $files[0]['name']);
        $this->assertNull($this->ocrText($loaded, 'dictee.txt'));
    }

    public function testFailedReadingDeletesTheAssessment(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $path = $this->pngFile();
        $reader = new class implements SubjectPageReader {
            public function read(string $pagePath, string $pageName, array $tree): array
            {
                throw new WSException('Subject analysis failed', 502);
            }
        };

        try {
            SubjectIntake::create(
                $this->user,
                [['path' => $path, 'name' => 'sujet.png', 'contentType' => 'image/png']],
                'fr',
                $reader,
                $this->ocr('Sujet')
            );
            $this->fail('Expected the reader failure to surface');
        } catch (WSException $exception) {
            $this->assertSame(502, $exception->getCode());
        } finally {
            @unlink($path);
        }

        $this->assertSame([], $this->user->assessments());
    }

    public function testLaterFilesAreQueuedOnceSubjectCountryAndLevelAreKnown(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $intro = $this->pngFile();
        $suite = $this->pngFile();
        $reste = $this->pngFile();
        $ocr = new class implements SubjectImageOcr {
            /** @var list<string> */
            public array $names = [];

            public function recognize(string $path, string $filename): array
            {
                $this->names[] = $filename;
                $text = str_contains($filename, 'suite') ? "Dictée\nCM2" : 'Dictée';
                return [
                    'text' => $text,
                    'bounding_boxes' => [[
                        'text' => $text,
                        'left' => 0.1,
                        'top' => 0.2,
                        'width' => 0.3,
                        'height' => 0.05,
                    ]],
                ];
            }
        };
        $reader = new class implements SubjectPageReader {
            public int $calls = 0;

            public function read(string $pagePath, string $pageName, array $tree): array
            {
                $this->calls++;
                $text = (string) file_get_contents($pagePath);
                if (str_contains($text, 'CM2')) {
                    return [
                        'name' => '[Test] Dictée CM2',
                        'subject' => 'Dictation',
                        'level' => 'CM2',
                        'date' => '2026-05-01',
                    ];
                }
                return [
                    'name' => '[Test] Dictée',
                    'subject' => 'Dictation',
                    'level' => '',
                    'date' => '',
                ];
            }
        };

        $assessment = SubjectIntake::create(
            $this->user,
            [
                ['path' => $intro, 'name' => 'intro.png', 'contentType' => 'image/png'],
                ['path' => $suite, 'name' => 'suite.png', 'contentType' => 'image/png'],
                ['path' => $reste, 'name' => 'reste.png', 'contentType' => 'image/png'],
            ],
            'fr',
            $reader,
            $ocr
        );
        @unlink($intro);
        @unlink($suite);
        @unlink($reste);

        $this->assertSame(2, $reader->calls);
        $this->assertSame(['intro.png', 'suite.png'], $ocr->names);

        $loaded = Assessment::from_hash((string) $assessment->id);
        $this->assertSame('Dictation', $loaded->subject);
        $this->assertSame('fr', $loaded->country);
        $this->assertSame('CM2', $loaded->level);
        $this->assertSame('[Test] Dictée CM2', $loaded->name);
        $this->assertSame('Dictée', $this->ocrText($loaded, 'intro.png'));
        $this->assertSame("Dictée\nCM2", $this->ocrText($loaded, 'suite.png'));
        $this->assertNull($this->ocrText($loaded, 'reste.png'));

        $byName = $this->filesByName($loaded);
        $this->assertArrayHasKey('reste.png', $byName);
        $queuedIds = $this->fileIds($this->queued);
        $this->assertContains($byName['reste.png']->id, $queuedIds);
        $this->assertNotContains($byName['intro.png']->id, $queuedIds);
        $this->assertNotContains($byName['suite.png']->id, $queuedIds);
        $this->assertSame(
            Catalog::pipelineClass('Dictation', 'fr', 'CM2'),
            $this->taskFor($byName['reste.png']->id)
        );
    }

    public function testHeicSubjectIsStoredAsWebpBeforeOcr(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required');
        }
        $formats = array_map('strtoupper', (new \Imagick())->queryFormats('HEI*'));
        if (!in_array('HEIC', $formats, true) && !in_array('HEIF', $formats, true)) {
            $this->markTestSkipped('Imagick has no HEIC decoder');
        }

        $source = __DIR__ . '/fixtures/capture.heic';
        $ocr = $this->ocr('Page une');
        $reader = new class implements SubjectPageReader {
            public function read(string $pagePath, string $pageName, array $tree): array
            {
                return [
                    'name' => '[Test] Photo',
                    'subject' => 'Math',
                    'level' => '',
                    'date' => '',
                ];
            }
        };

        $assessment = SubjectIntake::create(
            $this->user,
            [['path' => $source, 'name' => 'capture.HEIC', 'contentType' => 'image/heic']],
            'fr',
            $reader,
            $ocr
        );

        $loaded = Assessment::from_hash((string) $assessment->id);
        $file = $this->filesByName($loaded)['capture.webp'] ?? null;
        $this->assertInstanceOf(InputFile::class, $file);
        $this->assertSame('image/webp', $file->content_type);
        $bytes = ObjectStore::getInstance()->getContents($file->contentKey());
        $info = getimagesizefromstring($bytes);
        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);
        $this->assertSame(['capture.webp'], $ocr->names);
        $this->assertSame('Page une', $this->ocrText($loaded, 'capture.webp'));
        $this->assertSame([], $this->fileIds($this->queued));
    }

    private function pngFile(): string
    {
        $image = imagecreatetruecolor(8, 8);
        $path = tempnam(sys_get_temp_dir(), 'intake_');
        $this->assertNotFalse($path);
        $this->assertNotFalse($image);
        imagepng($image, $path);
        imagedestroy($image);
        return $path;
    }

    private function ocr(string $text): SubjectImageOcr
    {
        return new class($text) implements SubjectImageOcr {
            /** @var list<string> */
            public array $names = [];

            public function __construct(private string $text)
            {
            }

            public function recognize(string $path, string $filename): array
            {
                $this->names[] = $filename;
                return [
                    'text' => $this->text,
                    'bounding_boxes' => [[
                        'text' => $this->text,
                        'left' => 0.1,
                        'top' => 0.2,
                        'width' => 0.3,
                        'height' => 0.05,
                    ]],
                ];
            }
        };
    }

    private function ocrText(BaseAssessment $assessment, string $name): ?string
    {
        $file = $this->filesByName($assessment)[$name] ?? null;
        if (!$file instanceof InputFile) {
            return null;
        }
        $store = ObjectStore::getInstance();
        if (!$store->exists($file->ocrResultKey())) {
            return null;
        }
        $decoded = json_decode($store->getContents($file->ocrResultKey()), true);
        if (!is_array($decoded)) {
            return null;
        }
        $text = $decoded['text'] ?? null;
        return is_string($text) ? $text : null;
    }

    /**
     * @return array<string, InputFile>
     */
    private function filesByName(BaseAssessment $assessment): array
    {
        $byName = [];
        foreach ($assessment->listFileModels() as $file) {
            if ($file instanceof InputFile && $file->id !== null) {
                $byName[$file->name] = $file;
            }
        }
        return $byName;
    }

    /**
     * @param list<string> $payloads
     * @return list<string>
     */
    private function fileIds(array $payloads): array
    {
        $ids = [];
        foreach ($payloads as $payload) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded) && is_string($decoded['file_id'] ?? null)) {
                $ids[] = $decoded['file_id'];
            }
        }
        return $ids;
    }

    private function taskFor(string $fileId): ?string
    {
        foreach ($this->queued as $payload) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded) && ($decoded['file_id'] ?? null) === $fileId) {
                $task = $decoded['task'] ?? null;
                return is_string($task) ? $task : null;
            }
        }
        return null;
    }
}
