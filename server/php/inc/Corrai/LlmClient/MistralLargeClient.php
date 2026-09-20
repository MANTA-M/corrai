<?php

namespace Corrai\LlmClient;

class MistralLargeClient extends LlmClient
{

    public function __construct()
    {
        parent::__construct("mistralai/mistral-large");
    }

    public function add_plugin(string $plugin)
    {
        if (!isset($this->payload['plugins'])) {
            $this->payload['plugins'] = [];
        }
        if (isset($this->payload['plugins'])) {
            foreach ($this->payload['plugins'] as $plugin) {
                if ($plugin['id'] === "file-parser") {
                    return;
                }
            }
        }

        $this->payload['plugins'][] = [
            "id" => "file-parser",
            "pdf" => [
                "engine" => "pdf-text"  # defaults to "mistral-ocr". See Pricing above
            ]
        ];
    }

    /**     
     * Add a file to the payload
     * If the file is a PDF add a plugin to the payload
     * @param string $file_path - the path to the file
     * @return void
     */
    public function add_file(string $file_path, string $file_name): void
    {
        if (pathinfo($file_path, PATHINFO_EXTENSION) === 'pdf') {
            $this->add_plugin('file-parser');
        }
        // Add a file with the name of the file and the content of the file in Base64 Encoded data url
        $file_data = base64_encode(file_get_contents($file_path));
        $user_content = &$this->get_user_content();
        $data_content = ["type" => "file", "file" => ["filename" => $file_name, "file_data" => $file_data]];
        $user_content[] = $data_content;
    }
}