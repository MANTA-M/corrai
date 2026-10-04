<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Llm\Mistral\MistralClient;
use Corrai\Llm\Mistral\MistralDirectClient;
use PHPUnit\Framework\TestCase;

class MistralDirectClientTest extends TestCase
{
    public function testTextFileIsAddedAsTextContent(): void
    {
        $path = $this->temporaryFile('Correction text');

        try {
            $client = new TestableMistralDirectClient();
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

    public function testPdfUsesDocumentUrlChunk(): void
    {
        $path = $this->temporaryFile('%PDF-1.4');

        try {
            $client = new TestableMistralDirectClient();
            $client->add_file($path, 'corrigé.pdf');

            $content = $client->userContent();
            $this->assertSame('document_url', $content[0]['type']);
            $this->assertSame('corrigé.pdf', $content[0]['document_name']);
            $this->assertStringStartsWith(
                'data:application/pdf;base64,',
                $content[0]['document_url']
            );
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
            $client = new TestableMistralDirectClient();
            $client->add_file($path, 'scan.png');

            $content = $client->userContent();
            $this->assertSame('image_url', $content[0]['type']);
            $url = $content[0]['image_url']['url'] ?? '';
            $this->assertMatchesRegularExpression('#^data:image/png;base64,#', $url);
        } finally {
            @unlink($path);
        }
    }

    public function testJsonResponseFormatIsSetOnThePayload(): void
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['ok'],
            'properties' => [
                'ok' => ['type' => 'boolean'],
            ],
        ];

        $client = new TestableMistralDirectClient();
        $client->set_json_response('sample', $schema);

        $this->assertSame([
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'sample',
                'strict' => true,
                'schema' => $schema,
            ],
        ], $client->responseFormat());
        $this->assertArrayNotHasKey('provider', $client->payloadSnapshot());
    }

    public function testEnableImageOutputAddsImageGenerationToolOnce(): void
    {
        $client = new TestableMistralDirectClient();
        $client->enable_image_output();
        $client->enable_image_output();

        $this->assertSame([
            ['type' => 'image_generation'],
        ], $client->tools());
        $this->assertArrayNotHasKey('modalities', $client->payloadSnapshot());
    }

    public function testAddTextAndSystemContent(): void
    {
        $client = new TestableMistralDirectClient();
        $client->set_system_content('You are helpful.');
        $client->add_text('Hello');

        $messages = $client->messages();
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('You are helpful.', $messages[0]['content']);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertSame([
            ['type' => 'text', 'text' => 'Hello'],
        ], $messages[1]['content']);
    }

    public function testMistralClientAndMistralDirectClientInheritance(): void
    {
        $directClient = new MistralDirectClient();
        $this->assertInstanceOf(MistralClient::class, $directClient);

        $client = new MistralClient('mistral-small-latest');
        $this->assertInstanceOf(MistralClient::class, $client);
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'corrai_mistral_');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);
        return $path;
    }

    private function temporaryPng(int $width, int $height): string
    {
        $path = tempnam(sys_get_temp_dir(), 'corrai_mistral_png_') . '.png';
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        imagepng($image, $path);
        imagedestroy($image);
        return $path;
    }
}

class TestableMistralDirectClient extends MistralDirectClient
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

    public function responseFormat(): array
    {
        return $this->payload['response_format'];
    }

    public function tools(): array
    {
        return $this->payload['tools'] ?? [];
    }

    public function messages(): array
    {
        return $this->payload['messages'];
    }

    public function payloadSnapshot(): array
    {
        return $this->payload;
    }
}
