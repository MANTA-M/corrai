<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Utils\AssessmentsPathMigrator;
use PHPUnit\Framework\TestCase;

class AssessmentsPathMigratorTest extends TestCase
{
    public function testRewriteKeyReplacesTheExamsSegment(): void
    {
        $source = 'schools/s1/teachers/t1/exams/e1/files/f1/content';
        $this->assertSame(
            'schools/s1/teachers/t1/assessments/e1/files/f1/content',
            AssessmentsPathMigrator::rewriteKey($source)
        );
    }

    public function testRewriteJsonRenamesExamIdAndEmbeddedPaths(): void
    {
        $rewritten = AssessmentsPathMigrator::rewriteJson([
            'name' => 'Dictée',
            'exam_id' => 'abc1234',
            'nested' => [
                'exam_id' => 'abc1234',
                'path' => 'schools/s1/teachers/t1/exams/abc1234/files/f1/content',
            ],
        ]);

        $this->assertSame('abc1234', $rewritten['assessment_id']);
        $this->assertArrayNotHasKey('exam_id', $rewritten);
        $this->assertSame('abc1234', $rewritten['nested']['assessment_id']);
        $this->assertSame(
            'schools/s1/teachers/t1/assessments/abc1234/files/f1/content',
            $rewritten['nested']['path']
        );
    }
}
