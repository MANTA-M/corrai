<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Llm\Eden\GoogleOCRClient;
use Corrai\Llm\Openrouter\ClaudeSonnetClient;
use Corrai\Model\OCRResult;
use Corrai\Subject\Catalog;
use Corrai\Subject\DictationFranceOCRGoogle\GoogleOcr;
use Corrai\Subject\DictationFranceOCRGoogle\Task1Correcting;
use PHPUnit\Framework\TestCase;

class DictationFranceOCRGoogleTest extends TestCase
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

    public function testPipelineIsSelectedForOcrGoogle(): void
    {
        $this->assertSame(
            Task1Correcting::class,
            Catalog::pipelineClass('Dictation', 'fr', 'OCRGoogle')
        );
    }

    public function testGoogleBoxesBecomePixelBoxesForTheModel(): void
    {
        $ocr = OCRResult::from_google([
            'text' => 'Une makine',
            'bounding_boxes' => [
                [
                    'text' => 'Une',
                    'left' => 0.1,
                    'top' => 0.2,
                    'width' => 0.05,
                    'height' => 0.025,
                ],
            ],
        ]);

        $task = new Task1Correcting();
        $method = new \ReflectionMethod(Task1Correcting::class, 'ocrWordsForPrompt');
        $method->setAccessible(true);
        $words = $method->invoke($task, $ocr, 1000, 800);

        $this->assertSame('Une', $words[0]['text']);
        $this->assertSame(0, $words[0]['page']);
        $this->assertSame([100, 160, 150, 180], $words[0]['box']);
    }

    public function testFindErrorsSendsPixelOcrWords(): void
    {
        $mockClient = new OcrGoogleCapturedClaudeSonnetClient();
        $task = new TestableOcrGoogleCorrectingTask($mockClient);

        $assessment = $this->createMock(\Corrai\Model\Assessment::class);
        $assessment->method('instructionFilesText')->willReturn('Assessment instructions');

        $task->findErrors(
            $assessment,
            $this->tempImagePath,
            'copy.png',
            'French',
            [['text' => 'makine', 'page' => 0, 'box' => [40, 50, 120, 90]]]
        );

        $promptText = $mockClient->sentPromptText();
        $this->assertStringContainsString('OCR words of the student copy:', $promptText);
        $this->assertStringContainsString('"box": [', $promptText);
        $this->assertStringContainsString('40', $promptText);
    }

    public function testGoogleOcrClientIsEdenGoogle(): void
    {
        $task = new GoogleOcr();
        $method = new \ReflectionMethod(GoogleOcr::class, 'createGoogleOCRClient');
        $method->setAccessible(true);
        $this->assertInstanceOf(GoogleOCRClient::class, $method->invoke($task));
        $this->assertSame('ocr/ocr/google', GoogleOCRClient::MODEL);
    }
}

class TestableOcrGoogleCorrectingTask extends Task1Correcting
{
    public function __construct(private readonly ClaudeSonnetClient $mockClient)
    {
    }

    protected function createClaudeSonnetClient(): ClaudeSonnetClient
    {
        return $this->mockClient;
    }
}

class OcrGoogleCapturedClaudeSonnetClient extends ClaudeSonnetClient
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

    public function call_text(): string
    {
        return '{"student_name":"","errors":[]}';
    }
}
