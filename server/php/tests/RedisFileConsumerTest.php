<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Assessment as GenericAssessment;
use Corrai\Model\SubmissionFile;
use Corrai\Queue\RedisConsumer;
use Corrai\Subject\Dictation\Assessment as DictationAssessment;
use Corrai\Subject\DictationFranceCM2\Assessment as DictationFranceCM2Assessment;
use Corrai\Subject\DictationFranceCM2\File as DictationFranceCM2File;
use Corrai\Subject\DictationFranceOCRGoogle\Assessment as DictationFranceOCRGoogleAssessment;
use Corrai\Subject\AssessmentFactory;
use Corrai\Subject\Math\Assessment as MathAssessment;
use PHPUnit\Framework\TestCase;

class RedisFileConsumerTest extends TestCase
{
    public function testFactorySelectsDictationFranceOCRGoogle(): void
    {
        $class = AssessmentFactory::assessmentClass('Dictation', 'fr', 'OCRGoogle');
        $this->assertSame(DictationFranceOCRGoogleAssessment::class, $class);
        $this->assertSame(
            DictationFranceCM2Assessment::class,
            AssessmentFactory::assessmentClass('Dictation', 'FR', 'cm2')
        );

        $assessment = AssessmentFactory::fromAttributes(
            [
                'name' => 'Test',
                'subject' => 'Dictation',
                'country' => 'fr',
                'level' => 'OCRGoogle',
                'date' => '2026-01-15',
                'created_at' => '2026-01-15T00:00:00Z',
            ],
            'school1',
            'teacher1',
            'assessment1'
        );

        $this->assertInstanceOf(DictationFranceOCRGoogleAssessment::class, $assessment);
        $this->assertSame('assessment1', $assessment->id);
        $this->assertSame('school1', $assessment->school_id);
        $this->assertSame('teacher1', $assessment->user_id);
        $this->assertSame('Dictation', $assessment->subject);
        $this->assertSame('fr', $assessment->country);
        $this->assertSame('OCRGoogle', $assessment->level);
    }

    public function testFactoryFallsBackToBareSubjectThenGeneric(): void
    {
        $this->assertSame(DictationAssessment::class, AssessmentFactory::assessmentClass('Dictation', '', ''));
        $this->assertSame(MathAssessment::class, AssessmentFactory::assessmentClass('Math', '', ''));
        $this->assertSame(GenericAssessment::class, AssessmentFactory::assessmentClass('UnknownSubject', '', ''));
    }

    public function testFileFromAttributesCopiesStatusAndIds(): void
    {
        $assessment = AssessmentFactory::fromAttributes(
            [
                'name' => 'Test',
                'subject' => 'Math',
                'date' => '2026-01-15',
                'created_at' => '2026-01-15T00:00:00Z',
            ],
            'school1',
            'teacher1',
            'assessment1'
        );

        $file = $assessment->fileFromAttributes(
            [
                'name' => 'copy.png',
                'type' => 'submission',
                'student' => null,
                'status' => 'stored',
                'content_type' => 'image/png',
                'size' => 42,
                'created' => 1700000000,
            ],
            'file1',
            '"etag-1"'
        );

        $this->assertInstanceOf(SubmissionFile::class, $file);
        $this->assertSame('file1', $file->id);
        $this->assertSame('school1', $file->school_id);
        $this->assertSame('teacher1', $file->user_id);
        $this->assertSame('assessment1', $file->assessment_id);
        $this->assertSame('stored', $file->status);
        $this->assertSame('copy.png', $file->name);
        $this->assertSame('"etag-1"', $file->etag);
    }

    public function testLegacyLoadedStatusIsReadAsStored(): void
    {
        $assessment = AssessmentFactory::fromAttributes(
            [
                'name' => 'Test',
                'subject' => 'Math',
                'date' => '2026-01-15',
                'created_at' => '2026-01-15T00:00:00Z',
            ],
            'school1',
            'teacher1',
            'assessment1'
        );

        $file = $assessment->fileFromAttributes(
            [
                'name' => 'copy.png',
                'type' => 'submission',
                'status' => 'loaded',
                'size' => 1,
                'created' => 1,
            ],
            'file1'
        );

        $this->assertSame('stored', $file->status);
    }

    public function testFileFromAttributesUsesDictationFranceCM2File(): void
    {
        $assessment = AssessmentFactory::fromAttributes(
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
            'assessment1'
        );

        $this->assertInstanceOf(DictationFranceCM2Assessment::class, $assessment);
        $this->assertSame(DictationFranceCM2File::class, $assessment->fileClass());

        $file = $assessment->fileFromAttributes(
            [
                'name' => 'copy.png',
                'type' => 'submission',
                'status' => 'stored',
                'size' => 1,
                'created' => 1,
            ],
            'file1'
        );

        $this->assertInstanceOf(DictationFranceCM2File::class, $file);
    }

    public function testGenericAssessmentResolvesDictationFranceCM2FileClass(): void
    {
        $assessment = new GenericAssessment();
        $assessment->subject = 'Dictation';
        $assessment->country = 'fr';
        $assessment->level = 'CM2';

        $this->assertSame(DictationFranceCM2File::class, $assessment->fileClass());

        $file = $assessment->fileFromAttributes(
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

    public function testHandleTicketLogsAndReturnsWhenTheTicketHasNoTarget(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'redis_consumer_log_');
        $this->assertNotFalse($log);
        $previous = ini_set('error_log', $log);
        try {
            RedisConsumer::handleTicket(['task' => 'Corrai\\Subject\\DictationFranceCM2\\Task2Annotating']);
        } finally {
            if ($previous === false) {
                ini_restore('error_log');
            } else {
                ini_set('error_log', $previous);
            }
        }

        $contents = (string) file_get_contents($log);
        @unlink($log);
        $this->assertStringContainsString('Missing path or task on ticket', $contents);
    }

    public function testDispatchCallsOnStoredWhenDefined(): void
    {
        $assessment = new class extends GenericAssessment {
            public bool $assessmentCalled = false;

            public function on_stored(SubmissionFile $file): void
            {
                $this->assessmentCalled = true;
            }
        };
        $assessment->id = 'assessment1';
        $assessment->school_id = 'school1';
        $assessment->user_id = 'teacher1';

        $file = new class extends SubmissionFile {
            public bool $called = false;

            public function on_stored(): void
            {
                $this->called = true;
            }
        };
        $file->id = 'file1';
        $file->status = 'stored';

        RedisConsumer::dispatch($assessment, $file);
        $this->assertTrue($file->called);
        $this->assertFalse($assessment->assessmentCalled);
    }

    public function testDispatchCallsFileHandlerBeforeAssessment(): void
    {
        $assessment = new class extends GenericAssessment {
            public bool $assessmentCalled = false;

            public function on_ocr_done(SubmissionFile $file): void
            {
                $this->assessmentCalled = true;
            }
        };
        $assessment->id = 'assessment1';
        $assessment->school_id = 'school1';
        $assessment->user_id = 'teacher1';

        $file = new class extends SubmissionFile {
            public bool $fileCalled = false;

            public function on_ocr_done(): void
            {
                $this->fileCalled = true;
            }
        };
        $file->id = 'file1';
        $file->school_id = 'school1';
        $file->user_id = 'teacher1';
        $file->assessment_id = 'assessment1';
        $file->name = 'a.txt';
        $file->status = 'ocr_done';
        $file->size = 1;
        $file->created = 1;

        RedisConsumer::dispatch($assessment, $file);
        $this->assertTrue($file->fileCalled);
        $this->assertFalse($assessment->assessmentCalled);
    }

    public function testDispatchLogsWhenMethodMissing(): void
    {
        $assessment = new GenericAssessment();
        $assessment->id = 'assessment1';
        $assessment->school_id = 'school1';
        $assessment->user_id = 'teacher1';

        $file = $assessment->fileFromAttributes(
            [
                'name' => 'a.txt',
                'status' => 'missing_step',
                'size' => 1,
                'created' => 1,
            ],
            'file1'
        );

        $log = tempnam(sys_get_temp_dir(), 'redis_consumer_log_');
        $this->assertNotFalse($log);
        $previous = ini_set('error_log', $log);
        try {
            RedisConsumer::dispatch($assessment, $file);
        } finally {
            if ($previous === false) {
                ini_restore('error_log');
            } else {
                ini_set('error_log', $previous);
            }
        }

        $contents = (string) file_get_contents($log);
        @unlink($log);
        $this->assertStringContainsString('No method on_missing_step', $contents);
        $this->assertStringContainsString(GenericAssessment::class, $contents);
        $this->assertStringContainsString('file1', $contents);
        $this->assertStringContainsString('status=missing_step', $contents);
    }

    public function testStoredClassNamesTheConcreteAssessment(): void
    {
        $generic = new GenericAssessment();
        $generic->subject = 'Dictation';
        $generic->country = 'fr';
        $generic->level = 'CM2';
        $generic->name = 'Dictée';
        $generic->date = '2026-03-12';

        $this->assertSame(DictationFranceCM2Assessment::class, $generic->storedClass());
        $this->assertSame(DictationFranceCM2Assessment::class, $generic->attributePayload()['class']);

        $concrete = new DictationFranceCM2Assessment();
        $this->assertSame(DictationFranceCM2Assessment::class, $concrete->storedClass());
    }

    public function testFactoryPrefersTheStoredAssessmentClass(): void
    {
        $assessment = AssessmentFactory::fromAttributes(
            [
                'name' => 'Test',
                'subject' => 'Math',
                'class' => DictationFranceCM2Assessment::class,
            ],
            'school1',
            'teacher1',
            'assessment1'
        );

        $this->assertInstanceOf(DictationFranceCM2Assessment::class, $assessment);
        $this->assertSame('Math', $assessment->subject);
    }

    public function testFactoryIgnoresAnUnknownStoredClass(): void
    {
        $assessment = AssessmentFactory::fromAttributes(
            [
                'name' => 'Test',
                'subject' => 'Math',
                'class' => \Corrai\Model\User::class,
            ],
            'school1',
            'teacher1',
            'assessment1'
        );

        $this->assertInstanceOf(MathAssessment::class, $assessment);
    }

    public function testFileAttributePayloadAndReadUseTheConcreteFileClass(): void
    {
        $file = new DictationFranceCM2File();
        $file->name = 'copy.png';
        $file->type = 'submission';
        $file->status = 'stored';
        $file->size = 4;
        $file->created = 10;

        $this->assertSame(DictationFranceCM2File::class, $file->attributePayload()['class']);

        $loaded = DictationFranceCM2File::from_array($file->attributePayload());
        $this->assertInstanceOf(DictationFranceCM2File::class, $loaded);
        $this->assertTrue($loaded->hasStoredClass);

        $assessment = new MathAssessment();
        $fromAttributes = $assessment->fileFromAttributes(
            $file->attributePayload(),
            'file1'
        );
        $this->assertInstanceOf(DictationFranceCM2File::class, $fromAttributes);

        $promoted = (new SubmissionFile())->asClass(DictationFranceCM2File::class);
        $this->assertInstanceOf(DictationFranceCM2File::class, $promoted);
        $this->assertSame(DictationFranceCM2File::class, $promoted->storedClass());
    }
}
