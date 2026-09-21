<?php

namespace Corrai\LlmClient;

class ClaudeSonnetClient extends LlmClient
{
    public function __construct(?string $model = null)
    {
        parent::__construct($model ?: ($_ENV['OPENROUTER_MODEL'] ?? 'anthropic/claude-3.7-sonnet'));
    }

    /**
     * Add a file to the payload
     * If the file is a PDF add a plugin to the payload
     * @param string $file_path - the path to the file
     * @return void
     */
    public function add_file(string $file_path, string $file_name): void
    {
        $file_data = base64_encode(file_get_contents($file_path));
        $urldata = "data:application/pdf;base64," . $file_data;
        $user_content = &$this->get_user_content();
        $data_content = ["type" => "file", "file" => ["filename" => $file_name, "file_data" => $urldata]];
        $user_content[] = $data_content;
    }
}
