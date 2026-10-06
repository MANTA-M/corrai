<?php

namespace Corrai\Subject;

/**
 * Google OCR output for one image or one rendered page.
 */
interface SubjectImageOcr
{
    /**
     * @return array<string, mixed> Output of GoogleOCRClient::process().
     */
    public function recognize(string $path, string $filename): array;
}
