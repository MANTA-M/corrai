<?php

namespace Corrai\Clients\Openrouter;

use Corrai\Utils\Utils;

class GeminiFlashLiteClient extends OpenrouterClient
{
    public function __construct()
    {
        parent::__construct("google/gemini-3.1-flash-lite");
    }

    /**
     * Add a file to the payload.
     * Vision requests need an image MIME inside image_url. A PDF data URI in
     * that block is parsed as a document and rejected with "The document has no pages."
     */
    public function add_file(string $file_path, string $file_name): void
    {
        $bytes = file_get_contents($file_path);
        if ($bytes === false || $bytes === '') {
            throw new \Exception("Cannot read file: $file_name");
        }

        $mime = strtolower(trim(explode(';', Utils::mimeTypeForFilename($file_name))[0]));
        $imageMime = $this->imageMime($bytes, $mime);
        $user_content = &$this->get_user_content();

        if ($imageMime !== null) {
            $encoded = base64_encode($bytes);
            $decoded = base64_decode($encoded, true);
            if ($decoded !== $bytes) {
                throw new \Exception("Incomplete image payload: $file_name");
            }
            $user_content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:' . $imageMime . ';base64,' . $encoded],
            ];
            return;
        }

        if ($mime === 'application/pdf' || str_starts_with($bytes, '%PDF')) {
            throw new \Exception(
                'Gemini Flash Lite image_url accepts images only. Render the PDF page to JPEG or PNG before sending.'
            );
        }

        $user_content[] = ['type' => 'text', 'text' => $file_name . ":\n" . $bytes];
    }

    /**
     * Image MIME for a complete raster payload, or null when the bytes are not an image.
     * Filename wins when it already names an image type; otherwise the bytes are sniffed
     * so a mislabeled PDF name cannot hide a JPEG or PNG.
     */
    private function imageMime(string $bytes, string $filenameMime): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if (str_starts_with($filenameMime, 'image/')) {
            if ($info === false) {
                throw new \Exception('Image payload is empty or corrupted');
            }
            return $filenameMime;
        }

        if ($info === false) {
            return null;
        }

        $detected = image_type_to_mime_type((int) $info[2]);
        if (!is_string($detected) || !str_starts_with($detected, 'image/')) {
            return null;
        }

        return $detected;
    }
}
