<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Utils\ObjectStore;
use PHPUnit\Framework\TestCase;

class AssessmentFilePathTest extends TestCase
{
    public function testSubjectMaterialLivesUnderSubject(): void
    {
        $this->assertSame(
            'schools/s1/teachers/t1/assessments/a1/subject/f1/',
            ObjectStore::assessmentFilePrefix('s1', 't1', 'a1', 'f1', 'subject')
        );
        $this->assertSame(
            'schools/s1/teachers/t1/assessments/a1/subject/f1/content',
            ObjectStore::assessmentFileContentKey('s1', 't1', 'a1', 'f1', 'solution')
        );
        $this->assertSame(
            'schools/s1/teachers/t1/assessments/a1/subject/f1/attributes.json',
            ObjectStore::assessmentFileAttrKey('s1', 't1', 'a1', 'f1', 'instructions')
        );
    }

    public function testStudentCopyLivesUnderTheStudent(): void
    {
        $prefix = ObjectStore::assessmentFilePrefix('s1', 't1', 'a1', 'f1', 'submission', 'st1');
        $this->assertSame('schools/s1/teachers/t1/assessments/a1/students/st1/f1/', $prefix);
        $this->assertSame(
            'schools/s1/teachers/t1/assessments/a1/students/st1/f1/events/e1.json',
            ObjectStore::assessmentFileEventKey('s1', 't1', 'a1', 'f1', 'e1', 'submission', 'st1')
        );
    }

    public function testUnclassifiedFilesStayApartFromSubjectAndStudents(): void
    {
        $this->assertSame(
            'schools/s1/teachers/t1/assessments/a1/unclassified/f1/content',
            ObjectStore::assessmentFileContentKey('s1', 't1', 'a1', 'f1', '')
        );
        $this->assertSame(
            'schools/s1/teachers/t1/assessments/a1/unclassified/f1/content',
            ObjectStore::assessmentFileContentKey('s1', 't1', 'a1', 'f1', 'submission', null)
        );
    }

    public function testParseNodePrefixRecognizesTheThreeAreas(): void
    {
        $subject = ObjectStore::parseNodePrefix('schools/s1/teachers/t1/assessments/a1/subject/f1/');
        $this->assertSame('file', $subject['kind']);
        $this->assertSame('f1', $subject['file_id']);
        $this->assertSame('subject', $subject['area']);

        $student = ObjectStore::parseNodePrefix('schools/s1/teachers/t1/assessments/a1/students/st1/');
        $this->assertSame('student', $student['kind']);
        $this->assertSame('st1', $student['student_id']);

        $copy = ObjectStore::parseNodePrefix('schools/s1/teachers/t1/assessments/a1/students/st1/f1/');
        $this->assertSame('file', $copy['kind']);
        $this->assertSame('st1', $copy['student_id']);
        $this->assertSame('f1', $copy['file_id']);

        $pending = ObjectStore::parseNodePrefix('schools/s1/teachers/t1/assessments/a1/unclassified/f1/');
        $this->assertSame('file', $pending['kind']);
        $this->assertSame('unclassified', $pending['area']);
    }

    public function testParseNodePrefixStillReadsLegacyFiles(): void
    {
        $legacy = ObjectStore::parseNodePrefix('schools/s1/teachers/t1/assessments/a1/files/f1/');
        $this->assertSame('file', $legacy['kind']);
        $this->assertSame('f1', $legacy['file_id']);
    }
}
