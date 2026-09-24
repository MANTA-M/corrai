<?php

namespace Corrai\LlmClient;

use Corrai\Utils\Utils;

class Gemini3Client extends LlmClient
{
    public function __construct(?string $model = null)
    {
        parent::__construct($model ?: ($_ENV['OPENROUTER_IMAGE_MODEL'] ?? 'google/gemini-2.5-flash-image'));
    }

    /**
     * Add a submission file to the user message.
     * Images are sent as images. PDFs are sent as files and parsed into page
     * images first: the image model rejects a raw PDF with "The document has no pages."
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

    /**
     * Force a parser that turns PDF pages into images.
     * The default native path forwards the PDF to Google AI Studio, which rejects it.
     */
    private function enablePdfParser(): void
    {
        foreach ($this->payload['plugins'] ?? [] as $plugin) {
            if (($plugin['id'] ?? '') === 'file-parser') {
                return;
            }
        }
        $this->payload['plugins'][] = [
            'id' => 'file-parser',
            'pdf' => ['engine' => 'mistral-ocr'],
        ];
    }
}
