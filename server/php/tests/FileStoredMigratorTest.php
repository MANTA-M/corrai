<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Utils\FileStoredMigrator;
use Corrai\Utils\MenuLabels;
use PHPUnit\Framework\TestCase;

class FileStoredMigratorTest extends TestCase
{
    public function testAttributeStatusRewritesLoadedOnly(): void
    {
        $this->assertSame(
            ['status' => 'stored', 'name' => 'copy.png'],
            FileStoredMigrator::attributeStatus(['status' => 'loaded', 'name' => 'copy.png'])
        );
        $this->assertNull(FileStoredMigrator::attributeStatus(['status' => 'stored']));
        $this->assertNull(FileStoredMigrator::attributeStatus(['status' => 'corrected']));
    }

    public function testEventNameRewritesLoadedOnly(): void
    {
        $this->assertSame(
            ['timestamp' => 10, 'name' => 'Stored'],
            FileStoredMigrator::eventName(['timestamp' => 10, 'name' => 'Loaded'])
        );
        $this->assertNull(FileStoredMigrator::eventName(['name' => 'Stored']));
        $this->assertNull(FileStoredMigrator::eventName(['name' => 'OCR queued']));
    }

    public function testEventKeysStayUnderTheEventsPrefix(): void
    {
        $this->assertTrue(FileStoredMigrator::isEventKey(
            'schools/s1/teachers/t1/assessments/a1/files/f1/events/1-ab.json'
        ));
        $this->assertFalse(FileStoredMigrator::isEventKey(
            'schools/s1/teachers/t1/assessments/a1/files/f1/attributes.json'
        ));
    }

    public function testStoredEventLabelFollowsTheLocale(): void
    {
        $this->assertSame('Stocké', MenuLabels::fileEventLabel('Loaded', 'fr'));
        $this->assertSame('Stored', MenuLabels::fileEventLabel('Stored', 'en'));
        $this->assertSame('OCR queued', MenuLabels::fileEventLabel('OCR queued', 'fr'));
    }
}
