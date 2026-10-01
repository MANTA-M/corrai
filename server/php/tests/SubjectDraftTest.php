<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Subject\SubjectDraft;
use PHPUnit\Framework\TestCase;

class SubjectDraftTest extends TestCase
{
    public function testDictationLevelSelectsTheFrenchPipelineCode(): void
    {
        $attributes = SubjectDraft::normalize(
            [
                'name' => 'Dictée n°3',
                'subject' => 'dictation',
                'level' => 'cm2',
                'date' => '2026-03-12',
            ],
            'fr',
            'scan.pdf'
        );

        $this->assertSame('Dictée n°3', $attributes['name']);
        $this->assertSame('Dictation', $attributes['subject']);
        $this->assertSame('CM2', $attributes['level']);
        $this->assertSame('fr', $attributes['country']);
        $this->assertSame('2026-03-12', $attributes['date']);
    }

    public function testUnknownSubjectFallsBackAndDropsABadDate(): void
    {
        $attributes = SubjectDraft::normalize(
            [
                'name' => '',
                'subject' => 'Cuisine',
                'level' => 'this is a sentence that is far too long to be a level code really',
                'date' => '12/03/2026',
            ],
            'fr',
            'controle-maths.pdf'
        );

        $this->assertSame('controle-maths', $attributes['name']);
        $this->assertSame('Other', $attributes['subject']);
        $this->assertNull($attributes['level']);
        $this->assertSame('fr', $attributes['country']);
        $this->assertSame('', $attributes['date']);
    }

    public function testEducationLevelIsKeptWhenTheSubjectHasNoCatalogLevel(): void
    {
        $attributes = SubjectDraft::normalize(
            [
                'name' => 'Contrôle fractions',
                'subject' => 'Math',
                'level' => 'cm1',
                'date' => '2026-02-31',
            ],
            'fr',
            'fractions.png'
        );

        $this->assertSame('Math', $attributes['subject']);
        $this->assertSame('cm1', $attributes['level']);
        $this->assertSame('fr', $attributes['country']);
        $this->assertSame('', $attributes['date']);
    }
}
