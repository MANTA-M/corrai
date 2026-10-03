<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Llm\Openrouter\ClaudeSonnetClient;
use Corrai\Subject\DictationFranceCM2\Task1Correcting;
use Corrai\Subject\DictationFranceCM2\Task2Annotating;
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

    public function testGdAnnotationsForwardsTheErrorBoxesToTheModel(): void
    {
        $inputCorrection = json_encode([
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
        $task = new TestableAnnotatingTask($mockClient);

        $task->gdAnnotations($this->tempImagePath, 'copy.png', $inputCorrection);

        $promptText = $mockClient->sentPromptText();
        $this->assertStringContainsString('Correction listing the errors to mark:', $promptText);

        $jsonStart = strpos($promptText, '{');
        $this->assertNotFalse($jsonStart);
        $sentJson = substr($promptText, $jsonStart);
        $data = json_decode($sentJson, true);
        $this->assertIsArray($data);

        $this->assertSame('avansse', $data['errors'][0]['student']);
        $this->assertSame(50, $data['errors'][0]['box']['x1']);
        $this->assertSame(60, $data['errors'][0]['box']['y1']);
        $this->assertSame(100, $data['errors'][0]['box']['x2']);
        $this->assertSame(120, $data['errors'][0]['box']['y2']);
    }

    public function testRenderOcrBoxesDrawsGreenRectanglesOnTheSourceImage(): void
    {
        $pipeline = new Pipeline();
        $method = new \ReflectionMethod(Pipeline::class, 'renderOcrBoxes');
        $png = $method->invoke($pipeline, $this->tempImagePath, [
            ['text' => 'avansse', 'page' => 0, 'box' => [40, 50, 120, 90]],
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

    public function testRenderOcrBoxesDrawsErrorBoxesInRed(): void
    {
        $pipeline = new Pipeline();
        $method = new \ReflectionMethod(Pipeline::class, 'renderOcrBoxes');
        $png = $method->invoke($pipeline, $this->tempImagePath, [
            ['text' => 'avansse', 'page' => 0, 'box' => [40, 50, 120, 90]],
            ['text' => 'et', 'page' => 0, 'box' => [140, 50, 180, 90]],
        ], [
            [40, 50, 120, 90],
        ]);

        $this->assertIsString($png);
        $image = @imagecreatefromstring($png);
        $this->assertNotFalse($image);
        try {
            $this->assertSame([200, 0, 0], $this->rgb($image, 40, 50));
            $this->assertSame([200, 0, 0], $this->rgb($image, 120, 90));
            $this->assertSame([0, 200, 0], $this->rgb($image, 140, 50));
            $this->assertSame([0, 200, 0], $this->rgb($image, 180, 90));
        } finally {
            imagedestroy($image);
        }
    }

    public function testGdAnnotationsThrowsExceptionOnInvalidJson(): void
    {
        $mockClient = new CapturedClaudeSonnetClient();
        $task = new TestableAnnotatingTask($mockClient);

        $this->expectException(WSException::class);
        $this->expectExceptionMessage('Invalid correction JSON');
        $task->gdAnnotations($this->tempImagePath, 'copy.png', 'Not a valid JSON');
    }

    public function testCoordinatesPastTheImageAreLimitedToItsDimensions(): void
    {
        $inputCorrection = json_encode([
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
        $task = new TestableAnnotatingTask($mockClient);

        $log = tempnam(sys_get_temp_dir(), 'coord_log_');
        $previousLog = ini_set('error_log', $log);
        try {
            $task->gdAnnotations($this->tempImagePath, 'copy.png', $inputCorrection);
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

    public function testFindErrorsSchemaAsksOnlyForErrors(): void
    {
        $mockClient = new CapturedClaudeSonnetClient();
        $task = new TestableCorrectingTask($mockClient);

        $assessment = $this->createMock(\Corrai\Model\Assessment::class);
        $assessment->method('instructionFilesText')->willReturn('Assessment instructions');

        $solutionPath = tempnam(sys_get_temp_dir(), 'test_sol_') . '.png';
        copy($this->tempImagePath, $solutionPath);

        try {
            $task->findErrors($assessment, $this->tempImagePath, 'copy.png', $solutionPath, 'sol.png', 'French');

            $schema = $mockClient->responseFormat()['json_schema']['schema'];
            $this->assertSame(['errors'], $schema['required']);
            $this->assertSame(['errors'], array_keys($schema['properties']));
            $this->assertSame(
                ['student', 'expected', 'kind', 'box'],
                $schema['properties']['errors']['items']['required']
            );

            $system = $mockClient->systemContent();
            $this->assertStringContainsString('origin (0,0) is top-left', strtolower($system));
            $this->assertStringNotContainsStringIgnoringCase('magenta', $system);
        } finally {
            if (is_file($solutionPath)) {
                @unlink($solutionPath);
            }
        }
    }

    public function testFindErrorsSendsTheOcrWordsToTheModel(): void
    {
        $mockClient = new CapturedClaudeSonnetClient();
        $task = new TestableCorrectingTask($mockClient);

        $assessment = $this->createMock(\Corrai\Model\Assessment::class);
        $assessment->method('instructionFilesText')->willReturn('Assessment instructions');

        $solutionPath = tempnam(sys_get_temp_dir(), 'test_sol_') . '.png';
        copy($this->tempImagePath, $solutionPath);

        try {
            $task->findErrors(
                $assessment,
                $this->tempImagePath,
                'copy.png',
                $solutionPath,
                'sol.png',
                'French',
                [['text' => 'avansse', 'page' => 0, 'box' => [40, 50, 120, 90]]]
            );
        } finally {
            if (is_file($solutionPath)) {
                @unlink($solutionPath);
            }
        }

        $promptText = $mockClient->sentPromptText();
        $this->assertStringContainsString('OCR words of the student copy:', $promptText);

        $jsonStart = strpos($promptText, '[');
        $this->assertNotFalse($jsonStart);
        $words = json_decode(substr($promptText, $jsonStart), true);
        $this->assertIsArray($words);
        $this->assertSame('avansse', $words[0]['text']);
        $this->assertSame([40, 50, 120, 90], $words[0]['box']);

        $system = $mockClient->systemContent();
        $this->assertStringContainsString('reuse its box as is', $system);
        $this->assertStringContainsString('no OCR word matches', $system);
    }

    public function testFindErrorsSendsEverySubjectFile(): void
    {
        $mockClient = new CapturedClaudeSonnetClient();
        $task = new TestableCorrectingTask($mockClient);

        $assessment = $this->createMock(\Corrai\Model\Assessment::class);
        $assessment->method('instructionFilesText')->willReturn('Assessment instructions');

        $solutionPath = tempnam(sys_get_temp_dir(), 'test_sol_') . '.png';
        copy($this->tempImagePath, $solutionPath);
        $subjectPath = tempnam(sys_get_temp_dir(), 'test_subject_');
        file_put_contents($subjectPath, "Le texte de la dictée.");

        try {
            $task->findErrors(
                $assessment,
                $this->tempImagePath,
                'copy.png',
                $solutionPath,
                'sol.png',
                'French',
                [],
                [['path' => $subjectPath, 'name' => 'dictee.txt']]
            );
        } finally {
            if (is_file($solutionPath)) {
                @unlink($solutionPath);
            }
            if (is_file($subjectPath)) {
                @unlink($subjectPath);
            }
        }

        $promptText = $mockClient->sentPromptText();
        $this->assertStringContainsString('dictee.txt:', $promptText);
        $this->assertStringContainsString('Le texte de la dictée.', $mockClient->userText());
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

class TestableCorrectingTask extends Task1Correcting
{
    public function __construct(private readonly ClaudeSonnetClient $mockClient)
    {
    }

    protected function createClaudeSonnetClient(): ClaudeSonnetClient
    {
        return $this->mockClient;
    }
}

class TestableAnnotatingTask extends Task2Annotating
{
    public function __construct(private readonly ClaudeSonnetClient $mockClient)
    {
    }

    protected function createClaudeSonnetClient(): ClaudeSonnetClient
    {
        return $this->mockClient;
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

    public function userText(): string
    {
        $text = '';
        foreach ($this->payload['messages'] as $message) {
            if (($message['role'] ?? '') !== 'user' || !is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text') {
                    $text .= (string) ($part['text'] ?? '') . "\n";
                }
            }
        }

        return $text;
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
        return '<?php $GD_annotations = [];';
    }
}
