<?php

namespace Corrai\Llm\Openrouter;

use Corrai\Utils\ImageRedimentioner;
use Corrai\Utils\Utils;

class ClaudeSonnetClient extends OpenrouterClient
{
    public const MAX_IMAGE_DIMENTION = 1568;
    public float $rescale = 1.0;
    public int $debug = 0;

    public function __construct(?string $model = null)
    {
        parent::__construct($model ?: 'anthropic/claude-3.7-sonnet');
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

        if (str_starts_with($mime, 'image/') || $this->imageMime($bytes) !== null) {
            $bytes = $this->imageBytesForPayload($bytes);
            $mime = $this->imageMime($bytes) ?? $mime;
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

    /**
     * Downscale raster image bytes when the longest side exceeds MAX_IMAGE_DIMENTION.
     */
    protected function imageBytesForPayload(string $bytes): string
    {
        $this->rescale = 1.0;
        if (!extension_loaded('gd')) {
            return $bytes;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return $bytes;
        }
        $redimentioner = new ImageRedimentioner(self::MAX_IMAGE_DIMENTION, $bytes);
        $this->rescale = $redimentioner->factor;
        return $redimentioner->image;
    }

    /**
     * MIME of the bytes that will be sent. The filename can stay .jpg after the copy is rewritten as PNG.
     */
    private function imageMime(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
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
