<?php

namespace Corrai\Subject;

use Corrai\Utils\Http\WSException;

/**
 * Plain-text excerpt of a subject stored as text, ODT, or DOCX.
 */
class SubjectDocumentText
{
    private const MAX_CHARS = 8000;

    /**
     * @return string|null Temporary text file, or null when the file is not a text document.
     */
    public static function textFile(string $sourcePath, string $filename): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['txt', 'text', 'md', 'odt', 'docx'], true)) {
            return null;
        }

        $text = match ($extension) {
            'odt' => self::officeXml($sourcePath, 'content.xml'),
            'docx' => self::officeXml($sourcePath, 'word/document.xml'),
            default => self::plain($sourcePath),
        };
        $text = trim($text);
        if ($text === '') {
            throw new WSException('Subject file has no readable text', 400);
        }
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS);
        }

        $path = tempnam(sys_get_temp_dir(), 'subject_text_');
        if ($path === false || file_put_contents($path, $text) === false) {
            throw new WSException('Cannot create a temporary file', 500);
        }

        return $path;
    }

    private static function plain(string $sourcePath): string
    {
        $bytes = file_get_contents($sourcePath);
        if ($bytes === false || $bytes === '') {
            throw new WSException('Cannot read subject file', 400);
        }
        if (str_contains($bytes, "\0")) {
            throw new WSException('Subject file must be text, ODT, DOCX, PDF, or an image', 400);
        }

        return $bytes;
    }

    private static function officeXml(string $sourcePath, string $entry): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new WSException('Cannot read the subject document', 500);
        }

        $zip = new \ZipArchive();
        if ($zip->open($sourcePath) !== true) {
            throw new WSException('Cannot read the subject document', 400);
        }
        $xml = $zip->getFromName($entry);
        $zip->close();
        if (!is_string($xml) || $xml === '') {
            throw new WSException('Cannot read the subject document', 400);
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded !== true) {
            throw new WSException('Cannot read the subject document', 400);
        }

        $xpath = new \DOMXPath($document);
        $paragraphs = $xpath->query('//*[local-name()="p" or local-name()="h"]');
        if ($paragraphs === false) {
            throw new WSException('Cannot read the subject document', 400);
        }

        $lines = [];
        foreach ($paragraphs as $paragraph) {
            $line = trim(preg_replace('/\s+/u', ' ', $paragraph->textContent) ?? '');
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }
}
