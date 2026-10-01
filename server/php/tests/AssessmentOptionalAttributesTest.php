<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Assessment;
use Corrai\Subject\Dictation\Pipeline as Dictation;
use Corrai\Subject\DictationFranceCM2\Pipeline as DictationFranceCM2;
use PHPUnit\Framework\TestCase;

class AssessmentOptionalAttributesTest extends TestCase
{
    public function testBlankCountryAndLevelBecomeNull(): void
    {
        $assessment = Assessment::from_array([
            'name' => 'Dictation',
            'subject' => 'Dictation',
            'country' => null,
            'level' => '  ',
            'date' => '2026-01-01',
        ]);

        $this->assertNull($assessment->country);
        $this->assertNull($assessment->level);

        $output = $assessment->to_output();
        $this->assertNull($output['country']);
        $this->assertNull($output['level']);
        $this->assertSame(Dictation::class, $assessment->pipelineClass());
    }

    public function testSpecifiedCountryAndLevelAreKept(): void
    {
        $assessment = Assessment::from_array([
            'subject' => 'Dictation',
            'country' => ' fr ',
            'level' => 'CM2',
        ]);

        $this->assertSame('fr', $assessment->country);
        $this->assertSame('CM2', $assessment->level);
        $this->assertSame('fr', $assessment->to_output()['country']);
        $this->assertSame('CM2', $assessment->to_output()['level']);
        $this->assertSame(DictationFranceCM2::class, $assessment->pipelineClass());
    }
}
