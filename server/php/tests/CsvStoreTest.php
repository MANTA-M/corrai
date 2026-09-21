<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\CsvStore;
use PHPUnit\Framework\TestCase;

class CsvStoreTest extends TestCase
{
    public function testEncodeDecodeRowsRoundTrip(): void
    {
        $rows = [
            ['name' => 'scan.pdf', 'type' => 'submission', 'author' => 'Doe, Alice'],
            ['name' => 'subject.pdf', 'type' => 'subject', 'author' => ''],
        ];

        $csv = CsvStore::encodeRows($rows);
        $decoded = CsvStore::decodeRows($csv);

        $this->assertSame($rows, $decoded);
    }

    public function testEncodeDecodeRowsEmpty(): void
    {
        $this->assertSame('', CsvStore::encodeRows([]));
        $this->assertSame([], CsvStore::decodeRows(''));
        $this->assertSame([], CsvStore::decodeRows("   \n"));
    }
}
