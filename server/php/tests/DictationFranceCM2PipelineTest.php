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

    public function testHandwrittenBaselineYsBecomeThirtyPixelLines(): void
    {
        // Cropped height: 750 - 50 = 700
        $inputCorrection = json_encode([
            'cropped_image' => [
                'x1' => 50,
                'y1' => 50,
                'x2' => 450,
                'y2' => 750,
            ],
            'lines' => [
                ['y' => 100], // 100/1000 * 700 + 50 = 120
                ['y' => 500], // 500/1000 * 700 + 50 = 400
            ],
            'errors' => [
                [
                    'student' => 'avansse',
                    'expected' => 'avance',
                    'kind' => 'orthographe',
                    'box' => [
                        'x1' => 100, // 100/1000 * 400 + 50 = 90
                        'y1' => 100, // 100/1000 * 700 + 50 = 120
                        'x2' => 250,
                        'y2' => 200,
                    ],
                ],
            ],
        ]);

        $mockClient = new CapturedClaudeSonnetClient();
        $pipeline = new TestablePipeline($mockClient);
        $pipeline->debug = 0;

        $directivesPhp = $pipeline->callGdDirectives($this->tempImagePath, 'copy.png', $inputCorrection);

        $jsonStart = strpos($mockClient->sentPromptText(), '{');
        $this->assertNotFalse($jsonStart);
        $sent = json_decode(substr($mockClient->sentPromptText(), $jsonStart), true);
        $this->assertIsArray($sent);
        $this->assertArrayNotHasKey('lines', $sent);
        $this->assertSame(120, $sent['errors'][0]['box']['y1']);

        $reflection = new \ReflectionMethod(Pipeline::class, 'loadDirectives');
        $directives = $reflection->invoke($pipeline, $directivesPhp);

        $this->assertCount(2, $directives);
        $this->assertSame('imageline', $directives[0]['fn']);
        $this->assertSame([0, 120, 30, 120], $directives[0]['args']);
        $this->assertSame('red', $directives[0]['color']);
        $this->assertSame([0, 400, 30, 400], $directives[1]['args']);

        $renderReflection = new \ReflectionMethod(Pipeline::class, 'renderCorrection');
        $png = $renderReflection->invoke($pipeline, $this->tempImagePath, $directivesPhp);
        $this->assertIsString($png);
        $this->assertNotEmpty($png);
    }

    public function testHandwrittenBaselineYUsesSameRescaleCorrectionAsErrors(): void
    {
        $inputCorrection = json_encode([
            'cropped_image' => [
                'x1' => 50,
                'y1' => 50,
                'x2' => 450,
                'y2' => 750,
            ],
            'lines' => [
                ['y' => 100], // (100/1000 * 700 + 50) / 0.5 = 240
            ],
            'errors' => [],
        ]);

        $mockClient = new CapturedClaudeSonnetClient();
        $pipeline = new TestablePipeline($mockClient);
        $pipeline->rescale = 0.5;
        $pipeline->debug = 0;

        $directivesPhp = $pipeline->callGdDirectives($this->tempImagePath, 'copy.png', $inputCorrection);

        $reflection = new \ReflectionMethod(Pipeline::class, 'loadDirectives');
        $directives = $reflection->invoke($pipeline, $directivesPhp);

        $this->assertCount(1, $directives);
        $this->assertSame([0, 240, 30, 240], $directives[0]['args']);
    }

    public function testCoordinatesPastTheImageAreLimitedToItsDimensions(): void
    {
        // Source image is 500 by 800. Crop is larger than the scan.
        $inputCorrection = json_encode([
            'cropped_image' => [
                'x1' => 0,
                'y1' => 0,
                'x2' => 2000,
                'y2' => 3000,
            ],
            'lines' => [
                ['y' => 1000], // 1000/1000 * 3000 = 3000 → 800
            ],
            'errors' => [
                [
                    'box' => [
                        'x1' => 100, // 100/1000 * 2000 = 200
                        'y1' => 100, // 100/1000 * 3000 = 300
                        'x2' => 1000, // 2000 → 500
                        'y2' => 1000, // 3000 → 800
                    ],
                ],
            ],
        ]);

        $mockClient = new CapturedClaudeSonnetClient();
        $pipeline = new TestablePipeline($mockClient);
        $pipeline->debug = 0;

        $log = tempnam(sys_get_temp_dir(), 'coord_log_');
        $previousLog = ini_set('error_log', $log);
        try {
            $directivesPhp = $pipeline->callGdDirectives($this->tempImagePath, 'copy.png', $inputCorrection);
        } finally {
            if ($previousLog === false) {
                ini_restore('error_log');
            } else {
                ini_set('error_log', $previousLog);
            }
        }
        $logged = is_string($log) && is_file($log) ? (string) file_get_contents($log) : '';
        if (is_string($log) && is_file($log)) {
            @unlink($log);
        }
        $this->assertStringContainsString('X 2000 exceeds source image width 500', $logged);
        $this->assertStringContainsString('Y 3000 exceeds source image height 800', $logged);

        $jsonStart = strpos($mockClient->sentPromptText(), '{');
        $this->assertNotFalse($jsonStart);
        $sent = json_decode(substr($mockClient->sentPromptText(), $jsonStart), true);
        $this->assertIsArray($sent);
        $this->assertSame(200, $sent['errors'][0]['box']['x1']);
        $this->assertSame(300, $sent['errors'][0]['box']['y1']);
        $this->assertSame(500, $sent['errors'][0]['box']['x2']);
        $this->assertSame(800, $sent['errors'][0]['box']['y2']);

        $reflection = new \ReflectionMethod(Pipeline::class, 'loadDirectives');
        $directives = $reflection->invoke($pipeline, $directivesPhp);
        $this->assertSame([0, 800, 30, 800], $directives[0]['args']);
    }

    public function testCropDebugRectangleIsLimitedToImageDimensions(): void
    {
        $inputCorrection = json_encode([
            'cropped_image' => [
                'x1' => 0,
                'y1' => 0,
                'x2' => 900,
                'y2' => 2000,
            ],
            'errors' => [],
        ]);

        $mockClient = new CapturedClaudeSonnetClient();
        $pipeline = new TestablePipeline($mockClient);

        $directivesPhp = $pipeline->callGdDirectives($this->tempImagePath, 'copy.png', $inputCorrection);
        $reflection = new \ReflectionMethod(Pipeline::class, 'loadDirectives');
        $directives = $reflection->invoke($pipeline, $directivesPhp);

        $this->assertSame('imagerectangle', $directives[0]['fn']);
        $this->assertSame([0, 0, 500, 800], $directives[0]['args']);
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
            $this->assertContains('lines', $schema['required']);

            $croppedImage = $schema['properties']['cropped_image'];
            $this->assertSame(['x1', 'y1', 'x2', 'y2'], $croppedImage['required']);
            $this->assertSame('integer', $croppedImage['properties']['x1']['type']);
            $this->assertSame('integer', $croppedImage['properties']['y1']['type']);
            $this->assertSame('integer', $croppedImage['properties']['x2']['type']);
            $this->assertSame('integer', $croppedImage['properties']['y2']['type']);

            $lines = $schema['properties']['lines'];
            $this->assertSame('array', $lines['type']);
            $this->assertSame(['y'], $lines['items']['required']);
            $this->assertSame('integer', $lines['items']['properties']['y']['type']);
        } finally {
            if (is_file($solutionPath)) {
                @unlink($solutionPath);
            }
        }
    }

    public function testEnsureGridDrawsYellowStepWhenNoneIsFound(): void
    {
        $path = $this->writePng($this->solidImage(200, 150, 255, 255, 255));

        try {
            $method = new \ReflectionMethod(Pipeline::class, 'ensureGrid');
            $method->invoke(new Pipeline(), $path);

            $image = imagecreatefrompng($path);
            $this->assertNotFalse($image);
            $this->assertYellow($image, 0, 25);
            $this->assertYellow($image, 50, 25);
            $this->assertYellow($image, 100, 0);
            $this->assertYellow($image, 25, 50);
            $this->assertSame([255, 255, 255], $this->rgb($image, 25, 25));
            $this->assertSame([255, 255, 255], $this->rgb($image, 49, 25));
            imagedestroy($image);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function testEnsureGridLeavesAnExistingRulingUntouched(): void
    {
        $image = $this->solidImage(600, 800, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $this->assertNotFalse($black);
        for ($y = 40; $y < 800; $y += 40) {
            imageline($image, 0, $y, 599, $y, $black);
        }
        for ($x = 40; $x < 600; $x += 40) {
            imageline($image, $x, 0, $x, 799, $black);
        }
        $path = $this->writePng($image);

        try {
            $before = file_get_contents($path);
            $this->assertNotFalse($before);

            $method = new \ReflectionMethod(Pipeline::class, 'ensureGrid');
            $method->invoke(new Pipeline(), $path);

            $this->assertSame($before, file_get_contents($path));
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function solidImage(int $width, int $height, int $red, int $green, int $blue): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $color = imagecolorallocate($image, $red, $green, $blue);
        $this->assertNotFalse($color);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $color);

        return $image;
    }

    private function writePng(\GdImage $image): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cm2_grid_') . '.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function assertYellow(\GdImage $image, int $x, int $y): void
    {
        $this->assertSame([255, 255, 0], $this->rgb($image, $x, $y), "pixel $x,$y");
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function rgb(\GdImage $image, int $x, int $y): array
    {
        $rgba = imagecolorat($image, $x, $y);

        return [($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF];
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
