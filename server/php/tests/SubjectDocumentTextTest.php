<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Subject\SubjectDocumentText;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class SubjectDocumentTextTest extends TestCase
{
    public function testDocxAndOdtYieldParagraphText(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive required');
        }

        $docx = $this->zip('docx', 'word/document.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body><w:p><w:r><w:t>Contrôle de fractions</w:t></w:r></w:p></w:body>
</w:document>
XML);
        $odt = $this->zip('odt', 'content.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">
  <office:body><office:text><text:p>Dictée du mardi</text:p></office:text></office:body>
</office:document-content>
XML);

        $docxText = SubjectDocumentText::textFile($docx, 'sujet.docx');
        $odtText = SubjectDocumentText::textFile($odt, 'sujet.odt');
        $this->assertNotNull($docxText);
        $this->assertNotNull($odtText);
        $this->assertSame('Contrôle de fractions', file_get_contents($docxText));
        $this->assertSame('Dictée du mardi', file_get_contents($odtText));

        @unlink($docx);
        @unlink($odt);
        @unlink($docxText);
        @unlink($odtText);
    }

    public function testImageFilenameIsLeftToThePageRaster(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'subject_img_');
        $this->assertNotFalse($path);
        file_put_contents($path, 'not-an-image');
        $this->assertNull(SubjectDocumentText::textFile($path, 'sujet.png'));
        @unlink($path);
    }

    private function zip(string $suffix, string $entry, string $xml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'subject_' . $suffix . '_');
        $this->assertNotFalse($path);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
        $zip->addFromString($entry, $xml);
        $zip->close();
        return $path;
    }
}
