<?php

namespace Corrai\Llm\Openrouter;

use Corrai\Utils\Utils;

class Gemini15FlashClient extends OpenrouterClient
{
    public function __construct()
    {
        parent::__construct("google/gemini-2.5-flash");
    }

    /**
     * Add a file to the payload.
     * Images are sent as images. Other files keep the PDF data-url path.
     */
    public function add_file(string $file_path, string $file_name): void
    {
        $bytes = file_get_contents($file_path);
        if ($bytes === false) {
            throw new \Exception("Cannot read file: $file_name");
        }

        $mime = strtolower(trim(explode(';', Utils::mimeTypeForFilename($file_name))[0]));
        $user_content = &$this->get_user_content();
        if (str_starts_with($mime, 'image/')) {
            $user_content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)],
            ];
            return;
        }

        $user_content[] = [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:application/pdf;base64,' . base64_encode($bytes)],
        ];
    }
}
