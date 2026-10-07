<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Clients\Google\MissingCredentialsException;
use Corrai\Clients\Google\Vision;
use Corrai\Clients\LlmClient;
use Corrai\Model\InputFile;
use Corrai\Model\OCRResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GoogleVisionClientTest extends TestCase
{
    public function testVisionExtendsLlmClient(): void
    {
        $this->assertTrue(is_subclass_of(Vision::class, LlmClient::class));
    }

    public function testDefaultCredentialsPathIsServerGoogleKeys(): void
    {
        $client = new Vision();
        $property = new \ReflectionProperty(Vision::class, 'keysPath');
        $property->setAccessible(true);
        $this->assertSame(
            dirname(__DIR__, 2) . '/google-keys.json',
            $property->getValue($client)
        );
    }

    public function testProcessThrowsWhenCredentialsFileIsMissing(): void
    {
        $previous = getenv('GOOGLE_KEYS_JSON');
        putenv('GOOGLE_KEYS_JSON');
        unset($_ENV['GOOGLE_KEYS_JSON']);

        $path = $this->tempPng();
        try {
            $client = new Vision('/tmp/corrai-missing-google-keys.json');
            $client->set_file($path, 'page.png');
            $this->expectException(MissingCredentialsException::class);
            $this->expectExceptionMessage('Google Vision credentials file not found: /tmp/corrai-missing-google-keys.json');
            $client->process();
        } finally {
            @unlink($path);
            if (is_string($previous) && $previous !== '') {
                putenv('GOOGLE_KEYS_JSON=' . $previous);
                $_ENV['GOOGLE_KEYS_JSON'] = $previous;
            }
        }
    }

    public function testProcessMapsDocumentTextToEdenBoxes(): void
    {
        $client = new FakeVisionClient([
            'responses' => [[
                'fullTextAnnotation' => [
                    'text' => 'Une makine',
                    'pages' => [[
                        'width' => 1000,
                        'height' => 500,
                        'blocks' => [[
                            'paragraphs' => [[
                                'words' => [
                                    [
                                        'boundingBox' => [
                                            'vertices' => [
                                                ['x' => 100, 'y' => 50],
                                                ['x' => 180, 'y' => 50],
                                                ['x' => 180, 'y' => 80],
                                                ['x' => 100, 'y' => 80],
                                            ],
                                        ],
                                        'symbols' => [
                                            ['text' => 'U'],
                                            ['text' => 'n'],
                                            ['text' => 'e'],
                                        ],
                                    ],
                                    [
                                        'boundingBox' => [
                                            'normalizedVertices' => [
                                                ['x' => 0.2, 'y' => 0.1],
                                                ['x' => 0.4, 'y' => 0.1],
                                                ['x' => 0.4, 'y' => 0.2],
                                                ['x' => 0.2, 'y' => 0.2],
                                            ],
                                        ],
                                        'symbols' => [
                                            ['text' => 'makine'],
                                        ],
                                    ],
                                ],
                            ]],
                        ]],
                    ]],
                ],
            ]],
        ]);

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
        $this->assertEqualsWithDelta(0.1, $output['bounding_boxes'][0]['left'], 0.00001);
        $this->assertEqualsWithDelta(0.1, $output['bounding_boxes'][0]['top'], 0.00001);
        $this->assertEqualsWithDelta(0.08, $output['bounding_boxes'][0]['width'], 0.00001);
        $this->assertEqualsWithDelta(0.06, $output['bounding_boxes'][0]['height'], 0.00001);
        $this->assertSame(0, $output['bounding_boxes'][0]['page']);
        $this->assertSame('makine', $output['bounding_boxes'][1]['text']);
        $this->assertEqualsWithDelta(0.2, $output['bounding_boxes'][1]['left'], 0.00001);
        $this->assertEqualsWithDelta(0.2, $output['bounding_boxes'][1]['width'], 0.00001);

        $request = $client->requests[0]['requests'][0];
        $this->assertSame(Vision::FEATURE, $request['features'][0]['type']);
        $this->assertSame(['fr'], $request['imageContext']['languageHints']);
        $this->assertArrayHasKey('content', $request['image']);
        $this->assertSame(Vision::IMAGES_ANNOTATE_URL, $client->urls[0]);

        $ocr = OCRResult::from_google($output);
        $this->assertSame('Une', $ocr->words[0]->text);
        $this->assertEqualsWithDelta(0.18, $ocr->words[0]->right, 0.00001);
        $this->assertEqualsWithDelta(0.16, $ocr->words[0]->bottom, 0.00001);
    }

    public function testPdfRequestsRemainingPagesAfterTheFirst(): void
    {
        $client = new FakeVisionClient(null);
        $client->fileResponses = [
            [
                'responses' => [[
                    'totalPages' => 2,
                    'responses' => [[
                        'context' => ['pageNumber' => 1],
                        'fullTextAnnotation' => $this->pageAnnotation('Page'),
                    ]],
                ]],
            ],
            [
                'responses' => [[
                    'totalPages' => 2,
                    'responses' => [[
                        'context' => ['pageNumber' => 2],
                        'fullTextAnnotation' => $this->pageAnnotation('Two'),
                    ]],
                ]],
            ],
        ];

        $path = tempnam(sys_get_temp_dir(), 'vision_pdf_') . '.pdf';
        file_put_contents($path, "%PDF-1.4\n");
        try {
            $client->set_file($path, 'scan.pdf');
            $output = $client->process();
        } finally {
            @unlink($path);
        }

        $this->assertSame("Page\nTwo", $output['text']);
        $this->assertSame(0, $output['bounding_boxes'][0]['page']);
        $this->assertSame(1, $output['bounding_boxes'][1]['page']);
        $this->assertSame([1], $client->requests[0]['requests'][0]['pages']);
        $this->assertSame([2], $client->requests[1]['requests'][0]['pages']);
        $this->assertSame('application/pdf', $client->requests[0]['requests'][0]['inputConfig']['mimeType']);
        $this->assertSame(Vision::FILES_ANNOTATE_URL, $client->urls[0]);
    }

    public function testApiErrorBecomesAnException(): void
    {
        $client = new FakeVisionClient([
            'responses' => [[
                'error' => ['code' => 3, 'message' => 'Bad image data.'],
            ]],
        ]);
        $path = $this->tempPng();
        try {
            $client->set_file($path, 'scan.png');
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('Bad image data.');
            $client->process();
        } finally {
            @unlink($path);
        }
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
            $client = new Vision();
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

        $client = new Vision();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Image file too heavy');
        $client->set_file($file);
    }

    public function testSetRemoteFileRejectsAnEdenFileId(): void
    {
        $client = new Vision();
        $this->expectException(InvalidArgumentException::class);
        $client->set_remote_file('file-abc123');
    }

    public function testLogResponseConstantLogsFormattedOcrResult(): void
    {
        $client = new FakeVisionClient([
            'responses' => [[
                'fullTextAnnotation' => [
                    'text' => 'Hello',
                    'pages' => [[
                        'width' => 100,
                        'height' => 100,
                        'blocks' => [[
                            'paragraphs' => [[
                                'words' => [[
                                    'boundingBox' => [
                                        'vertices' => [
                                            ['x' => 10, 'y' => 10],
                                            ['x' => 30, 'y' => 10],
                                            ['x' => 30, 'y' => 20],
                                            ['x' => 10, 'y' => 20],
                                        ],
                                    ],
                                    'symbols' => [['text' => 'Hello']],
                                ]],
                            ]],
                        ]],
                    ]],
                ],
            ]],
        ]);

        $this->assertTrue(Vision::LOG_RESPONSE);
        $path = $this->tempPng();
        $prevLog = ini_get('error_log');
        $tempLog = tempnam(sys_get_temp_dir(), 'vision_log_');
        ini_set('error_log', $tempLog);

        try {
            $client->set_file($path, 'scan.png');
            $client->process();
            $logContent = file_get_contents($tempLog);
            $this->assertIsString($logContent);
            $this->assertStringContainsString('[Vision] Processed OCR Result (HTTP 200):', $logContent);
            $this->assertStringContainsString('"text": "Hello"', $logContent);
            $this->assertStringContainsString('"bounding_boxes":', $logContent);
        } finally {
            if ($prevLog !== false) {
                ini_set('error_log', $prevLog);
            }
            @unlink($path);
            if ($tempLog !== false) {
                @unlink($tempLog);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pageAnnotation(string $word): array
    {
        return [
            'text' => $word,
            'pages' => [[
                'width' => 100,
                'height' => 100,
                'blocks' => [[
                    'paragraphs' => [[
                        'words' => [[
                            'boundingBox' => [
                                'vertices' => [
                                    ['x' => 0, 'y' => 0],
                                    ['x' => 10, 'y' => 0],
                                    ['x' => 10, 'y' => 10],
                                    ['x' => 0, 'y' => 10],
                                ],
                            ],
                            'symbols' => [['text' => $word]],
                        ]],
                    ]],
                ]],
            ]],
        ];
    }

    private function tempPng(): string
    {
        $img = imagecreatetruecolor(20, 20);
        $this->assertNotFalse($img);
        $path = tempnam(sys_get_temp_dir(), 'vision_img_') . '.png';
        imagepng($img, $path);
        imagedestroy($img);
        return $path;
    }
}

class FakeVisionClient extends Vision
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** @var list<string> */
    public array $urls = [];

    /** @var list<array<string, mixed>>|null */
    public ?array $fileResponses = null;

    /**
     * @param array<string, mixed>|null $imageResponse
     */
    public function __construct(?array $imageResponse)
    {
        parent::__construct('/does/not/exist/google-keys.json');
        $this->imageResponse = $imageResponse;
    }

    /** @var array<string, mixed>|null */
    private ?array $imageResponse;

    protected function accessToken(): string
    {
        return 'test-token';
    }

    public function QueryArray(string $path, string $method = 'GET', $headers = null, $postData = null): ?array
    {
        $this->lastResponseCode = 200;
        $this->urls[] = $path;
        $this->requests[] = is_array($postData) ? $postData : [];
        if ($path === Vision::FILES_ANNOTATE_URL) {
            $response = array_shift($this->fileResponses);
            return is_array($response) ? $response : null;
        }
        return $this->imageResponse;
    }
}
