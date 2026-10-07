<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Clients\Google\Vision;
use Corrai\Model\InputFile;
use Corrai\Subject\GoogleSubjectImageOcr;
use Corrai\Subject\SubjectImageOcr;
use PHPUnit\Framework\TestCase;

class GoogleSubjectImageOcrTest extends TestCase
{
    public function testImplementsSubjectImageOcr(): void
    {
        $ocr = new GoogleSubjectImageOcr();
        $this->assertInstanceOf(SubjectImageOcr::class, $ocr);
    }

    public function testRecognizeDelegatesToVision(): void
    {
        $mockVision = $this->createMock(Vision::class);
        $expectedResult = [
            'text' => 'Sample OCR',
            'bounding_boxes' => [
                [
                    'text' => 'Sample',
                    'left' => 0.1,
                    'top' => 0.1,
                    'width' => 0.2,
                    'height' => 0.05,
                    'page' => 0,
                ],
            ],
            'usage' => null,
        ];

        $mockVision->expects($this->once())
            ->method('set_file')
            ->with('/tmp/sample.png', 'sample.png', null);

        $mockVision->expects($this->once())
            ->method('process')
            ->willReturn($expectedResult);

        $ocr = new GoogleSubjectImageOcr($mockVision);
        $result = $ocr->recognize('/tmp/sample.png', 'sample.png');

        $this->assertSame($expectedResult, $result);
    }

    public function testRecognizePassesInputFileToVision(): void
    {
        $mockVision = $this->createMock(Vision::class);
        $inputFile = $this->createMock(InputFile::class);

        $mockVision->expects($this->once())
            ->method('set_file')
            ->with('/tmp/sample.png', 'sample.png', $inputFile);

        $mockVision->expects($this->once())
            ->method('process')
            ->willReturn(['text' => 'ok', 'bounding_boxes' => [], 'usage' => null]);

        $ocr = new GoogleSubjectImageOcr($mockVision);
        $ocr->recognize('/tmp/sample.png', 'sample.png', $inputFile);
    }
}
