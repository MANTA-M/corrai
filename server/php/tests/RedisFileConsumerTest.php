<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Exam as GenericExam;
use Corrai\Model\File;
use Corrai\Queue\RedisConsumer;
use Corrai\Subject\Dictation\Exam as DictationExam;
use Corrai\Subject\DictationFranceCM1\Exam as DictationFranceCM1Exam;
use Corrai\Subject\DictationFranceCM2\Exam as DictationFranceCM2Exam;
use Corrai\Subject\DictationFranceCM2\File as DictationFranceCM2File;
use Corrai\Subject\ExamFactory;
use Corrai\Subject\Math\Exam as MathExam;
use PHPUnit\Framework\TestCase;

class RedisFileConsumerTest extends TestCase
{
    public function testFactorySelectsDictationFranceCM1(): void
    {
        $class = ExamFactory::examClass('Dictation', 'fr', 'CM1');
        $this->assertSame(DictationFranceCM1Exam::class, $class);

        $exam = ExamFactory::fromAttributes(
            [
                'name' => 'Test',
                'subject' => 'Dictation',
                'country' => 'fr',
                'level' => 'CM1',
                'date' => '2026-01-15',
                'created_at' => '2026-01-15T00:00:00Z',
            ],
            'school1',
            'teacher1',
            'exam1'
        );

        $this->assertInstanceOf(DictationFranceCM1Exam::class, $exam);
        $this->assertSame('exam1', $exam->id);
        $this->assertSame('school1', $exam->school_id);
        $this->assertSame('teacher1', $exam->user_id);
        $this->assertSame('Dictation', $exam->subject);
        $this->assertSame('fr', $exam->country);
        $this->assertSame('CM1', $exam->level);
    }

    public function testFactoryFallsBackToBareSubjectThenGeneric(): void
    {
        $this->assertSame(DictationExam::class, ExamFactory::examClass('Dictation', '', ''));
        $this->assertSame(MathExam::class, ExamFactory::examClass('Math', '', ''));
        $this->assertSame(GenericExam::class, ExamFactory::examClass('UnknownSubject', '', ''));
    }

    public function testFileFromAttributesCopiesStatusAndIds(): void
    {
        $exam = ExamFactory::fromAttributes(
            [
                'name' => 'Test',
                'subject' => 'Math',
                'date' => '2026-01-15',
                'created_at' => '2026-01-15T00:00:00Z',
            ],
            'school1',
            'teacher1',
            'exam1'
        );

        $file = $exam->fileFromAttributes(
            [
                'name' => 'copy.png',
                'type' => 'submission',
                'student' => null,
                'status' => 'loaded',
                'content_type' => 'image/png',
                'size' => 42,
                'created' => 1700000000,
            ],
            'file1',
            '"etag-1"'
        );

        $this->assertInstanceOf(File::class, $file);
        $this->assertSame('file1', $file->id);
        $this->assertSame('school1', $file->school_id);
        $this->assertSame('teacher1', $file->user_id);
        $this->assertSame('exam1', $file->exam_id);
        $this->assertSame('loaded', $file->status);
        $this->assertSame('copy.png', $file->name);
        $this->assertSame('"etag-1"', $file->etag);
    }

    public function testFileFromAttributesUsesDictationFranceCM2File(): void
    {
        $exam = ExamFactory::fromAttributes(
            [
                'name' => 'Test',
                'subject' => 'Dictation',
                'country' => 'fr',
                'level' => 'CM2',
                'date' => '2026-01-15',
                'created_at' => '2026-01-15T00:00:00Z',
            ],
            'school1',
            'teacher1',
            'exam1'
        );

        $this->assertInstanceOf(DictationFranceCM2Exam::class, $exam);
        $this->assertSame(DictationFranceCM2File::class, $exam->fileClass());

        $file = $exam->fileFromAttributes(
            [
                'name' => 'copy.png',
                'type' => 'submission',
                'status' => 'loaded',
                'size' => 1,
                'created' => 1,
            ],
            'file1'
        );

        $this->assertInstanceOf(DictationFranceCM2File::class, $file);
    }

    public function testGenericExamResolvesDictationFranceCM2FileClass(): void
    {
        $exam = new GenericExam();
        $exam->subject = 'Dictation';
        $exam->country = 'fr';
        $exam->level = 'CM2';

        $this->assertSame(DictationFranceCM2File::class, $exam->fileClass());

        $file = $exam->fileFromAttributes(
            [
                'name' => 'copy.png',
                'type' => 'submission',
                'status' => 'ocr_done',
                'size' => 1,
                'created' => 1,
            ],
            'file1'
        );

        $this->assertInstanceOf(DictationFranceCM2File::class, $file);
    }

    public function testDispatchCallsOnLoadedWhenDefined(): void
    {
        $exam = new class extends GenericExam {
            public ?File $seen = null;

            public function on_loaded(File $file): void
            {
                $this->seen = $file;
            }
        };
        $exam->id = 'exam1';
        $exam->school_id = 'school1';
        $exam->user_id = 'teacher1';

        $file = $exam->fileFromAttributes(
            [
                'name' => 'a.txt',
                'status' => 'loaded',
                'size' => 1,
                'created' => 1,
            ],
            'file1'
        );

        RedisConsumer::dispatch($exam, $file);
        $this->assertSame($file, $exam->seen);
    }

    public function testDispatchCallsFileHandlerBeforeExam(): void
    {
        $exam = new class extends GenericExam {
            public bool $examCalled = false;

            public function on_ocr_done(File $file): void
            {
                $this->examCalled = true;
            }
        };
        $exam->id = 'exam1';
        $exam->school_id = 'school1';
        $exam->user_id = 'teacher1';

        $file = new class extends File {
            public bool $fileCalled = false;

            public function on_ocr_done(): void
            {
                $this->fileCalled = true;
            }
        };
        $file->id = 'file1';
        $file->school_id = 'school1';
        $file->user_id = 'teacher1';
        $file->exam_id = 'exam1';
        $file->name = 'a.txt';
        $file->status = 'ocr_done';
        $file->size = 1;
        $file->created = 1;

        RedisConsumer::dispatch($exam, $file);
        $this->assertTrue($file->fileCalled);
        $this->assertFalse($exam->examCalled);
    }

    public function testDispatchLogsWhenMethodMissing(): void
    {
        $exam = new GenericExam();
        $exam->id = 'exam1';
        $exam->school_id = 'school1';
        $exam->user_id = 'teacher1';

        $file = $exam->fileFromAttributes(
            [
                'name' => 'a.txt',
                'status' => 'loaded',
                'size' => 1,
                'created' => 1,
            ],
            'file1'
        );

        $log = tempnam(sys_get_temp_dir(), 'redis_consumer_log_');
        $this->assertNotFalse($log);
        $previous = ini_set('error_log', $log);
        try {
            RedisConsumer::dispatch($exam, $file);
        } finally {
            if ($previous === false) {
                ini_restore('error_log');
            } else {
                ini_set('error_log', $previous);
            }
        }

        $contents = (string) file_get_contents($log);
        @unlink($log);
        $this->assertStringContainsString('No method on_loaded', $contents);
        $this->assertStringContainsString(GenericExam::class, $contents);
        $this->assertStringContainsString('file1', $contents);
        $this->assertStringContainsString('status=loaded', $contents);
    }
}
