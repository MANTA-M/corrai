<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\Assessment;
use Corrai\Model\BaseAssessment;
use Corrai\Subject\Dictation\Task1Correcting as Dictation;
use Corrai\Subject\DictationFranceCM2\Task1Correcting as DictationFranceCM2;
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

    public function testCorrectionLocaleIsStoredAsACodeAndNamedForTheLlm(): void
    {
        $assessment = Assessment::from_array([
            'subject' => 'Dictation',
            'correction_language' => 'en-US',
        ]);

        $this->assertSame('en', $assessment->correction_language);
        $this->assertSame('en', $assessment->attributePayload()['correction_language']);
        $this->assertSame('English', $assessment->correctionLanguageName());

        $missing = Assessment::from_array(['subject' => 'Dictation']);
        $this->assertSame('fr', $missing->correction_language);
        $this->assertSame('French', $missing->correctionLanguageName());
        $this->assertSame('French', BaseAssessment::LOCALE_NAMES['fr']);
    }
}
