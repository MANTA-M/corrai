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

    public function testLargeImageIsDownscaledBeforeSend(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $path = $this->temporaryPng(2000, 800);

        try {
            $client = new TestableClaudeSonnetClient();
            $client->add_file($path, 'scan.png');

            $url = $client->userContent()[0]['image_url']['url'] ?? '';
            $this->assertMatchesRegularExpression('#^data:image/png;base64,#', $url);
            $body = $this->decodeDataUrlBody($url);
            $size = @getimagesizefromstring($body);
            $this->assertNotFalse($size);
            $this->assertLessThanOrEqual(ClaudeSonnetClient::MAX_IMAGE_DIMENTION, max($size[0], $size[1]));
            $this->assertSame(ClaudeSonnetClient::MAX_IMAGE_DIMENTION, $size[0]);
            $this->assertSame(627, $size[1]);
            $this->assertEqualsWithDelta(ClaudeSonnetClient::MAX_IMAGE_DIMENTION / 2000, $client->rescale, 0.000001);
        } finally {
            @unlink($path);
        }
    }

    public function testSmallImageIsNotResized(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required');
        }

        $path = $this->temporaryPng(400, 300);

        try {
            $client = new TestableClaudeSonnetClient();
            $client->add_file($path, 'small.png');

            $url = $client->userContent()[0]['image_url']['url'] ?? '';
            $body = $this->decodeDataUrlBody($url);
            $size = @getimagesizefromstring($body);
            $this->assertNotFalse($size);
            $this->assertSame(400, $size[0]);
            $this->assertSame(300, $size[1]);
            $this->assertSame(1.0, $client->rescale);
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

        $client = new TestableClaudeSonnetClient();
        $client->set_json_response('sample', $schema);

        $this->assertSame([
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'sample',
                'strict' => true,
                'schema' => $schema,
            ],
        ], $client->responseFormat());
        $this->assertTrue($client->provider()['require_parameters']);
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'corrai_test_');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);
        return $path;
    }

    private function temporaryPng(int $width, int $height): string
    {
        $path = tempnam(sys_get_temp_dir(), 'corrai_png_') . '.png';
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        imagepng($image, $path);
        imagedestroy($image);
        return $path;
    }

    private function decodeDataUrlBody(string $url): string
    {
        $this->assertMatchesRegularExpression('#^data:[^;]+;base64,(.+)$#s', $url, $url);
        preg_match('#^data:[^;]+;base64,(.+)$#s', $url, $matches);
        $body = base64_decode($matches[1], true);
        $this->assertNotFalse($body);
        return $body;
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

    public function responseFormat(): array
    {
        return $this->payload['response_format'];
    }

    public function provider(): array
    {
        return $this->payload['provider'];
    }
}
