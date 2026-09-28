<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Exam;
use Corrai\Subject\Dictation\Pipeline as Dictation;
use Corrai\Subject\DictationFranceCM2\Pipeline as DictationFranceCM2;
use PHPUnit\Framework\TestCase;

class ExamOptionalAttributesTest extends TestCase
{
    public function testBlankCountryAndLevelBecomeNull(): void
    {
        $exam = Exam::from_array([
            'name' => 'Dictation',
            'subject' => 'Dictation',
            'country' => null,
            'level' => '  ',
            'date' => '2026-01-01',
        ]);

        $this->assertNull($exam->country);
        $this->assertNull($exam->level);

        $output = $exam->to_output();
        $this->assertNull($output['country']);
        $this->assertNull($output['level']);
        $this->assertSame(Dictation::class, $exam->pipelineClass());
    }

    public function testSpecifiedCountryAndLevelAreKept(): void
    {
        $exam = Exam::from_array([
            'subject' => 'Dictation',
            'country' => ' fr ',
            'level' => 'CM2',
        ]);

        $this->assertSame('fr', $exam->country);
        $this->assertSame('CM2', $exam->level);
        $this->assertSame('fr', $exam->to_output()['country']);
        $this->assertSame('CM2', $exam->to_output()['level']);
        $this->assertSame(DictationFranceCM2::class, $exam->pipelineClass());
    }
}
