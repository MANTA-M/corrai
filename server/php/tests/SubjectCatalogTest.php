<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Subject\Catalog;
use PHPUnit\Framework\TestCase;

class SubjectCatalogTest extends TestCase
{
    public function testFrenchTreeUsesLocalizedNamesWithoutCountryOrLevel(): void
    {
        $tree = Catalog::tree('fr');
        $bySubject = [];
        foreach ($tree as $node) {
            $bySubject[$node['subject']] = $node;
        }

        $this->assertSame('Math', $bySubject['Math']['name']);
        $this->assertSame('Physique', $bySubject['Physics']['name']);
        $this->assertSame('Français (dictée)', $bySubject['Dictation']['name']);
        $this->assertSame('Anglais', $bySubject['English']['name']);
        $this->assertSame('Allemand', $bySubject['German']['name']);
        $this->assertSame('Espagnol', $bySubject['Spanish']['name']);
        $this->assertSame('Russe', $bySubject['Russian']['name']);
        $this->assertSame('Droit', $bySubject['Law']['name']);
        $this->assertSame('Autre', $bySubject['Other']['name']);

        $dictation = $bySubject['Dictation'];
        $this->assertSame([], $dictation['levels']);
        $this->assertCount(1, $dictation['countries']);
        $this->assertSame('fr', $dictation['countries'][0]['country']);
        $this->assertSame('France', $dictation['countries'][0]['name']);
        $this->assertSame(
            [
                ['level' => 'CM2', 'name' => 'Dictée CM2 France'],
                ['level' => 'OCRGoogle', 'name' => 'Dictée OCR Google France'],
            ],
            $dictation['countries'][0]['levels']
        );

        foreach (['Math', 'Physics', 'Law', 'Other', 'English', 'German', 'Spanish', 'Russian'] as $subject) {
            $this->assertSame([], $bySubject[$subject]['countries']);
            $this->assertSame([], $bySubject[$subject]['levels']);
        }
    }

    public function testUnknownLocaleFallsBackToEnglishName(): void
    {
        $tree = Catalog::tree('zz');
        $math = null;
        $dictation = null;
        foreach ($tree as $node) {
            if ($node['subject'] === 'Math') {
                $math = $node;
            }
            if ($node['subject'] === 'Dictation') {
                $dictation = $node;
            }
        }
        $this->assertNotNull($math);
        $this->assertSame('Mathematics', $math['name']);
        $this->assertNotNull($dictation);
        $this->assertSame('France', $dictation['countries'][0]['name']);
        $this->assertSame('Dictation CM2 France', $dictation['countries'][0]['levels'][0]['name']);
        $this->assertSame('Dictation OCR Google France', $dictation['countries'][0]['levels'][1]['name']);
    }
}
