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

    /** Known centres on the 500×800 fixture: round(w/3), round(h/3), … */
    private const C1X = 167;
    private const C1Y = 267;
    private const C2X = 333;
    private const C2Y = 533;

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

    /**
     * @return list<array{x: float|int, y: float|int}>
     */
    private function identityCrosses(): array
    {
        return [
            ['x' => self::C1X, 'y' => self::C1Y],
            ['x' => self::C2X, 'y' => self::C2Y],
        ];
    }

    public function testGdDirectivesCalibratesFromMagentaCrossesAndMapsCoordinates(): void
    {
        // Model reports half-size centres → scale 2, offset 0
        $inputCorrection = json_encode([
            'magenta_crosses' => [
                ['x' => self::C1X / 2, 'y' => self::C1Y / 2],
                ['x' => self::C2X / 2, 'y' => self::C2Y / 2],
            ],
            'errors' => [
                [
                    'student' => 'avansse',
                    'expected' => 'avance',
                    'kind' => 'orthographe',
                    'box' => [
                        'x1' => 50,
                        'y1' => 60,
                        'x2' => 100,
                        'y2' => 120,
                    ],
                ],
            ],
        ]);

        $mockClient = new CapturedClaudeSonnetClient();
        $pipeline = new TestablePipeline($mockClient);

        $pipeline->callGdDirectives($this->tempImagePath, 'copy.png', $inputCorrection);

        $this->assertEqualsWithDelta(2.0, $pipeline->scaleX, 1e-9);
        $this->assertEqualsWithDelta(2.0, $pipeline->scaleY, 1e-9);
        $this->assertEqualsWithDelta(0.0, $pipeline->offsetX, 1e-9);
        $this->assertEqualsWithDelta(0.0, $pipeline->offsetY, 1e-9);

        $promptText = $mockClient->sentPromptText();
        $this->assertStringContainsString('Correction listing the errors to mark:', $promptText);

        $jsonStart = strpos($promptText, '{');
        $this->assertNotFalse($jsonStart);
        $sentJson = substr($promptText, $jsonStart);
        $data = json_decode($sentJson, true);
        $this->assertIsArray($data);

        $this->assertSame(self::C1X / 2, $data['magenta_crosses'][0]['x']);
        $this->assertSame(self::C1Y / 2, $data['magenta_crosses'][0]['y']);
        $this->assertSame(self::C2X / 2, $data['magenta_crosses'][1]['x']);
        $this->assertSame(self::C2Y / 2, $data['magenta_crosses'][1]['y']);

        $this->assertEqualsWithDelta(2.0, $data['calibration']['scale_x'], 1e-9);
        $this->assertEqualsWithDelta(2.0, $data['calibration']['scale_y'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $data['calibration']['offset_x'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $data['calibration']['offset_y'], 1e-9);

        $this->assertSame(99, $data['errors'][0]['box']['x1']);
        $this->assertSame(119, $data['errors'][0]['box']['y1']);
        $this->assertSame(199, $data['errors'][0]['box']['x2']);
        $this->assertSame(239, $data['errors'][0]['box']['y2']);
    }

    public function testExtractMagentaCalibrationAndAdjustWithOffset(): void
    {
        $pipeline = new Pipeline();
        $reflectionWidth = new \ReflectionProperty(Pipeline::class, 'imageWidth');
        $reflectionHeight = new \ReflectionProperty(Pipeline::class, 'imageHeight');
        $reflectionWidth->setValue($pipeline, 500);
        $reflectionHeight->setValue($pipeline, 800);

        // scaleX=2, offsetX=10 → M1.x = (167-10)/2 = 78.5; M2.x = (333-10)/2 = 161.5
        // scaleY=2, offsetY=20 → M1.y = (267-20)/2 = 123.5; M2.y = (533-20)/2 = 256.5
        $data = [
            'magenta_crosses' => [
                ['x' => 78.5, 'y' => 123.5],
                ['x' => 161.5, 'y' => 256.5],
            ],
            'errors' => [
                [
                    'box' => [
                        'x1' => 100, // 10 + 2*100 = 210
                        'y1' => 200, // 20 + 2*200 = 420
                        'x2' => 150, // 10 + 2*150 = 310
                        'y2' => 250, // 20 + 2*250 = 520
                    ],
                ],
            ],
        ];

        $pipeline->extractMagentaCalibration($data);
        $this->assertEqualsWithDelta(2.0, $pipeline->scaleX, 1e-9);
        $this->assertEqualsWithDelta(2.0, $pipeline->scaleY, 1e-9);
        $this->assertEqualsWithDelta(10.0, $pipeline->offsetX, 1e-9);
        $this->assertEqualsWithDelta(20.0, $pipeline->offsetY, 1e-9);

        $reflection = new \ReflectionMethod(Pipeline::class, 'adjustCoordinates');
        $adjusted = $reflection->invoke($pipeline, $data);

        $this->assertSame(210, $adjusted['errors'][0]['box']['x1']);
        $this->assertSame(420, $adjusted['errors'][0]['box']['y1']);
        $this->assertSame(310, $adjusted['errors'][0]['box']['x2']);
        $this->assertSame(520, $adjusted['errors'][0]['box']['y2']);
        $this->assertSame(78.5, $adjusted['magenta_crosses'][0]['x']);
    }

    public function testRenderErrorBoxesDrawsGreenRectanglesOnTheSourceImage(): void
    {
        $pipeline = new Pipeline();
        $method = new \ReflectionMethod(Pipeline::class, 'renderErrorBoxes');
        $png = $method->invoke($pipeline, $this->tempImagePath, [
            'errors' => [
                [
                    'student' => 'avansse',
                    'box' => [
                        'x1' => 40,
                        'y1' => 50,
                        'x2' => 120,
                        'y2' => 90,
                    ],
                ],
            ],
        ]);

        $this->assertIsString($png);
        $image = @imagecreatefromstring($png);
        $this->assertNotFalse($image);
        try {
            $this->assertSame([0, 200, 0], $this->rgb($image, 40, 50));
            $this->assertSame([0, 200, 0], $this->rgb($image, 120, 90));
            $this->assertSame([0, 0, 0], $this->rgb($image, 80, 70));
            $this->assertSame([0, 0, 0], $this->rgb($image, 10, 10));
        } finally {
            imagedestroy($image);
        }
    }

    public function testGdDirectivesThrowsExceptionOnInvalidJson(): void
    {
        $mockClient = new CapturedClaudeSonnetClient();
        $pipeline = new TestablePipeline($mockClient);

        $this->expectException(WSException::class);
        $this->expectExceptionMessage('Invalid correction JSON');
        $pipeline->callGdDirectives($this->tempImagePath, 'copy.png', 'Not a valid JSON');
    }

    public function testGdDirectivesThrowsWhenMagentaCrossesMissing(): void
    {
        $mockClient = new CapturedClaudeSonnetClient();
        $pipeline = new TestablePipeline($mockClient);

        $this->expectException(WSException::class);
        $this->expectExceptionMessage('Missing magenta cross coordinates');
        $pipeline->callGdDirectives($this->tempImagePath, 'copy.png', json_encode(['errors' => []]));
    }

    public function testCoordinatesPastTheImageAreLimitedToItsDimensions(): void
    {
        $inputCorrection = json_encode([
            'magenta_crosses' => $this->identityCrosses(),
            'errors' => [
                [
                    'box' => [
                        'x1' => 200,
                        'y1' => 300,
                        'x2' => 2000,
                        'y2' => 3000,
                    ],
                ],
            ],
        ]);

        $mockClient = new CapturedClaudeSonnetClient();
        $pipeline = new TestablePipeline($mockClient);

        $log = tempnam(sys_get_temp_dir(), 'coord_log_');
        $previousLog = ini_set('error_log', $log);
        try {
            $pipeline->callGdDirectives($this->tempImagePath, 'copy.png', $inputCorrection);
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
    }

    public function testFindErrorsSchemaUsesMagentaCrosses(): void
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
            $this->assertArrayNotHasKey('cropped_image', $schema['properties']);
            $this->assertContains('magenta_crosses', $schema['required']);
            $this->assertNotContains('lines', $schema['required']);
            $this->assertArrayNotHasKey('lines', $schema['properties']);

            $crosses = $schema['properties']['magenta_crosses'];
            $this->assertSame(2, $crosses['minItems']);
            $this->assertSame(2, $crosses['maxItems']);
            $this->assertSame(['x', 'y'], $crosses['items']['required']);

            $system = $mockClient->systemContent();
            $this->assertStringContainsString('origin (0,0) is top-left', strtolower($system));
        } finally {
            if (is_file($solutionPath)) {
                @unlink($solutionPath);
            }
        }
    }

    public function testDrawMagentaCrossesAtOneThirdAndTwoThirds(): void
    {
        $path = $this->writePng($this->solidImage(500, 800, 255, 255, 255));

        try {
            $method = new \ReflectionMethod(Pipeline::class, 'drawMagentaCrosses');
            $method->invoke(new Pipeline(), $path, [
                [self::C1X, self::C1Y],
                [self::C2X, self::C2Y],
            ]);

            $image = imagecreatefrompng($path);
            $this->assertNotFalse($image);

            $half = max(7, (int) round(500 * 0.02));

            $this->assertSame([255, 0, 255], $this->rgb($image, self::C1X, self::C1Y));
            $this->assertSame([255, 0, 255], $this->rgb($image, self::C1X + $half, self::C1Y));
            $this->assertSame([255, 0, 255], $this->rgb($image, self::C1X + $half, self::C1Y + 1));
            $this->assertSame([255, 255, 255], $this->rgb($image, self::C1X + $half, self::C1Y - 1));
            $this->assertSame([255, 255, 255], $this->rgb($image, self::C1X + $half, self::C1Y + 2));
            $this->assertSame([255, 0, 255], $this->rgb($image, self::C1X, self::C1Y - $half));
            $this->assertSame([255, 0, 255], $this->rgb($image, self::C1X + 1, self::C1Y - $half));
            $this->assertSame([255, 255, 255], $this->rgb($image, self::C1X - 1, self::C1Y - $half));
            $this->assertSame([255, 255, 255], $this->rgb($image, self::C1X + 2, self::C1Y - $half));

            $this->assertSame([255, 0, 255], $this->rgb($image, self::C2X, self::C2Y));
            $this->assertSame([255, 255, 255], $this->rgb($image, self::C1X - $half - 1, self::C1Y));
            $this->assertSame([255, 255, 255], $this->rgb($image, 10, 10));

            imagedestroy($image);
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
        $path = tempnam(sys_get_temp_dir(), 'cm2_marked_') . '.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
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
