<?php

namespace Corrai\LlmClient;

use Corrai\JsonUtils;
use Corrai\RestClient;

abstract class LlmClient extends RestClient
{
    const OPENROUTER_API_URL = "https://openrouter.ai/api/v1/chat/completions";

    protected array $payload = [
        "model" => "",
        "messages" => []
    ];

    protected function __construct(string $model)
    {
        parent::__construct('', $_ENV['OPENROUTER_API_KEY'] ?? '');
        $this->send_length = true;
        $this->verbose = false;
        $this->timeout = 180;
        $this->payload["model"] = $model;
    }

    protected function &get_user_content(): array
    {
        foreach ($this->payload['messages'] as &$message) {
            if ($message['role'] === "user") {
                return $message['content'];
            }
        }
        unset($message); // Break the reference after loop
        $user_content = [];
        $this->payload['messages'][] = [
            "role" => "user",
            "content" => $user_content
        ];
        $last_index = count($this->payload['messages']) - 1;
        return $this->payload['messages'][$last_index]['content'];
    }

    public function set_system_content(string $content): void
    {
        foreach ($this->payload['messages'] as &$message) {
            if ($message['role'] === "system") {
                $message['content'] = $content;
                return;
            }
        }
        unset($message); // Break the reference after loop
        $this->payload['messages'][] = [
            "role" => "system",
            "content" => $content
        ];
    }

    public function add_text(string $text)
    {
        $user_content = &$this->get_user_content();
        $user_content[] = ["type" => "text", "text" => $text];
    }

    /**     
     * Add a file to the payload
     * If the file is a PDF add a plugin to the payload
     * @param string $file_path - the path to the file
     * @return void
     */
    public abstract function add_file(string $file_path, string $file_name): void;

    public function enable_image_output(): void
    {
        $this->payload['modalities'] = ['image', 'text'];
    }

    /**
     * Call OpenRouter and return assistant text plus generated/annotated images.
     *
     * @return array{text: string, images: list<array{mime: string, body: string}>}
     */
    public function call_annotation(): array
    {
        $response = $this->QueryArray(self::OPENROUTER_API_URL, 'POST', $this->common_headers, $this->payload);
        if ($response === null) {
            throw new \Exception('Empty response from model');
        }

        $message = $response['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            throw new \Exception('Invalid model response');
        }

        $text = self::extract_message_text($message['content'] ?? '');
        $images = [];
        foreach ($message['images'] ?? [] as $image) {
            $url = '';
            if (is_array($image)) {
                $url = $image['image_url']['url'] ?? (is_string($image['image_url'] ?? null) ? $image['image_url'] : '');
            }
            $decoded = self::decode_data_url((string) $url);
            if ($decoded !== null) {
                $images[] = $decoded;
            }
        }

        if ($images === []) {
            foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $part) {
                if (!is_array($part)) {
                    continue;
                }
                $url = $part['image_url']['url'] ?? '';
                $decoded = self::decode_data_url((string) $url);
                if ($decoded !== null) {
                    $images[] = $decoded;
                }
            }
        }

        return ['text' => $text, 'images' => $images];
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

    public function call(): array
    {
        try {
            $respone = $this->QueryArray(self::OPENROUTER_API_URL, 'POST', $this->common_headers, $this->payload);
            $json_content = $respone['choices'][0]['message']['content'];
            $response = JsonUtils::decodeArray(str_replace('json', '', str_replace('```', '', $json_content)));

            if ($response === null) {
                throw new \Exception("Invalid JSON content: " . $json_content);
            }
            return ["model" => $this->payload["model"], "response" => $response, "payload" => $this->payload, "respone" => $respone];
        } catch (\Throwable $th) {
            return ["model" => $this->payload["model"], "response" => $th->getMessage(), "payload" => $this->payload, "respone" => null];
        }
    }
}
