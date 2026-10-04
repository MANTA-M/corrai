<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Llm\MistralOcrClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MistralOcrClientTest extends TestCase
{
    public function testPdfUsesDocumentUrlChunk(): void
    {
        $path = $this->temporaryFile('%PDF-1.4');

        try {
            $client = new TestableMistralOcrClient();
            $client->set_file($path, 'scan.pdf');

            $this->assertSame('document_url', $client->document()['type']);
            $this->assertSame('scan.pdf', $client->document()['document_name']);
            $this->assertStringStartsWith(
                'data:application/pdf;base64,',
                $client->document()['document_url']
            );
            $this->assertSame('mistral-ocr-latest', $client->payloadSnapshot()['model']);
            $this->assertArrayNotHasKey('include_blocks', $client->payloadSnapshot());
        } finally {
            @unlink($path);
        }
    }

    public function testImageUsesImageUrlChunk(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $path = $this->temporaryPng(400, 300);

        try {
            $client = new TestableMistralOcrClient();
            $client->set_file($path, 'scan.png');

            $document = $client->document();
            $this->assertSame('image_url', $document['type']);
            $this->assertMatchesRegularExpression('#^data:image/png;base64,#', $document['image_url']);
        } finally {
            @unlink($path);
        }
    }

    public function testFileIdUsesFileChunk(): void
    {
        $client = new TestableMistralOcrClient();
        $client->set_file_id('550e8400-e29b-41d4-a716-446655440000');

        $this->assertSame([
            'type' => 'file',
            'file_id' => '550e8400-e29b-41d4-a716-446655440000',
        ], $client->document());
    }

    public function testOptionalFieldsAreSetOnThePayload(): void
    {
        $client = new TestableMistralOcrClient('mistral-ocr-2505');
        $client->set_document_url('https://example.com/scan.pdf', 'scan.pdf');
        $client->set_image_url('https://example.com/page.png');
        $client->set_pages([0, 2]);
        $client->set_include_image_base64(true);
        $client->set_include_blocks(false);
        $client->set_extract_header(true);
        $client->set_extract_footer(true);
        $client->set_table_format('html');
        $client->set_image_limit(3);
        $client->set_image_min_size(32);
        $client->set_confidence_scores_granularity('word');

        $this->assertSame([
            'model' => 'mistral-ocr-2505',
            'document' => [
                'type' => 'image_url',
                'image_url' => 'https://example.com/page.png',
            ],
            'pages' => [0, 2],
            'include_image_base64' => true,
            'include_blocks' => false,
            'extract_header' => true,
            'extract_footer' => true,
            'table_format' => 'html',
            'image_limit' => 3,
            'image_min_size' => 32,
            'confidence_scores_granularity' => 'word',
        ], $client->payloadSnapshot());
    }

    public function testDocumentUrlKeepsOptionalName(): void
    {
        $client = new TestableMistralOcrClient();
        $client->set_document_url('https://example.com/scan.pdf');

        $this->assertSame([
            'type' => 'document_url',
            'document_url' => 'https://example.com/scan.pdf',
        ], $client->document());

        $client->set_document_url('https://example.com/scan.pdf', 'scan.pdf');
        $this->assertSame('scan.pdf', $client->document()['document_name']);
    }

    public function testRejectsUnsupportedFileAndInvalidOptions(): void
    {
        $path = $this->temporaryFile('plain text');

        try {
            $client = new TestableMistralOcrClient();
            $this->expectException(InvalidArgumentException::class);
            $client->set_file($path, 'notes.txt');
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsInvalidTableFormat(): void
    {
        $client = new TestableMistralOcrClient();
        $this->expectException(InvalidArgumentException::class);
        $client->set_table_format('csv');
    }

    public function testProcessRequiresADocument(): void
    {
        $client = new TestableMistralOcrClient();
        $this->expectException(InvalidArgumentException::class);
        $client->process();
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'corrai_mistral_ocr_');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);
        return $path;
    }

    private function temporaryPng(int $width, int $height): string
    {
        $path = tempnam(sys_get_temp_dir(), 'corrai_mistral_ocr_png_') . '.png';
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        imagepng($image, $path);
        imagedestroy($image);
        return $path;
    }
}

class TestableMistralOcrClient extends MistralOcrClient
{
    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return $this->payload['document'];
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadSnapshot(): array
    {
        return $this->payload;
    }
}
