<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\File;
use Corrai\Stream\AssessmentEventFeed;
use PHPUnit\Framework\TestCase;

class AssessmentEventFeedTest extends TestCase
{
    public function testAssessmentSnapshotKeepsUnassignedFilesStudentsAndMarks(): void
    {
        $payload = AssessmentEventFeed::assessmentPayload(
            [
                ['id' => 'subject', 'type' => 'subject', 'student' => null, 'status' => 'stored'],
                ['id' => 'copy', 'type' => 'submission', 'student' => '', 'status' => 'ocr_done'],
                ['id' => 'debug', 'type' => 'debug', 'student' => null, 'status' => 'stored'],
                ['id' => 'assigned', 'type' => 'submission', 'student' => 'stu-1', 'status' => 'corrected'],
            ],
            [
                ['id' => 'stu-1', 'name' => 'Ada', 'status' => 'graded', 'mark' => 14.5],
            ],
            [
                'assessed_students_number' => 1,
                'mark_average' => 14.5,
                'mark_min' => 14.5,
                'mark_max' => 14.5,
            ]
        );

        $this->assertSame('assessment', $payload['scope']);
        $this->assertSame(['copy', 'debug'], array_column($payload['files'], 'id'));
        $this->assertSame('ocr_done', $payload['files'][0]['status']);
        $this->assertSame('graded', $payload['students'][0]['status']);
        $this->assertSame(14.5, $payload['mark_average']);
        $this->assertSame(14.5, $payload['mark_min']);
        $this->assertSame(14.5, $payload['mark_max']);
        $this->assertSame(1, $payload['assessed_students_number']);
    }

    public function testStudentSnapshotKeepsThatStudentsFilesAndAttributes(): void
    {
        $payload = AssessmentEventFeed::studentPayload(
            [
                ['id' => 'copy', 'type' => 'submission', 'student' => 'stu-1', 'status' => 'corrected'],
                ['id' => 'other', 'type' => 'submission', 'student' => 'stu-2', 'status' => 'stored'],
                ['id' => 'loose', 'type' => 'submission', 'student' => '', 'status' => 'stored'],
            ],
            ['id' => 'stu-1', 'name' => 'Ada', 'status' => 'graded', 'mark' => 16, 'appreciation' => 'Bien'],
            'stu-1'
        );

        $this->assertSame('student', $payload['scope']);
        $this->assertSame('Ada', $payload['student']['name']);
        $this->assertSame('graded', $payload['student']['status']);
        $this->assertSame(16, $payload['student']['mark']);
        $this->assertSame('Bien', $payload['student']['appreciation']);
        $this->assertSame(['copy'], array_column($payload['files'], 'id'));
        $this->assertSame('corrected', $payload['files'][0]['status']);
    }

    public function testChangedFileSendsOnlyThePipelineFields(): void
    {
        $file = new File();
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

    public function testMissingStudentIsReportedWithoutFiles(): void
    {
        $payload = AssessmentEventFeed::studentPayload([], null, 'missing');

        $this->assertNull($payload['student']);
        $this->assertSame([], $payload['files']);
    }
}
