<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Llm\Openrouter\ClaudeSonnetClient;
use Corrai\Llm\Openrouter\Gemini2FlashLiteClient;
use Corrai\Subject\Catalog;
use Corrai\Subject\DictationFranceGemini\CorrectingTask;
use Corrai\Utils\WSException;
use PHPUnit\Framework\TestCase;

class DictationFranceGeminiPipelineTest extends TestCase
{
    public function testPipelineIsSelectedForDictationFranceGemini(): void
    {
        $this->assertSame(
            CorrectingTask::class,
            Catalog::pipelineClass('Dictation', 'fr', 'Gemini')
        );
    }

    public function testStraightenRotatesAndScalesMainGridOntoTheStraightenedImage(): void
    {
        $path = $this->writeMarkedPng();
        $angleClient = new ScriptedGemini2FlashLiteClient('{"angle_degrees": 90}');
        $gridClient = new ScriptedGemini2FlashLiteClient(json_encode([
            'first_horizontal_y' => 10,
            'first_vertical_x' => 20,
            'vertical_step' => 15,
            'horizontal_step' => 25,
        ]));
        $claude = new GeminiCapturedClaudeSonnetClient();
        $pipeline = new TestableGeminiPipeline([$angleClient, $gridClient], $claude);

        try {
            $grid = $pipeline->measure($path);

            $image = imagecreatefrompng($path);
            $this->assertNotFalse($image);
            $this->assertSame(80, imagesx($image));
            $this->assertSame(200, imagesy($image));
            $this->assertSame([255, 0, 0], $this->rgb($image, 0, 0));
            imagedestroy($image);

            $this->assertSame(20, $grid['first_horizontal_y']);
            $this->assertSame(40, $grid['first_vertical_x']);
            $this->assertSame(30, $grid['vertical_step']);
            $this->assertSame(50, $grid['horizontal_step']);
            $this->assertSame(20, $pipeline->firstHorizontalY);
            $this->assertSame(40, $pipeline->firstVerticalX);
            $this->assertSame(30, $pipeline->verticalStep);
            $this->assertSame(50, $pipeline->horizontalStep);

            $this->assertStringContainsString('counter-clockwise', $angleClient->system);
            $this->assertSame('The attached image is 100 by 40 pixels. Answer in that pixel space.', $angleClient->texts[0]);
            $this->assertStringStartsWith('data:image/png;base64,', $angleClient->imageDataUrl());
            $this->assertSame(['angle_degrees'], $angleClient->responseFormat()['json_schema']['schema']['required']);

            $this->assertStringContainsString('thinner sub-lines', $gridClient->system);
            $this->assertStringContainsString('vertical pixel distance between two successive main horizontal lines', $gridClient->system);
            $this->assertStringContainsString('horizontal pixel distance between two successive main vertical lines', $gridClient->system);
            $this->assertSame('The attached image is 40 by 100 pixels. Answer in that pixel space.', $gridClient->texts[0]);
            $this->assertSame(
                ['first_horizontal_y', 'first_vertical_x', 'vertical_step', 'horizontal_step'],
                $gridClient->responseFormat()['json_schema']['schema']['required']
            );

            $assessment = $this->createMock(\Corrai\Model\Assessment::class);
            $assessment->method('instructionFilesText')->willReturn('');
            $pipeline->callFindErrors($assessment, $path, 'copy.png', $path, 'sol.png', 'French');
            $this->assertStringContainsString('first horizontal line y=20', $claude->systemContent());
            $this->assertStringContainsString('first vertical line x=40', $claude->systemContent());
            $this->assertStringContainsString('vertical step between main horizontal lines=30', $claude->systemContent());
            $this->assertStringContainsString('horizontal step between main vertical lines=50', $claude->systemContent());
            $this->assertStringContainsString('pixels of the straightened image', $claude->systemContent());
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function testZeroAngleKeepsTheImageAndStillScalesTheGrid(): void
    {
        $path = $this->writeMarkedPng();
        $before = file_get_contents($path);
        $this->assertNotFalse($before);

        $pipeline = new TestableGeminiPipeline([
            new ScriptedGemini2FlashLiteClient('{"angle_degrees": 0}'),
            new ScriptedGemini2FlashLiteClient('{"first_horizontal_y": 4, "first_vertical_x": 6, "vertical_step": 8, "horizontal_step": 10}'),
        ]);

        try {
            $grid = $pipeline->measure($path);
            $this->assertSame($before, file_get_contents($path));
            $this->assertSame(
                [
                    'first_horizontal_y' => 8,
                    'first_vertical_x' => 12,
                    'vertical_step' => 16,
                    'horizontal_step' => 20,
                ],
                $grid
            );
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function testUnusableAngleIsRejected(): void
    {
        $path = $this->writeMarkedPng();
        $pipeline = new TestableGeminiPipeline([
            new ScriptedGemini2FlashLiteClient('{"angle_degrees": 270}'),
        ]);

        try {
            $this->expectException(WSException::class);
            $pipeline->measure($path);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function writeMarkedPng(): string
    {
        $image = imagecreatetruecolor(200, 80);
        $this->assertNotFalse($image);
        $white = imagecolorallocate($image, 255, 255, 255);
        $red = imagecolorallocate($image, 255, 0, 0);
        $this->assertNotFalse($white);
        $this->assertNotFalse($red);
        imagefilledrectangle($image, 0, 0, 199, 79, $white);
        imagesetpixel($image, 199, 0, $red);

        $path = tempnam(sys_get_temp_dir(), 'gemini_dictation_') . '.png';
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

class TestableGeminiPipeline extends CorrectingTask
{
    /**
     * @param list<Gemini2FlashLiteClient> $geminiClients
     */
    public function __construct(
        private array $geminiClients,
        private ?ClaudeSonnetClient $claude = null,
    ) {
    }

    protected function createGeminiFlashClient(): Gemini2FlashLiteClient
    {
        $client = array_shift($this->geminiClients);
        if (!$client instanceof Gemini2FlashLiteClient) {
            throw new \RuntimeException('No Gemini client left');
        }

        return $client;
    }

    protected function createClaudeSonnetClient(): ClaudeSonnetClient
    {
        return $this->claude ?? parent::createClaudeSonnetClient();
    }

    protected function geminiMaxSide(): int
    {
        return 100;
    }

    /**
     * @return array{first_horizontal_y: int, first_vertical_x: int, vertical_step: int, horizontal_step: int}
     */
    public function measure(string $path): array
    {
        return $this->straightenAndMeasureGrid($path);
    }

    public function callFindErrors(
        \Corrai\Model\Assessment $assessment,
        string $copyPath,
        string $copyName,
        string $solutionPath,
        string $solutionName,
        string $languageName
    ): string {
        return $this->findErrors($assessment, $copyPath, $copyName, $solutionPath, $solutionName, $languageName);
    }
}

class ScriptedGemini2FlashLiteClient extends Gemini2FlashLiteClient
{
    public string $system = '';

    /** @var list<string> */
    public array $texts = [];

    public function __construct(private readonly string $response)
    {
        parent::__construct();
    }

    public function set_system_content(string $content): void
    {
        $this->system = $content;
        parent::set_system_content($content);
    }

    public function add_text(string $text): void
    {
        $this->texts[] = $text;
        parent::add_text($text);
    }

    public function call_text(): string
    {
        return $this->response;
    }

    public function imageDataUrl(): string
    {
        foreach ($this->payload['messages'] as $message) {
            if (($message['role'] ?? '') !== 'user' || !is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'image_url') {
                    return (string) ($part['image_url']['url'] ?? '');
                }
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function responseFormat(): array
    {
        return is_array($this->payload['response_format'] ?? null) ? $this->payload['response_format'] : [];
    }
}

class GeminiCapturedClaudeSonnetClient extends ClaudeSonnetClient
{
    public function systemContent(): string
    {
        foreach ($this->payload['messages'] as $message) {
            if (($message['role'] ?? '') === 'system') {
                return (string) $message['content'];
            }
        }

        return '';
    }

    public function call_text(): string
    {
        return '{}';
    }
}
