<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Clients\Google\Vision;
use Corrai\Clients\Openrouter\ClaudeSonnetClient;
use Corrai\Model\OCRResult;
use Corrai\Model\Student;
use Corrai\Subject\Catalog;
use Corrai\Subject\DictationFranceOCRGoogle\Assessment;
use Corrai\Subject\DictationFranceOCRGoogle\Submission;
use Corrai\Subject\DictationFranceOCRGoogle\GoogleOcr;
use Corrai\Subject\DictationFranceOCRGoogle\Task1Correcting;
use Corrai\Subject\DictationFranceOCRGoogle\Task2Annotating;
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

    public function testFindErrorsSchemaAsksForTheGradeAndTheAppreciation(): void
    {
        $mockClient = new OcrGoogleCapturedClaudeSonnetClient();
        $task = new TestableOcrGoogleCorrectingTask($mockClient);

        $assessment = $this->createMock(\Corrai\Model\Assessment::class);
        $assessment->method('instructionFilesText')->willReturn('Assessment instructions');

        $task->findErrors($assessment, $this->tempImagePath, 'copy.png', 'French');

        $schema = $mockClient->responseFormat()['json_schema']['schema'];
        $this->assertSame(['student_name', 'errors', 'note', 'appreciation'], $schema['required']);
        $this->assertSame('number', $schema['properties']['note']['type']);
        $this->assertSame('string', $schema['properties']['appreciation']['type']);

        $system = $mockClient->systemContent();
        $this->assertStringContainsString('appreciation in Markdown', $system);
    }

    public function testStoreStudentResultKeepsTheMarkAndTheAppreciation(): void
    {
        $task = new TestableOcrGoogleCorrectingTask(new OcrGoogleCapturedClaudeSonnetClient());
        $student = $this->getMockBuilder(Student::class)->onlyMethods(['save'])->getMock();
        $student->expects($this->once())->method('save');

        $assessment = $this->createMock(Assessment::class);
        $assessment->expects($this->once())
            ->method('getStudent')
            ->with('st1')
            ->willReturn($student);

        $file = new Submission();
        $file->student = 'st1';

        $task->storeStudentResult($file, $assessment, json_encode([
            'note' => 15,
            'appreciation' => "Bien joué.\n\n**Quelques accords** à revoir.",
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(15.0, $student->mark);
        $this->assertSame("Bien joué.\n\n**Quelques accords** à revoir.", $student->appreciation);
    }

    public function testGdAnnotationsAsksToDrawTheGradeAndTheAppreciation(): void
    {
        $mockClient = new OcrGoogleCapturedClaudeSonnetClient();
        $task = new TestableOcrGoogleAnnotatingTask($mockClient);

        $task->gdAnnotations($this->tempImagePath, 'copy.png', json_encode([
            'note' => 15,
            'appreciation' => 'Bien joué.',
            'errors' => [],
        ], JSON_THROW_ON_ERROR));

        $system = $mockClient->systemContent();
        $this->assertStringContainsString('top right', $system);
        $this->assertStringContainsString('along the bottom', $system);
    }

    public function testAssignUnclassifiedCopyCreatesAnUnknownStudentWhenNoNameIsReadable(): void
    {
        $task = new TestableOcrGoogleCorrectingTask(new OcrGoogleCapturedClaudeSonnetClient());
        $student = new Student();
        $student->id = 'st-unknown';
        $student->name = 'Inconnu 1';

        $assessment = $this->createMock(Assessment::class);
        $assessment->expects($this->once())
            ->method('nextUnknownStudentName')
            ->willReturn('Inconnu 1');
        $assessment->expects($this->once())
            ->method('findOrCreateStudentByName')
            ->with('Inconnu 1')
            ->willReturn($student);

        $file = $this->createMock(Submission::class);
        $file->expects($this->once())->method('saveAttributes');
        $file->expects($this->once())
            ->method('appendEvent')
            ->with('Assigned to student Inconnu 1');

        $task->assignUnclassifiedCopy($file, $assessment, json_encode([
            'student_name' => '',
            'errors' => [],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('st-unknown', $file->student);
    }

    public function testGoogleOcrClientIsVision(): void
    {
        $task = new GoogleOcr();
        $method = new \ReflectionMethod(GoogleOcr::class, 'createVisionClient');
        $this->assertInstanceOf(Vision::class, $method->invoke($task));
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

    public function testSetFileAppendsEventWhenFileIsInputFileAndExceeds10Mb(): void
    {
        $largeFilePath = tempnam(sys_get_temp_dir(), 'large_img_') . '.png';
        $fp = fopen($largeFilePath, 'wb');
        $this->assertNotFalse($fp);
        fseek($fp, 10 * 1024 * 1024);
        fwrite($fp, "\0");
        fclose($fp);

        $file = $this->createMock(Submission::class);
        $file->name = 'scan.png';
        $file->expects($this->once())
            ->method('appendEvent')
            ->with('Image file too heavy');

        try {
            $client = new Vision();
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('Image file too heavy');
            $client->set_file($largeFilePath, 'scan.png', $file);
        } finally {
            @unlink($largeFilePath);
        }
    }

    public function testSetFileInputFileWithSizeOver10MbThrowsAndAppendsEvent(): void
    {
        $file = $this->createMock(Submission::class);
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

    public function testGoogleOcrRecognizeThrowsAndAppendsEventWhenFileExceeds10Mb(): void
    {
        $file = $this->createMock(Submission::class);
        $file->type = 'submission';
        $file->size = 11 * 1024 * 1024;
        $file->name = 'copy.png';
        $file->expects($this->once())
            ->method('appendEvent')
            ->with('Image file too heavy');

        $ocr = new GoogleOcr();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Image file too heavy');
        $ocr->recognize($file);
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

class TestableOcrGoogleAnnotatingTask extends Task2Annotating
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
        return '{"student_name":"","errors":[],"note":20,"appreciation":""}';
    }
}
