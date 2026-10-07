<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Assessment;
use Corrai\Model\BaseAssessment;
use Corrai\Model\InputFile;
use Corrai\Model\School;
use Corrai\Model\User;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\SubjectImageOcr;
use Corrai\Subject\SubjectIntake;
use Corrai\Subject\SubjectPageOcr;
use Corrai\Subject\SubjectPageReader;
use Corrai\Subject\SubjectPages;
use Corrai\Task\TaskRotateAndCrop;
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
        $image = $this->filesByName($loaded)['fractions.png'];
        $this->assertSame(SubjectPages::STATUS_REFINE, $image->status);
        $this->assertSame('OCR refine', $image->get_status_label('fr'));
        $this->assertSame(
            TaskRotateAndCrop::class,
            $this->taskFor((string) $image->id)
        );
        $this->assertNull($this->compiledPages($loaded));
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
        $this->assertSame(["Dictée\nCM1\n12 mars 2026"], $this->compiledPages($loaded));
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
        $this->assertContains($byName['intro.png']->id, $queuedIds);
        $this->assertContains($byName['suite.png']->id, $queuedIds);
        $this->assertContains($byName['reste.png']->id, $queuedIds);
        $this->assertSame(SubjectPages::STATUS_REFINE, $byName['intro.png']->status);
        $this->assertSame(SubjectPages::STATUS_REFINE, $byName['suite.png']->status);
        $this->assertSame(SubjectPages::STATUS_ASKED, $byName['reste.png']->status);
        $this->assertSame('OCR demandé', $byName['reste.png']->get_status_label('fr'));
        $this->assertSame(TaskRotateAndCrop::class, $this->taskFor((string) $byName['intro.png']->id));
        $this->assertSame(TaskRotateAndCrop::class, $this->taskFor((string) $byName['suite.png']->id));
        $this->assertSame(SubjectPageOcr::class, $this->taskFor((string) $byName['reste.png']->id));
        $this->assertNull($this->compiledPages($loaded));
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
        $this->assertSame(TaskRotateAndCrop::class, $this->taskFor((string) $file->id));
    }

    public function testPdfSubjectIsNotQueuedForRotateAndCrop(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required');
        }
        $formats = array_map('strtoupper', (new \Imagick())->queryFormats('PDF'));
        if (!in_array('PDF', $formats, true)) {
            $this->markTestSkipped('Imagick has no PDF support');
        }

        $imagick = new \Imagick();
        $imagick->newImage(80, 80, new \ImagickPixel('white'));
        $imagick->setImageFormat('pdf');
        $path = tempnam(sys_get_temp_dir(), 'intake_pdf_');
        $this->assertNotFalse($path);
        file_put_contents($path, $imagick->getImageBlob());
        $imagick->clear();

        $ocr = $this->ocr('Page pdf');
        $reader = new class implements SubjectPageReader {
            public function read(string $pagePath, string $pageName, array $tree): array
            {
                return [
                    'name' => '[Test] PDF',
                    'subject' => 'Math',
                    'level' => 'cm2',
                    'date' => '2026-04-02',
                ];
            }
        };

        try {
            $assessment = SubjectIntake::create(
                $this->user,
                [['path' => $path, 'name' => 'sujet.pdf', 'contentType' => 'application/pdf']],
                'fr',
                $reader,
                $ocr
            );
        } finally {
            @unlink($path);
        }

        $loaded = Assessment::from_hash((string) $assessment->id);
        $file = $this->filesByName($loaded)['sujet.pdf'] ?? null;
        $this->assertInstanceOf(InputFile::class, $file);
        $this->assertSame(['page.jpg'], $ocr->names);
        $this->assertSame('Page pdf', $this->ocrText($loaded, 'sujet.pdf'));
        $this->assertSame(SubjectPages::STATUS_DONE, $file->status);
        $this->assertSame('OCR terminé', $file->get_status_label('fr'));
        $this->assertSame([], $this->fileIds($this->queued));
        $this->assertSame(['Page pdf'], $this->compiledPages($loaded));
    }

    public function testImageWithoutStraighteningIsOcrDoneAndCompiled(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $path = $this->pngFile();
        $ocr = new class implements SubjectImageOcr {
            public function recognize(string $path, string $filename): array
            {
                return [
                    'text' => 'Énoncé droit',
                    'bounding_boxes' => [],
                ];
            }
        };
        $reader = new class implements SubjectPageReader {
            public function read(string $pagePath, string $pageName, array $tree): array
            {
                return [
                    'name' => '[Test] Énoncé',
                    'subject' => 'Math',
                    'level' => 'cm2',
                    'date' => '2026-04-02',
                ];
            }
        };

        $assessment = SubjectIntake::create(
            $this->user,
            [['path' => $path, 'name' => 'enonce.png', 'contentType' => 'image/png']],
            'fr',
            $reader,
            $ocr
        );
        @unlink($path);

        $loaded = Assessment::from_hash((string) $assessment->id);
        $file = $this->filesByName($loaded)['enonce.png'];
        $this->assertSame(SubjectPages::STATUS_DONE, $file->status);
        $this->assertSame([], $this->fileIds($this->queued));
        $this->assertSame(['Énoncé droit'], $this->compiledPages($loaded));
    }

    public function testCompileWaitsUntilRotateAndCropReachesOcrDone(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $path = $this->pngFile();
        $reader = new class implements SubjectPageReader {
            public function read(string $pagePath, string $pageName, array $tree): array
            {
                return [
                    'name' => '[Test] Page',
                    'subject' => 'Math',
                    'level' => 'cm2',
                    'date' => '2026-04-02',
                ];
            }
        };

        $assessment = SubjectIntake::create(
            $this->user,
            [['path' => $path, 'name' => 'page.png', 'contentType' => 'image/png']],
            'fr',
            $reader,
            $this->ocr('Texte de la page')
        );
        @unlink($path);

        $loaded = Assessment::from_hash((string) $assessment->id);
        $file = $this->filesByName($loaded)['page.png'];
        $this->assertSame(SubjectPages::STATUS_REFINE, $file->status);
        $this->assertNull($this->compiledPages($loaded));

        $task = new TaskRotateAndCrop();
        $task->process_task((object) [
            'path' => $file->contentKey(),
            'task' => TaskRotateAndCrop::class,
            'task_id' => TaskRotateAndCrop::class,
            'file_id' => $file->id,
        ]);

        $done = InputFile::from_hash((string) $file->id);
        $this->assertSame(SubjectPages::STATUS_DONE, $done->status);
        $this->assertSame('OCR terminé', $done->get_status_label('fr'));
        $this->assertSame(['Texte de la page'], $this->compiledPages($loaded));
    }

    public function testQueuedSubjectImageMovesFromOcrRequestedToOcrDone(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $intro = $this->pngFile();
        $suite = $this->pngFile();
        $reader = new class implements SubjectPageReader {
            public function read(string $pagePath, string $pageName, array $tree): array
            {
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
        $ocr = new class implements SubjectImageOcr {
            public function recognize(string $path, string $filename): array
            {
                $text = str_contains($filename, 'intro') ? "Dictée\nCM2" : 'Page suivante';
                return [
                    'text' => $text,
                    'bounding_boxes' => [],
                ];
            }
        };

        $assessment = SubjectIntake::create(
            $this->user,
            [
                ['path' => $intro, 'name' => 'intro.png', 'contentType' => 'image/png'],
                ['path' => $suite, 'name' => 'suite.png', 'contentType' => 'image/png'],
            ],
            'fr',
            $reader,
            $ocr
        );
        @unlink($intro);
        @unlink($suite);

        $loaded = Assessment::from_hash((string) $assessment->id);
        $waiting = $this->filesByName($loaded)['suite.png'];
        $this->assertSame(SubjectPages::STATUS_ASKED, $waiting->status);
        $this->assertSame(SubjectPageOcr::class, $this->taskFor((string) $waiting->id));
        $this->assertNull($this->compiledPages($loaded));

        $task = new SubjectPageOcr($ocr);
        $task->process_task((object) [
            'path' => $waiting->contentKey(),
            'task' => SubjectPageOcr::class,
            'task_id' => SubjectPageOcr::class,
            'file_id' => $waiting->id,
        ]);

        $done = InputFile::from_hash((string) $waiting->id);
        $this->assertSame(SubjectPages::STATUS_DONE, $done->status);
        $this->assertSame('OCR terminé', $done->get_status_label('fr'));
        $this->assertSame(["Dictée\nCM2", 'Page suivante'], $this->compiledPages($loaded));
    }

    public function testCompiledPagesFollowCreationDate(): void
    {
        $first = tempnam(sys_get_temp_dir(), 'intake_txt_');
        $second = tempnam(sys_get_temp_dir(), 'intake_txt_');
        $this->assertNotFalse($first);
        $this->assertNotFalse($second);
        file_put_contents($first, 'Page une');
        file_put_contents($second, 'Page deux');

        $reader = new class implements SubjectPageReader {
            public function read(string $pagePath, string $pageName, array $tree): array
            {
                return [
                    'name' => '[Test] Texte',
                    'subject' => 'Math',
                    'level' => 'cm2',
                    'date' => '2026-04-02',
                ];
            }
        };

        $assessment = SubjectIntake::create(
            $this->user,
            [
                ['path' => $first, 'name' => 'b-deuxieme.txt', 'contentType' => 'text/plain'],
                ['path' => $second, 'name' => 'a-premiere.txt', 'contentType' => 'text/plain'],
            ],
            'fr',
            $reader,
            $this->ocr('unused')
        );
        @unlink($first);
        @unlink($second);

        $loaded = Assessment::from_hash((string) $assessment->id);
        $byName = $this->filesByName($loaded);
        $byName['b-deuxieme.txt']->created = 200;
        $byName['b-deuxieme.txt']->saveAttributes();
        $byName['a-premiere.txt']->created = 100;
        $byName['a-premiere.txt']->saveAttributes();
        SubjectPages::compileIfReady($loaded);

        $this->assertSame(['Page deux', 'Page une'], $this->compiledPages($loaded));
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

    /**
     * @return list<string>|null
     */
    private function compiledPages(BaseAssessment $assessment): ?array
    {
        $key = ObjectStore::assessmentSubjectCompileKey(
            $assessment->school_id,
            $assessment->user_id,
            (string) $assessment->id
        );
        $store = ObjectStore::getInstance();
        if (!$store->exists($key)) {
            return null;
        }
        $decoded = json_decode($store->getContents($key), true);
        if (!is_array($decoded) || !is_array($decoded['pages'] ?? null)) {
            return null;
        }
        $pages = [];
        foreach ($decoded['pages'] as $page) {
            $pages[] = is_string($page) ? $page : '';
        }
        return $pages;
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
