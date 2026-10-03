<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Assessment as GenericAssessment;
use Corrai\Subject\DictationFranceCM2\Assessment as DictationFranceCM2Assessment;
use Corrai\Subject\DictationFranceCM2\File as DictationFranceCM2File;
use Corrai\Subject\Math\Assessment as MathAssessment;
use Corrai\Utils\ClassAttributeMigrator;
use PHPUnit\Framework\TestCase;

class ClassAttributeMigratorTest extends TestCase
{
    public function testAttributeKeysMatchAssessmentAndFileDocumentsOnly(): void
    {
        $this->assertTrue(ClassAttributeMigrator::isAssessmentAttributeKey(
            'schools/s1/teachers/t1/assessments/a1/attributes.json'
        ));
        $this->assertFalse(ClassAttributeMigrator::isAssessmentAttributeKey(
            'schools/s1/teachers/t1/attributes.json'
        ));
        $this->assertFalse(ClassAttributeMigrator::isFileAttributeKey(
            'schools/s1/teachers/t1/assessments/a1/attributes.json'
        ));
        $this->assertFalse(ClassAttributeMigrator::isFileAttributeKey(
            'schools/s1/teachers/t1/assessments/a1/students/st1/attributes.json'
        ));

        $this->assertTrue(ClassAttributeMigrator::isFileAttributeKey(
            'schools/s1/teachers/t1/assessments/a1/subject/f1/attributes.json'
        ));
        $this->assertTrue(ClassAttributeMigrator::isFileAttributeKey(
            'schools/s1/teachers/t1/assessments/a1/unclassified/f1/attributes.json'
        ));
        $this->assertTrue(ClassAttributeMigrator::isFileAttributeKey(
            'schools/s1/teachers/t1/assessments/a1/files/f1/attributes.json'
        ));
        $this->assertTrue(ClassAttributeMigrator::isFileAttributeKey(
            'schools/s1/teachers/t1/assessments/a1/students/st1/f1/attributes.json'
        ));
    }

    public function testAssessmentClassForFollowsSubjectCountryAndLevel(): void
    {
        $this->assertSame(
            DictationFranceCM2Assessment::class,
            ClassAttributeMigrator::assessmentClassFor([
                'subject' => 'Dictation',
                'country' => 'fr',
                'level' => 'cm2',
                'class' => MathAssessment::class,
            ])
        );
        $this->assertSame(
            GenericAssessment::class,
            ClassAttributeMigrator::assessmentClassFor([
                'subject' => 'History',
                'class' => \Corrai\Model\User::class,
            ])
        );
    }

    public function testFileClassForUsesTheAssessmentWhenTheDocumentHasNoClass(): void
    {
        $assessment = new DictationFranceCM2Assessment();
        $this->assertSame(
            DictationFranceCM2File::class,
            ClassAttributeMigrator::fileClassFor($assessment)
        );
    }
}
