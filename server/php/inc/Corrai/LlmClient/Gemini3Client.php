<?php

namespace Corrai\LlmClient;

class Gemini3Client extends LlmClient
{
    public function __construct(?string $model = null)
    {
        parent::__construct($model ?: ($_ENV['OPENROUTER_IMAGE_MODEL'] ?? 'google/gemini-2.5-flash-image'));
    }

    /**     
     * Add a file to the payload
     * If the file is a PDF add a plugin to the payload
     * @param string $file_path - the path to the file
     * @return void
     */
    public function add_file(string $file_path, string $file_name): void
    {
        // Add a file with the name of the file and the content of the file in Base64 Encoded data url
        $file_data = base64_encode(file_get_contents($file_path));
        $urldata = "data:application/pdf;base64," . $file_data;
        $user_content = &$this->get_user_content();
        $data_content = ["type" => "image_url", "image_url" => ["url" => $urldata]];
        $user_content[] = $data_content;
    }
}
