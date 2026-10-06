<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\SubmissionFile;
use Corrai\Stream\AssessmentEventFeed;
use PHPUnit\Framework\TestCase;

class AssessmentEventFeedTest extends TestCase
{
    public function testChangedFileSendsOnlyThePipelineFields(): void
    {
        $file = new SubmissionFile();
        $file->id = 'f1';
        $file->name = 'copie.png';
        $file->size = 1375109;
        $file->status = 'stored';
        $file->type = 'submission';
        $file->student = null;

        $payload = AssessmentEventFeed::changedFile($file, null);

        $this->assertSame(
            ['id', 'status', 'status_label', 'type', 'student', 'student_name'],
            array_keys($payload)
        );
        $this->assertSame('f1', $payload['id']);
        $this->assertSame('stored', $payload['status']);
        $this->assertSame('Stocké', $payload['status_label']);
        $this->assertArrayNotHasKey('menu', $payload);
        $this->assertArrayNotHasKey('appreciation', $payload);
        $this->assertArrayNotHasKey('size', $payload);
    }

    public function testDeltaOmitsUnchangedMarksAndStats(): void
    {
        $before = [
            'file' => [
                'id' => 'f1',
                'status' => 'stored',
                'status_label' => 'Stocké',
                'type' => 'submission',
                'student' => null,
                'student_name' => null,
            ],
            'student' => null,
            'stats' => [
                'assessed_students_number' => 7,
                'mark_average' => 10.57,
                'mark_min' => 2,
                'mark_max' => 18,
            ],
        ];
        $after = $before;
        $after['file']['status'] = 'ocr_done';
        $after['file']['status_label'] = 'OCR terminé';

        $payload = AssessmentEventFeed::delta('assessment', $before, $after);

        $this->assertSame([
            'scope' => 'assessment',
            'file' => [
                'id' => 'f1',
                'status' => 'ocr_done',
                'status_label' => 'OCR terminé',
            ],
        ], $payload);
    }

    public function testDeltaIncludesStatsOnlyWhenTheyChange(): void
    {
        $before = [
            'file' => [
                'id' => 'f1',
                'status' => 'ocr_done',
                'status_label' => 'OCR terminé',
                'type' => 'submission',
                'student' => 'stu-1',
                'student_name' => 'Ada',
            ],
            'student' => [
                'id' => 'stu-1',
                'name' => 'Ada',
                'status' => '',
                'mark' => 12,
                'appreciation' => 'Bien',
            ],
            'stats' => [
                'assessed_students_number' => 7,
                'mark_average' => 10.57,
                'mark_min' => 2,
                'mark_max' => 18,
            ],
        ];
        $after = $before;
        $after['student']['mark'] = 14;
        $after['stats']['mark_average'] = 11.2;
        $after['stats']['mark_max'] = 18;

        $payload = AssessmentEventFeed::delta('assessment', $before, $after);

        $this->assertSame(14, $payload['student']['mark']);
        $this->assertArrayNotHasKey('name', $payload['student']);
        $this->assertArrayNotHasKey('appreciation', $payload['student']);
        $this->assertSame(11.2, $payload['mark_average']);
        $this->assertArrayNotHasKey('mark_min', $payload);
        $this->assertArrayNotHasKey('mark_max', $payload);
        $this->assertArrayNotHasKey('assessed_students_number', $payload);
    }

    public function testChangedFileIncludesLoadingWhenProvided(): void
    {
        $file = new SubmissionFile();
        $file->id = 'f1';
        $file->status = 'stored';
        $file->type = 'submission';

        $payloadTrue = AssessmentEventFeed::changedFile($file, null, true);
        $this->assertTrue($payloadTrue['loading']);

        $payloadFalse = AssessmentEventFeed::changedFile($file, null, false);
        $this->assertFalse($payloadFalse['loading']);

        $file->loading = true;
        $payloadFromProp = AssessmentEventFeed::changedFile($file, null);
        $this->assertTrue($payloadFromProp['loading']);
    }

    public function testDeltaIncludesLoadingTrueAtTaskStart(): void
    {
        $before = [
            'file' => [
                'id' => 'f1',
                'status' => 'ocr_done',
                'status_label' => 'OCR terminé',
                'type' => 'submission',
                'student' => null,
                'student_name' => null,
                'loading' => false,
            ],
            'student' => null,
            'stats' => [],
        ];
        $start = $before;
        $start['file']['loading'] = true;

        $payload = AssessmentEventFeed::delta('assessment', $before, $start);

        $this->assertSame([
            'scope' => 'assessment',
            'file' => [
                'id' => 'f1',
                'loading' => true,
            ],
        ], $payload);
    }

    public function testDeltaIncludesLoadingFalseAtTaskEnd(): void
    {
        $start = [
            'file' => [
                'id' => 'f1',
                'status' => 'ocr_done',
                'status_label' => 'OCR terminé',
                'type' => 'submission',
                'student' => null,
                'student_name' => null,
                'loading' => true,
            ],
            'student' => null,
            'stats' => [],
        ];
        $end = $start;
        $end['file']['status'] = 'errors_found';
        $end['file']['status_label'] = 'Erreurs trouvées';
        $end['file']['loading'] = false;

        $payload = AssessmentEventFeed::delta('assessment', $start, $end);

        $this->assertSame([
            'scope' => 'assessment',
            'file' => [
                'id' => 'f1',
                'status' => 'errors_found',
                'status_label' => 'Erreurs trouvées',
                'loading' => false,
            ],
        ], $payload);
    }
}
