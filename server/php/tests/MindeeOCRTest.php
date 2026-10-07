<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Clients\LlmClient;
use Corrai\Clients\Mindee\MindeeOCR;
use Corrai\Model\InputFile;
use Corrai\Model\OCRResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MindeeOCRTest extends TestCase
{
    public function testExtendsLlmClient(): void
    {
        $this->assertTrue(is_subclass_of(MindeeOCR::class, LlmClient::class));
    }

    public function testUsesMindeeApiKeyAndDefaultModel(): void
    {
        $client = new MindeeOCR();
        $token = new \ReflectionProperty(MindeeOCR::class, 'token');
        $token->setAccessible(true);
        $model = new \ReflectionProperty(MindeeOCR::class, 'modelId');
        $model->setAccessible(true);

        $this->assertNotSame('', $token->getValue($client));
        $this->assertSame($_ENV['MINDEE_API_KEY'], $token->getValue($client));
        $this->assertSame(MindeeOCR::DEFAULT_MODEL_ID, $model->getValue($client));
    }

    public function testModelIdCanComeFromTheEnvironment(): void
    {
        $previous = getenv('MINDEE_OCR_MODEL_ID');
        putenv('MINDEE_OCR_MODEL_ID=model-from-env');
        $_ENV['MINDEE_OCR_MODEL_ID'] = 'model-from-env';
        try {
            $client = new MindeeOCR();
            $model = new \ReflectionProperty(MindeeOCR::class, 'modelId');
            $model->setAccessible(true);
            $this->assertSame('model-from-env', $model->getValue($client));
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv('MINDEE_OCR_MODEL_ID=' . $previous);
                $_ENV['MINDEE_OCR_MODEL_ID'] = $previous;
            } else {
                putenv('MINDEE_OCR_MODEL_ID');
                unset($_ENV['MINDEE_OCR_MODEL_ID']);
            }
        }
    }

    public function testProcessThrowsWhenApiKeyIsMissing(): void
    {
        $path = $this->tempPng();
        try {
            $client = new MindeeOCR('');
            $client->set_file($path, 'page.png');
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('MINDEE_API_KEY is required');
            $client->process();
        } finally {
            @unlink($path);
        }
    }

    public function testProcessThrowsWhenFileIsMissing(): void
    {
        $client = new MindeeOCR('test-key');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OCR file is required');
        $client->process();
    }

    public function testProcessMapsWordPolygonsToEdenBoxes(): void
    {
        $client = new FakeMindeeOCR([[
            'content' => 'Une makine',
            'words' => [
                [
                    'content' => 'Une',
                    'polygon' => [
                        [0.09742441209406495, 0.07007125890736342],
                        [0.15621500559910415, 0.07046714172604909],
                        [0.15621500559910415, 0.08155186064924783],
                        [0.09742441209406495, 0.08155186064924783],
                    ],
                ],
                [
                    'content' => 'makine',
                    'polygon' => [
                        [0.2, 0.1],
                        [0.4, 0.1],
                        [0.4, 0.2],
                        [0.2, 0.2],
                    ],
                ],
            ],
        ]]);

        $path = $this->tempPng();
        try {
            $client->set_file($path, 'scan.png');
            $client->set_language('fr');
            $output = $client->process();
        } finally {
            @unlink($path);
        }

        $this->assertSame('Une makine', $output['text']);
        $this->assertNull($output['usage']);
        $this->assertSame('Une', $output['bounding_boxes'][0]['text']);
        $this->assertEqualsWithDelta(0.09742441209406495, $output['bounding_boxes'][0]['left'], 0.0000001);
        $this->assertEqualsWithDelta(0.07007125890736342, $output['bounding_boxes'][0]['top'], 0.0000001);
        $this->assertEqualsWithDelta(0.0587905935050392, $output['bounding_boxes'][0]['width'], 0.0000001);
        $this->assertEqualsWithDelta(0.01148060174188441, $output['bounding_boxes'][0]['height'], 0.0000001);
        $this->assertSame(0, $output['bounding_boxes'][0]['page']);
        $this->assertSame('makine', $output['bounding_boxes'][1]['text']);
        $this->assertEqualsWithDelta(0.2, $output['bounding_boxes'][1]['left'], 0.00001);
        $this->assertEqualsWithDelta(0.2, $output['bounding_boxes'][1]['width'], 0.00001);
        $this->assertSame('fr', $client->language());

        $ocr = OCRResult::from_google($output);
        $this->assertSame('Une', $ocr->words[0]->text);
        $this->assertEqualsWithDelta(0.15621500559910415, $ocr->words[0]->right, 0.0000001);
        $this->assertEqualsWithDelta(0.08155186064924783, $ocr->words[0]->bottom, 0.0000001);
    }

    public function testPagesAreZeroBased(): void
    {
        $client = new FakeMindeeOCR([
            [
                'content' => 'Page',
                'words' => [[
                    'content' => 'Page',
                    'polygon' => [[0, 0], [0.1, 0], [0.1, 0.1], [0, 0.1]],
                ]],
            ],
            [
                'content' => 'Two',
                'words' => [[
                    'content' => 'Two',
                    'polygon' => [[0, 0], [0.1, 0], [0.1, 0.1], [0, 0.1]],
                ]],
            ],
        ]);

        $path = $this->tempPng();
        try {
            $client->set_file($path, 'scan.png');
            $output = $client->process();
        } finally {
            @unlink($path);
        }

        $this->assertSame("Page\nTwo", $output['text']);
        $this->assertSame(0, $output['bounding_boxes'][0]['page']);
        $this->assertSame(1, $output['bounding_boxes'][1]['page']);
    }

    public function testSetRemoteFileRejectsAFileId(): void
    {
        $client = new MindeeOCR('test-key');
        $this->expectException(InvalidArgumentException::class);
        $client->set_remote_file('file-abc123');
    }

    public function testSetLanguageRejectsEmpty(): void
    {
        $client = new MindeeOCR('test-key');
        $this->expectException(InvalidArgumentException::class);
        $client->set_language('  ');
    }

    public function testSetFileThrowsWhenFileExceeds10Mb(): void
    {
        $largeFilePath = tempnam(sys_get_temp_dir(), 'large_img_') . '.png';
        $fp = fopen($largeFilePath, 'wb');
        $this->assertNotFalse($fp);
        fseek($fp, 10 * 1024 * 1024);
        fwrite($fp, "\0");
        fclose($fp);

        try {
            $client = new MindeeOCR('test-key');
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('Image file too heavy');
            $client->set_file($largeFilePath, 'scan.png');
        } finally {
            @unlink($largeFilePath);
        }
    }

    public function testSetFileAppendsEventWhenInputFileExceeds10Mb(): void
    {
        $file = $this->createMock(InputFile::class);
        $file->size = 11 * 1024 * 1024;
        $file->name = 'scan.png';
        $file->expects($this->once())
            ->method('appendEvent')
            ->with('Image file too heavy');

        $client = new MindeeOCR('test-key');
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Image file too heavy');
        $client->set_file($file);
    }

    private function tempPng(): string
    {
        $img = imagecreatetruecolor(20, 20);
        $this->assertNotFalse($img);
        $path = tempnam(sys_get_temp_dir(), 'mindee_img_') . '.png';
        imagepng($img, $path);
        imagedestroy($img);
        return $path;
    }
}

class FakeMindeeOCR extends MindeeOCR
{
    /**
     * @param list<array<string, mixed>> $pages
     */
    public function __construct(private array $pages)
    {
        parent::__construct('test-key', 'test-model');
    }

    public function language(): ?string
    {
        $property = new \ReflectionProperty(MindeeOCR::class, 'language');
        $property->setAccessible(true);
        $language = $property->getValue($this);
        return is_string($language) ? $language : null;
    }

    protected function requestPages(): array
    {
        return $this->pages;
    }
}
