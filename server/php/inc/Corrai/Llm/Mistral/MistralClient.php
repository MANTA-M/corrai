<?php

namespace Corrai\Llm\Mistral;

use Corrai\Utils\ImageRedimentioner;
use Corrai\Utils\JsonUtils;
use Corrai\Utils\RestClient;
use Corrai\Utils\Utils;
use Corrai\Utils\WSException;

/**
 * Direct Mistral chat client with the same call surface as OpenrouterClient.
 * Posts to https://api.mistral.ai/v1/chat/completions using MISTRAL_API_KEY.
 */
class MistralClient extends RestClient
{
    public const MISTRAL_API_URL = 'https://api.mistral.ai/v1/chat/completions';
    public const MAX_IMAGE_DIMENTION = 1568;

    public float $rescale = 1.0;

    protected array $payload = [
        'model' => '',
        'messages' => [],
    ];

    public function __construct(?string $model = null)
    {
        parent::__construct('', $_ENV['MISTRAL_API_KEY'] ?? '');
        $this->send_length = true;
        $this->verbose = true;
        $this->timeout = 180;
        $this->payload['model'] = $model
            ?: ($_ENV['MISTRAL_MODEL'] ?? 'mistral-large-latest');
    }

    protected function &get_user_content(): array
    {
        foreach ($this->payload['messages'] as &$message) {
            if ($message['role'] === 'user') {
                return $message['content'];
            }
        }
        unset($message);
        $user_content = [];
        $this->payload['messages'][] = [
            'role' => 'user',
            'content' => $user_content,
        ];
        $last_index = count($this->payload['messages']) - 1;
        return $this->payload['messages'][$last_index]['content'];
    }

    public function set_system_content(string $content): void
    {
        foreach ($this->payload['messages'] as &$message) {
            if ($message['role'] === 'system') {
                $message['content'] = $content;
                return;
            }
        }
        unset($message);
        $this->payload['messages'][] = [
            'role' => 'system',
            'content' => $content,
        ];
    }

    public function add_text(string $text): void
    {
        $user_content = &$this->get_user_content();
        $user_content[] = ['type' => 'text', 'text' => $text];
    }

    /**
     * Add a file to the user message as a Mistral content chunk.
     * Images use ImageURLChunk; PDFs use DocumentURLChunk; other files become text.
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
            'type' => 'document_url',
            'document_url' => $dataUrl,
            'document_name' => $file_name,
        ];
    }

    /**
     * Enable image generation via Mistral's image_generation tool.
     */
    public function enable_image_output(): void
    {
        $tools = is_array($this->payload['tools'] ?? null) ? $this->payload['tools'] : [];
        foreach ($tools as $tool) {
            if (($tool['type'] ?? '') === 'image_generation') {
                return;
            }
        }
        $tools[] = ['type' => 'image_generation'];
        $this->payload['tools'] = $tools;
    }

    /**
     * Require JSON that matches $schema (Mistral ResponseFormat json_schema).
     *
     * @param array<string, mixed> $schema
     */
    public function set_json_response(string $name, array $schema): void
    {
        $this->payload['response_format'] = [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $name,
                'strict' => true,
                'schema' => $schema,
            ],
        ];
    }

    /**
     * Call Mistral and return the assistant text (no JSON parsing).
     */
    public function call_text(): string
    {
        $response = $this->query_model();
        if ($response === null) {
            throw new \Exception('Empty response from model');
        }

        $message = $response['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            throw new \Exception('Invalid model response');
        }

        $text = self::extract_message_text($message['content'] ?? '');
        if (trim($text) === '') {
            throw new \Exception('Empty text response from model');
        }
        return $text;
    }

    /**
     * Call Mistral and return assistant text plus generated/annotated images.
     *
     * @return array{text: string, images: list<array{mime: string, body: string}>}
     */
    public function call_annotation(): array
    {
        $response = $this->query_model();
        if ($response === null) {
            throw new \Exception('Empty response from model');
        }

        $message = $response['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            throw new \Exception('Invalid model response');
        }

        $text = self::extract_message_text($message['content'] ?? '');
        $images = [];
        foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $part) {
            if (!is_array($part) || ($part['type'] ?? '') !== 'image_url') {
                continue;
            }
            $url = '';
            if (is_array($part['image_url'] ?? null)) {
                $url = $part['image_url']['url'] ?? '';
            } elseif (is_string($part['image_url'] ?? null)) {
                $url = $part['image_url'];
            }
            $decoded = self::decode_data_url((string) $url);
            if ($decoded !== null) {
                $images[] = $decoded;
            }
        }

        return ['text' => $text, 'images' => $images];
    }

    public function call(): array
    {
        try {
            $respone = $this->query_model();
            $json_content = $respone['choices'][0]['message']['content'];
            if (is_array($json_content)) {
                $json_content = self::extract_message_text($json_content);
            }
            $response = JsonUtils::decodeArray(str_replace('json', '', str_replace('```', '', (string) $json_content)));

            if ($response === null) {
                throw new \Exception('Invalid JSON content: ' . $json_content);
            }
            return ['model' => $this->payload['model'], 'response' => $response, 'payload' => $this->payload, 'respone' => $respone];
        } catch (WSException $e) {
            throw $e;
        } catch (\Throwable $th) {
            return ['model' => $this->payload['model'], 'response' => $th->getMessage(), 'payload' => $this->payload, 'respone' => null];
        }
    }

    /**
     * Mistral 402 means the account has no credit. Log that and surface a generic AI error.
     */
    private function query_model(): ?array
    {
        try {
            return $this->QueryArray(self::MISTRAL_API_URL, 'POST', $this->common_headers, $this->payload);
        } catch (\Throwable $th) {
            if ((int) $th->getCode() === 402) {
                error_log('LLM Credit payment required');
                throw new WSException('AI error', 500, $th);
            }
            throw $th;
        }
    }

    private static function extract_message_text(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
                continue;
            }
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $parts[] = $part['text'];
            }
        }
        return implode("\n", $parts);
    }

    /**
     * @return array{mime: string, body: string}|null
     */
    private static function decode_data_url(string $url): ?array
    {
        if ($url === '' || !preg_match('#^data:([^;]+);base64,(.+)$#s', $url, $matches)) {
            return null;
        }
        $body = base64_decode($matches[2], true);
        if ($body === false) {
            return null;
        }
        return ['mime' => $matches[1], 'body' => $body];
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
