<?php

namespace Corrai\LlmClient;

use Corrai\Utils;

class DeepSeekV32Client extends LlmClient
{
    public function __construct(?string $model = null)
    {
        parent::__construct($model ?: 'deepseek/deepseek-v3.2');
    }

    /**
     * PDFs go through OpenRouter file-parser (text extraction).
     * Images are sent as image_url; other files as UTF-8 text.
     */
    public function add_file(string $file_path, string $file_name): void
    {
        $bytes = file_get_contents($file_path);
        if ($bytes === false) {
            throw new \Exception("Cannot read file: $file_name");
        }

        $mime = strtolower(trim(explode(';', Utils::mimeTypeForFilename($file_name))[0]));
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        $user_content = &$this->get_user_content();

        if ($mime === 'application/pdf') {
            $this->enablePdfParser();
            $user_content[] = [
                'type' => 'file',
                'file' => [
                    'filename' => $file_name,
                    'file_data' => $dataUrl,
                ],
            ];
            return;
        }

        if (str_starts_with($mime, 'image/')) {
            $user_content[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]];
            return;
        }

        $user_content[] = ['type' => 'text', 'text' => $file_name . ":\n" . $bytes];
    }

    private function enablePdfParser(): void
    {
        foreach ($this->payload['plugins'] ?? [] as $plugin) {
            if (($plugin['id'] ?? '') === 'file-parser') {
                return;
            }
        }
        $this->payload['plugins'][] = [
            'id' => 'file-parser',
            'pdf' => ['engine' => 'pdf-text'],
        ];
    }
}
