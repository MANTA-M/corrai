<?php

namespace Corrai\Llm\Eden;

use Corrai\Utils\JsonUtils;
use Corrai\Utils\RestClient;
use Corrai\Utils\Utils;
use Corrai\Utils\WSException;
use InvalidArgumentException;

/**
 * Google OCR through Eden AI.
 *
 * Equivalent request:
 *
 * curl -X POST "https://api.edenai.run/v3/universal-ai/" \
 *   -H "Authorization: Bearer $EDENAI_API_KEY" \
 *   -H "Content-Type: application/json" \
 *   -d '{"model":"ocr/ocr/google","input":{"file":"<file id or url>"},"show_original_response":false}'
 *
 * `input.file` is a file id from POST /v3/upload, or an http(s) URL Eden can fetch.
 * Docs: https://www.edenai.co/docs/v3/expert-models/features/ocr/ocr
 */
class GoogleOCRClient extends RestClient
{
    public const UNIVERSAL_AI_URL = 'https://api.edenai.run/v3/universal-ai/';
    public const UPLOAD_URL = 'https://api.edenai.run/v3/upload';
    public const MODEL = 'ocr/ocr/google';

    /** @var array<string, mixed> */
    protected array $payload = [
        'model' => self::MODEL,
        'input' => [],
        'show_original_response' => false,
    ];

    public function __construct()
    {
        parent::__construct('', $_ENV['EDENAI_API_KEY'] ?? '');
        $this->send_length = true;
        $this->verbose = true;
        $this->timeout = 180;
    }

    /**
     * Upload a local PDF or image and use the returned file id as input.file.
     */
    public function set_file(string $file_path, string $file_name): void
    {
        if (!is_readable($file_path)) {
            throw new \Exception("Cannot read file: $file_name");
        }

        $mime = strtolower(trim(explode(';', Utils::mimeTypeForFilename($file_name))[0]));
        if ($mime !== 'application/pdf' && !str_starts_with($mime, 'image/')) {
            throw new InvalidArgumentException("Unsupported OCR file type: $file_name");
        }

        $this->payload['input']['file'] = $this->upload($file_path, $file_name, $mime);
    }

    /**
     * File id from POST /v3/upload, or a URL Eden can fetch. This is input.file.
     */
    public function set_remote_file(string $file_id_or_url): void
    {
        $file = trim($file_id_or_url);
        if ($file === '') {
            throw new InvalidArgumentException('OCR file is required');
        }
        $this->payload['input']['file'] = $file;
    }

    /**
     * Optional language code. Google detects the language and usually ignores this.
     */
    public function set_language(string $language): void
    {
        $language = trim($language);
        if ($language === '') {
            throw new InvalidArgumentException('Language must not be empty');
        }
        $this->payload['input']['language'] = $language;
    }

    /**
     * Run OCR and return one word and its box for each recognized word.
     *
     * Eden wraps the provider result. The HTTP body looks like:
     *
     * {
     *   "status": "success",
     *   "cost": "0.0015",
     *   "provider": "google",
     *   "feature": "ocr",
     *   "subfeature": "ocr",
     *   "output": { ...this return value... },
     *   "error": null
     * }
     *
     * This method returns `output` (or the body itself when it is already that object):
     *
     * {
     *   "text": "Une makine... pluire",
     *   "bounding_boxes": [
     *     {
     *       "text": "Une",
     *       "left": 0.0435244161358811,
     *       "top": 0.040625,
     *       "width": 0.08067940552016985,
     *       "height": 0.025
     *     }
     *   ],
     *   "usage": null
     * }
     *
     * `text` is the full transcription, words in reading order.
     * `bounding_boxes` is one entry per word, in the same order. `usage` is often null;
     * do not use it for layout.
     *
     * Coordinates are fractions of the page, not pixels, each in [0, 1]:
     * - left: distance from the left edge, as a fraction of the page width
     * - top: distance from the top edge, as a fraction of the page height
     * - width: box width as a fraction of the page width
     * - height: box height as a fraction of the page height
     * Origin is the top-left corner. The box is axis-aligned.
     *
     * On an image or page of size (pageWidth, pageHeight) in pixels:
     *   x = left * pageWidth
     *   y = top * pageHeight
     *   w = width * pageWidth
     *   h = height * pageHeight
     *   pixel box [x1, y1, x2, y2] = [x, y, x + w, y + h]
     *
     * There is no page index. This is the synchronous single-document call
     * (model ocr/ocr/google). Treat every box as belonging to that one page.
     * A multi-page PDF needs Eden's async OCR instead.
     *
     * @return array{
     *   text: string,
     *   bounding_boxes: list<array{text: string, left: float, top: float, width: float, height: float}>,
     *   usage?: mixed
     * }
     */
    public function process(): array
    {
        $file = $this->payload['input']['file'] ?? null;
        if (!is_string($file) || $file === '') {
            throw new InvalidArgumentException('OCR file is required');
        }

        $response = $this->query_model();
        if ($response === null) {
            throw new \Exception('Empty OCR response');
        }
        if (($response['status'] ?? null) === 'fail') {
            throw new \Exception($this->failureMessage($response));
        }

        $output = isset($response['output']) && is_array($response['output'])
            ? $response['output']
            : $response;
        if (!isset($output['text']) || !is_string($output['text'])) {
            throw new \Exception('OCR response has no text');
        }
        if (!isset($output['bounding_boxes']) || !is_array($output['bounding_boxes'])) {
            throw new \Exception('OCR response has no bounding boxes');
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function failureMessage(array $response): string
    {
        $error = $response['error'] ?? null;
        if (is_string($error) && $error !== '') {
            return $error;
        }
        if (is_array($error)) {
            $detail = $error['message'] ?? $error['detail'] ?? null;
            if (is_string($detail) && $detail !== '') {
                return $detail;
            }
        }
        return 'OCR failed';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function query_model(): ?array
    {
        try {
            return $this->QueryArray(self::UNIVERSAL_AI_URL, 'POST', $this->common_headers, $this->payload);
        } catch (\Throwable $th) {
            if ((int) $th->getCode() === 402) {
                error_log('LLM Credit payment required');
                throw new WSException('AI error', 500, $th);
            }
            throw $th;
        }
    }

    private function upload(string $file_path, string $file_name, string $mime): string
    {
        $curl = curl_init(self::UPLOAD_URL);
        if ($curl === false) {
            throw new \Exception('Failed to start the Eden AI upload');
        }

        $opts = [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
            ],
            CURLOPT_POSTFIELDS => [
                'file' => new \CURLFile($file_path, $mime, $file_name),
                'purpose' => 'ocr',
                'expires_in_days' => 1,
            ],
        ];
        if ($this->timeout > 0) {
            $opts[CURLOPT_TIMEOUT] = $this->timeout;
        }
        if ($this->disableSslVerif) {
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
        }
        if ($this->verbose) {
            error_log('REST QUERY POST ' . self::UPLOAD_URL);
        }

        curl_setopt_array($curl, $opts);
        $response = curl_exec($curl);
        $responseCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($response === false) {
            $error = curl_error($curl) ?: 'Unknown error';
            curl_close($curl);
            throw new \Exception($error);
        }
        curl_close($curl);

        $body = is_string($response) ? $response : '';
        $decoded = JsonUtils::decodeArray($body);
        if ($responseCode < 200 || $responseCode > 299) {
            if ($responseCode === 402) {
                error_log('LLM Credit payment required');
                throw new WSException('AI error', 500);
            }
            throw new \Exception($this->uploadError($decoded, $body), $responseCode);
        }

        $fileId = is_array($decoded) ? ($decoded['file_id'] ?? null) : null;
        if (!is_string($fileId) || $fileId === '') {
            throw new \Exception('Eden AI upload did not return a file id');
        }
        return $fileId;
    }

    /**
     * @param array<string, mixed>|null $decoded
     */
    private function uploadError(?array $decoded, string $body): string
    {
        $detail = is_array($decoded) ? ($decoded['detail'] ?? null) : null;
        if (is_string($detail) && $detail !== '') {
            return $detail;
        }
        if (is_array($detail)) {
            $encoded = json_encode($detail);
            if (is_string($encoded) && $encoded !== '') {
                return $encoded;
            }
        }
        return $body !== '' ? $body : 'Eden AI upload failed';
    }
}
