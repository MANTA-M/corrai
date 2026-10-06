<?php

namespace Corrai\Subject;

use Corrai\Llm\Eden\GoogleOCRClient;
use Corrai\Model\InputFile;

/**
 * Google OCR through Eden AI, one file per call.
 */
class GoogleSubjectImageOcr implements SubjectImageOcr
{
    public function recognize(string $path, string $filename, ?InputFile $file = null): array
    {
        $client = new GoogleOCRClient();
        $client->set_file($path, $filename, $file);
        return $client->process();
    }
}
