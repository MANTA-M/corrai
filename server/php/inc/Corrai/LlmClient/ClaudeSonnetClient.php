<?php

namespace Corrai\LlmClient;

use Corrai\Utils\Utils;

class ClaudeSonnetClient extends LlmClient
{
    public function __construct(?string $model = null)
    {
        parent::__construct($model ?: ($_ENV['OPENROUTER_MODEL'] ?? 'anthropic/claude-3.7-sonnet'));
    }

    /**
     * Add a file to the payload.
     * Images are sent as image_url data URLs. PDFs stay as file attachments.
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
            $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($bytes);
            $user_content[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]];
            return;
        }

        if ($mime !== 'application/pdf') {
            $user_content[] = ['type' => 'text', 'text' => $file_name . ":\n" . $bytes];
            return;
        }

        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        $user_content[] = [
            'type' => 'file',
            'file' => [
                'filename' => $file_name,
                'file_data' => $dataUrl,
            ],
        ];
    }
}
