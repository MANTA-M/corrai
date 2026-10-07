<?php

namespace Corrai\Subject;

use Corrai\Clients\Google\Vision;
use Corrai\Model\InputFile;

/**
 * Google OCR through direct Google Vision, one file per call.
 */
class GoogleSubjectImageOcr implements SubjectImageOcr
{
    private ?Vision $client;

    public function __construct(?Vision $client = null)
    {
        $this->client = $client;
    }

    public function recognize(string $path, string $filename, ?InputFile $file = null): array
    {
        $client = $this->client ?? new Vision();
        $client->set_file($path, $filename, $file);
        return $client->process();
    }
}
