<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\LlmClient\ClaudeSonnetClient;
use Corrai\Subject\DictationFranceCM2\Pipeline;
use Corrai\Utils\WSException;
use PHPUnit\Framework\TestCase;

class DictationFranceCM2PipelineTest extends TestCase
{
    private string $tempImagePath;

    protected function setUp(): void
    {
        $img = imagecreatetruecolor(500, 800);
        $this->assertNotFalse($img);
        $this->tempImagePath = tempnam(sys_get_temp_dir(), 'test_img_') . '.png';
        imagepng($img, $this->tempImagePath);
        imagedestroy($img);
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempImagePath)) {
            @unlink($this->tempImagePath);
        }
    }

    public function testGdDirectivesParsesJsonStoresCroppedAttributesAndCorrectsCoordinates(): void
    {
        // Cropped dimensions: width = 450 - 50 = 400, height = 750 - 50 = 700
        $inputCorrection = json_encode([
            'cropped_image' => [
                'x1' => 50,
                'y1' => 50,
                'x2' => 450,
                'y2' => 750,
            ],
            'errors' => [
                [
                    'student' => 'avansse',
                    'expected' => 'avance',
                    'kind' => 'orthographe',
                    'box' => [
                        'x1' => 100, // 100/1000 * 400 + 50 = 40 + 50 = 90
                        'y1' => 100, // 100/1000 * 700 + 50 = 70 + 50 = 120
                        'x2' => 250, // 250/1000 * 400 + 50 = 100 + 50 = 150
                        'y2' => 200, // 200/1000 * 700 + 50 = 140 + 50 = 190
                    ],
                ],
                [
                    'student' => 'chevalle',
                    'expected' => 'cheval',
                    'kind' => 'orthographe',
                    'box' => [
                        'x1' => 500, // 500/1000 * 400 + 50 = 200 + 50 = 250
                        'y1' => 0,   // 0/1000 * 700 + 50 = 0 + 50 = 50
                        'x2' => 1000, // 1000/1000 * 400 + 50 = 400 + 50 = 450
                        'y2' => 500,  // 500/1000 * 700 + 50 = 350 + 50 = 400
                    ],
                ],
            ],
        ]);

        $mockClient = new CapturedClaudeSonnetClient();
        $pipeline = new TestablePipeline($mockClient);

        $pipeline->callGdDirectives($this->tempImagePath, 'copy.png', $inputCorrection);

        // Object attributes should be set
        $this->assertSame(50, $pipeline->cropped_x1);
        $this->assertSame(50, $pipeline->cropped_y1);
        $this->assertSame(450, $pipeline->cropped_x2);
        $this->assertSame(750, $pipeline->cropped_y2);

        $promptText = $mockClient->sentPromptText();
        $this->assertStringContainsString('Correction listing the errors to mark:', $promptText);

        $jsonStart = strpos($promptText, '{');
        $this->assertNotFalse($jsonStart);
        $sentJson = substr($promptText, $jsonStart);
        $data = json_decode($sentJson, true);
        $this->assertIsArray($data);

        // cropped_image values should remain unchanged
        $this->assertSame(50, $data['cropped_image']['x1']);
        $this->assertSame(50, $data['cropped_image']['y1']);
        $this->assertSame(450, $data['cropped_image']['x2']);
        $this->assertSame(750, $data['cropped_image']['y2']);

        // First error: (value / 1000) * cropped_dim + x1/y1
        $this->assertSame(90, $data['errors'][0]['box']['x1']);
        $this->assertSame(120, $data['errors'][0]['box']['y1']);
        $this->assertSame(150, $data['errors'][0]['box']['x2']);
        $this->assertSame(190, $data['errors'][0]['box']['y2']);

        // Second error
        $this->assertSame(250, $data['errors'][1]['box']['x1']);
        $this->assertSame(50, $data['errors'][1]['box']['y1']);
        $this->assertSame(450, $data['errors'][1]['box']['x2']);
        $this->assertSame(400, $data['errors'][1]['box']['y2']);
    }

    public function testExtractCroppedCoordinatesAndAdjustCoordinatesWithRootLevelKeys(): void
    {
        $pipeline = new Pipeline();
        // Cropped dimensions: width = 215 - 15 = 200, height = 325 - 25 = 300
        $data = [
            'cropped_x1' => 15,
            'cropped_y1' => 25,
            'cropped_x2' => 215,
            'cropped_y2' => 325,
            'errors' => [
                [
                    'box' => [
                        'x1' => 100, // 100/1000 * 200 + 15 = 20 + 15 = 35
                        'y1' => 200, // 200/1000 * 300 + 25 = 60 + 25 = 85
                        'x2' => 500, // 500/1000 * 200 + 15 = 100 + 15 = 115
                        'y2' => 600, // 600/1000 * 300 + 25 = 180 + 25 = 205
                    ],
                ],
            ],
        ];

        $pipeline->extractCroppedCoordinates($data);
        $this->assertSame(15, $pipeline->cropped_x1);
        $this->assertSame(25, $pipeline->cropped_y1);
        $this->assertSame(215, $pipeline->cropped_x2);
        $this->assertSame(325, $pipeline->cropped_y2);

        $reflection = new \ReflectionMethod(Pipeline::class, 'adjustCoordinates');
        $adjusted = $reflection->invoke($pipeline, $data);

        $this->assertSame(35, $adjusted['errors'][0]['box']['x1']);
        $this->assertSame(85, $adjusted['errors'][0]['box']['y1']);
        $this->assertSame(115, $adjusted['errors'][0]['box']['x2']);
        $this->assertSame(205, $adjusted['errors'][0]['box']['y2']);
    }

    public function testAdjustCoordinatesDividesMappedPixelsByRescale(): void
    {
        $pipeline = new Pipeline();
        $pipeline->rescale = 0.5;
        $data = [
            'cropped_x1' => 15,
            'cropped_y1' => 25,
            'cropped_x2' => 215,
            'cropped_y2' => 325,
            'errors' => [
                [
                    'box' => [
                        'x1' => 100, // (100/1000 * 200 + 15) / 0.5 = 70
                        'y1' => 200, // (200/1000 * 300 + 25) / 0.5 = 170
                        'x2' => 500, // (500/1000 * 200 + 15) / 0.5 = 230
                        'y2' => 600, // (600/1000 * 300 + 25) / 0.5 = 410
                    ],
                ],
            ],
        ];

        $pipeline->extractCroppedCoordinates($data);
        $reflection = new \ReflectionMethod(Pipeline::class, 'adjustCoordinates');
        $adjusted = $reflection->invoke($pipeline, $data);

        $this->assertSame(70, $adjusted['errors'][0]['box']['x1']);
        $this->assertSame(170, $adjusted['errors'][0]['box']['y1']);
        $this->assertSame(230, $adjusted['errors'][0]['box']['x2']);
        $this->assertSame(410, $adjusted['errors'][0]['box']['y2']);
    }

    public function testGdDirectivesThrowsExceptionOnInvalidJson(): void
    {
        $mockClient = new CapturedClaudeSonnetClient();
        $pipeline = new TestablePipeline($mockClient);

        $this->expectException(WSException::class);
        $this->expectExceptionMessage('Invalid correction JSON');
        $pipeline->callGdDirectives($this->tempImagePath, 'copy.png', 'Not a valid JSON');
    }

    public function testDebugFlagIsActiveByDefaultAndAddsCroppingRectangleDirective(): void
    {
        $this->assertSame(1, Pipeline::DEBUG);

        $inputCorrection = json_encode([
            'cropped_image' => [
                'x1' => 60,
                'y1' => 70,
                'x2' => 460,
                'y2' => 760,
            ],
            'errors' => [],
        ]);

        $mockClient = new CapturedClaudeSonnetClient();
        $pipeline = new TestablePipeline($mockClient);
        $this->assertTrue($pipeline->isDebug());

        $directivesPhp = $pipeline->callGdDirectives($this->tempImagePath, 'copy.png', $inputCorrection);

        $reflection = new \ReflectionMethod(Pipeline::class, 'loadDirectives');
        $directives = $reflection->invoke($pipeline, $directivesPhp);

        $this->assertCount(1, $directives);
        $this->assertSame('imagerectangle', $directives[0]['fn']);
        $this->assertSame([60, 70, 460, 760], $directives[0]['args']);
        $this->assertSame('red', $directives[0]['color']);

        $renderReflection = new \ReflectionMethod(Pipeline::class, 'renderCorrection');
        $png = $renderReflection->invoke($pipeline, $this->tempImagePath, $directivesPhp);
        $this->assertIsString($png);
        $this->assertNotEmpty($png);
    }

    public function testDebugFlagDisabledDoesNotAddCroppingRectangleDirective(): void
    {
        $inputCorrection = json_encode([
            'cropped_image' => [
                'x1' => 60,
                'y1' => 70,
                'x2' => 460,
                'y2' => 760,
            ],
            'errors' => [],
        ]);

        $mockClient = new CapturedClaudeSonnetClient();
        $pipeline = new TestablePipeline($mockClient);
        $pipeline->debug = 0;
        $this->assertFalse($pipeline->isDebug());

        $directivesPhp = $pipeline->callGdDirectives($this->tempImagePath, 'copy.png', $inputCorrection);

        $reflection = new \ReflectionMethod(Pipeline::class, 'loadDirectives');
        $directives = $reflection->invoke($pipeline, $directivesPhp);

        $this->assertCount(0, $directives);
    }

    public function testFindErrorsSchemaUsesCroppedImage(): void
    {
        $mockClient = new CapturedClaudeSonnetClient();
        $pipeline = new TestablePipeline($mockClient);

        $exam = $this->createMock(\Corrai\Model\Exam::class);
        $exam->method('instructionFilesText')->willReturn('Exam instructions');

        $solutionPath = tempnam(sys_get_temp_dir(), 'test_sol_') . '.png';
        copy($this->tempImagePath, $solutionPath);

        try {
            $pipeline->callFindErrors($exam, $this->tempImagePath, 'copy.png', $solutionPath, 'sol.png', 'French');

            $schema = $mockClient->responseFormat()['json_schema']['schema'];
            $this->assertArrayNotHasKey('analyzed_image', $schema['properties']);
            $this->assertContains('cropped_image', $schema['required']);

            $croppedImage = $schema['properties']['cropped_image'];
            $this->assertSame(['x1', 'y1', 'x2', 'y2'], $croppedImage['required']);
            $this->assertSame('integer', $croppedImage['properties']['x1']['type']);
            $this->assertSame('integer', $croppedImage['properties']['y1']['type']);
            $this->assertSame('integer', $croppedImage['properties']['x2']['type']);
            $this->assertSame('integer', $croppedImage['properties']['y2']['type']);
        } finally {
            if (is_file($solutionPath)) {
                @unlink($solutionPath);
            }
        }
    }
}

class TestablePipeline extends Pipeline
{
    public function __construct(private readonly ClaudeSonnetClient $mockClient)
    {
    }

    protected function createClaudeSonnetClient(): ClaudeSonnetClient
    {
        return $this->mockClient;
    }

    public function callFindErrors(
        \Corrai\Model\Exam $exam,
        string $copyPath,
        string $copyName,
        string $solutionPath,
        string $solutionName,
        string $languageName
    ): string {
        return $this->findErrors($exam, $copyPath, $copyName, $solutionPath, $solutionName, $languageName);
    }

    public function callGdDirectives(string $copyPath, string $copyName, string $correction): string
    {
        return $this->gdDirectives($copyPath, $copyName, $correction);
    }
}

class CapturedClaudeSonnetClient extends ClaudeSonnetClient
{
    private string $promptText = '';

    public function add_text(string $text): void
    {
        $this->promptText .= $text . "\n";
        parent::add_text($text);
    }

    public function sentPromptText(): string
    {
        return $this->promptText;
    }

    public function systemContent(): string
    {
        return (string) ($this->payload['messages'][0]['content'] ?? '');
    }

    public function responseFormat(): array
    {
        return is_array($this->payload['response_format'] ?? null) ? $this->payload['response_format'] : [];
    }

    public function call_text(): string
    {
        return '<?php $GD_directives = [];';
    }
}
