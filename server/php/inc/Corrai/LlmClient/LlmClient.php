<?php

namespace Corrai\LlmClient;

use Corrai\JsonUtils;
use Corrai\Restclient;

abstract class LlmClient extends Restclient
{
    const OPENROUTER_API_URL = "https://openrouter.ai/api/v1/chat/completions";

    protected array $payload = [
        "model" => "",
        "messages" => []
    ];

    protected function __construct(string $model)
    {
        parent::__construct('', $_ENV['OPENROUTER_API_KEY']);
        $this->send_length = true;
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
                $message['role']['content'] = $content;
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
