<?php

namespace Corrai\Clients\Mistral;

use Corrai\Clients\LlmClient;
use Corrai\Utils\Image\ImageRedimentioner;
use Corrai\Utils\Utils;
use Corrai\Utils\Http\WSException;
use InvalidArgumentException;

/**
 * Direct Mistral OCR client.
 * Posts to https://api.mistral.ai/v1/ocr using MISTRAL_API_KEY.
 */
class MistralOcrClient extends LlmClient
{
    public const MISTRAL_OCR_URL = 'https://api.mistral.ai/v1/ocr';
    public const MAX_IMAGE_DIMENTION = 1568;

    public float $rescale = 1.0;

    /** @var array<string, mixed> */
    protected array $payload = [
        'model' => '',
    ];

    public function __construct(?string $model = null)
    {
        parent::__construct('', $_ENV['MISTRAL_API_KEY'] ?? '');
        $this->payload['model'] = $model
            ?: ($_ENV['MISTRAL_OCR_MODEL'] ?? 'mistral-ocr-latest');
    }

    /**
     * Local PDF or image. PDFs use DocumentURLChunk; images use ImageURLChunk.
     */
    public function set_file(string $file_path, string $file_name): void
    {
        $bytes = file_get_contents($file_path);
        if ($bytes === false) {
            throw new \Exception("Cannot read file: $file_name");
        }

        $mime = strtolower(trim(explode(';', Utils::mimeTypeForFilename($file_name))[0]));

        if (str_starts_with($mime, 'image/') || $this->imageMime($bytes) !== null) {
            $bytes = $this->imageBytesForPayload($bytes);
            $mime = $this->imageMime($bytes) ?? $mime;
            $this->payload['document'] = [
                'type' => 'image_url',
                'image_url' => 'data:' . $mime . ';base64,' . base64_encode($bytes),
            ];
            return;
        }

        if ($mime !== 'application/pdf') {
            throw new InvalidArgumentException("Unsupported OCR file type: $file_name");
        }

        $this->payload['document'] = [
            'type' => 'document_url',
            'document_url' => 'data:' . $mime . ';base64,' . base64_encode($bytes),
            'document_name' => $file_name,
        ];
    }

    public function set_document_url(string $url, ?string $name = null): void
    {
        $document = [
            'type' => 'document_url',
            'document_url' => $url,
        ];
        if ($name !== null && $name !== '') {
            $document['document_name'] = $name;
        }
        $this->payload['document'] = $document;
    }

    public function set_image_url(string $url): void
    {
        $this->payload['document'] = [
            'type' => 'image_url',
            'image_url' => $url,
        ];
    }

    public function set_file_id(string $file_id): void
    {
        $this->payload['document'] = [
            'type' => 'file',
            'file_id' => $file_id,
        ];
    }

    /**
     * @param string|list<int> $pages
     */
    public function set_pages(string|array $pages): void
    {
        if (is_array($pages)) {
            foreach ($pages as $page) {
                if (!is_int($page)) {
                    throw new InvalidArgumentException('Page numbers must be integers');
                }
            }
        } elseif (trim($pages) === '') {
            throw new InvalidArgumentException('Pages must not be empty');
        }
        $this->payload['pages'] = $pages;
    }

    public function set_include_image_base64(bool $include): void
    {
        $this->payload['include_image_base64'] = $include;
    }

    public function set_include_blocks(bool $include): void
    {
        $this->payload['include_blocks'] = $include;
    }

    public function set_extract_header(bool $extract): void
    {
        $this->payload['extract_header'] = $extract;
    }

    public function set_extract_footer(bool $extract): void
    {
        $this->payload['extract_footer'] = $extract;
    }

    public function set_table_format(string $format): void
    {
        if ($format !== 'markdown' && $format !== 'html') {
            throw new InvalidArgumentException('Table format must be markdown or html');
        }
        $this->payload['table_format'] = $format;
    }

    public function set_image_limit(int $limit): void
    {
        $this->payload['image_limit'] = $limit;
    }

    public function set_image_min_size(int $size): void
    {
        $this->payload['image_min_size'] = $size;
    }

    public function set_confidence_scores_granularity(string $granularity): void
    {
        if (!in_array($granularity, ['word', 'page', 'block'], true)) {
            throw new InvalidArgumentException('Confidence granularity must be word, page, or block');
        }
        $this->payload['confidence_scores_granularity'] = $granularity;
    }

    /**
     * Call Mistral OCR and return the decoded OCRResponse.
     *
     * @return array<string, mixed>
     */
    public function process(): array
    {
        if (!isset($this->payload['document']) || !is_array($this->payload['document'])) {
            throw new InvalidArgumentException('OCR document is required');
        }

        $response = $this->query_model();
        if ($response === null || !isset($response['pages']) || !is_array($response['pages']) || $response['pages'] === []) {
            throw new \Exception('Empty OCR response');
        }

        return $response;
    }

    /**
     * Mistral 402 means the account has no credit. Log that and surface a generic AI error.
     *
     * @return array<string, mixed>|null
     */
    private function query_model(): ?array
    {
        try {
            return $this->QueryArray(self::MISTRAL_OCR_URL, 'POST', $this->common_headers, $this->payload);
        } catch (\Throwable $th) {
            if ((int) $th->getCode() === 402) {
                error_log('LLM Credit payment required');
                throw new WSException('AI error', 500, $th);
            }
            throw $th;
        }
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
