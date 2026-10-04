<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Assessment;
use Corrai\Model\School;
use Corrai\Model\User;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\SubjectIntake;
use Corrai\Subject\SubjectPageReader;
use Corrai\Utils\WSException;
use PHPUnit\Framework\TestCase;
use Redis;

class SubjectIntakeTest extends TestCase
{
    private static School $indSchool;
    private User $user;

    public static function setUpBeforeClass(): void
    {
        self::$indSchool = School::ensureIndependent();
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
        $reader = new class implements SubjectPageReader {
            public bool $sawPage = false;

            public function read(string $pagePath, string $pageName, array $tree): array
            {
                $this->sawPage = is_file($pagePath) && $pageName === 'page.jpg' && $tree !== [];
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
            $path,
            'fractions.png',
            'image/png',
            'fr',
            $reader
        );
        @unlink($path);

        $this->assertTrue($reader->sawPage);
        $loaded = Assessment::from_hash((string) $assessment->id);
        $this->assertSame('[Test] Contrôle de fractions', $loaded->name);
        $this->assertSame('Math', $loaded->subject);
        $this->assertSame('cm2', $loaded->level);
        $this->assertSame('2026-04-02', $loaded->date);

        $files = $loaded->list_files();
        $this->assertCount(1, $files);
        $this->assertSame('fractions.png', $files[0]['name']);
        $this->assertSame('subject', $files[0]['type']);
    }

    public function testTextFileIsAnalyzedFromItsContents(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'intake_txt_');
        $this->assertNotFalse($path);
        file_put_contents($path, "Dictée\nCM1\n12 mars 2026");

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
            $path,
            'dictee.txt',
            'text/plain',
            'fr',
            $reader
        );
        @unlink($path);

        $this->assertTrue($reader->sawText);
        $loaded = Assessment::from_hash((string) $assessment->id);
        $this->assertSame('[Test] Dictée', $loaded->name);
        $files = $loaded->list_files();
        $this->assertSame('dictee.txt', $files[0]['name']);
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
            SubjectIntake::create($this->user, $path, 'sujet.png', 'image/png', 'fr', $reader);
            $this->fail('Expected the reader failure to surface');
        } catch (WSException $exception) {
            $this->assertSame(502, $exception->getCode());
        } finally {
            @unlink($path);
        }

        $this->assertSame([], $this->user->assessments());
    }

    private function pngFile(): string
    {
        $image = imagecreatetruecolor(8, 8);
        $path = tempnam(sys_get_temp_dir(), 'intake_');
        $this->assertNotFalse($path);
        imagepng($image, $path);
        imagedestroy($image);
        return $path;
    }
}
