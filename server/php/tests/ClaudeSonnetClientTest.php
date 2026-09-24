<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\LlmClient\ClaudeSonnetClient;
use PHPUnit\Framework\TestCase;

class ClaudeSonnetClientTest extends TestCase
{
    public function testTextFileIsAddedAsTextContent(): void
    {
        $path = $this->temporaryFile('Correction text');

        try {
            $client = new TestableClaudeSonnetClient();
            $client->add_file($path, 'corrigé_dictée.txt');

            $content = $client->userContent();
            $this->assertSame([
                'type' => 'text',
                'text' => "corrigé_dictée.txt:\nCorrection text",
            ], $content[0]);
        } finally {
            @unlink($path);
        }
    }

    public function testPdfKeepsPdfMimeType(): void
    {
        $path = $this->temporaryFile('%PDF-1.4');

        try {
            $client = new TestableClaudeSonnetClient();
            $client->add_file($path, 'corrigé.pdf');

            $content = $client->userContent();
            $this->assertSame('file', $content[0]['type']);
            $this->assertStringStartsWith(
                'data:application/pdf;base64,',
                $content[0]['file']['file_data']
            );
        } finally {
            @unlink($path);
        }
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'corrai_test_');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);
        return $path;
    }
}

class TestableClaudeSonnetClient extends ClaudeSonnetClient
{
    public function userContent(): array
    {
        foreach ($this->payload['messages'] as $message) {
            if ($message['role'] === 'user') {
                return $message['content'];
            }
        }
        return [];
    }
}
