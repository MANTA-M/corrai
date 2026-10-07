<?php

namespace Corrai\Subject;

use Corrai\Clients\Openrouter\GeminiFlashLiteClient;
use Corrai\Utils\Http\WSException;

/**
 * Synchronous first-page reading with google/gemini-2.5-flash-lite.
 */
class GeminiSubjectPageReader implements SubjectPageReader
{
    public function __construct(private readonly GeminiFlashLiteClient $client)
    {
    }

    public function read(string $pagePath, string $pageName, array $tree): array
    {
        $treeJson = json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($treeJson === false) {
            throw new WSException('Cannot encode the subject tree', 500);
        }
        $isText = str_ends_with(strtolower($pageName), '.txt');
        $this->client->set_system_content(
            $isText
                ? "You read the opening of an assessment subject given as text.\nReply with JSON only."
                : "You read only the first page of an assessment subject.\n"
                    . "Ignore every later page. The attached image is that first page.\n"
                    . "Reply with JSON only."
        );
        $this->client->set_json_response('assessment_attributes', [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'subject' => ['type' => 'string'],
                'level' => ['type' => 'string'],
                'country' => ['type' => 'string'],
                'date' => ['type' => 'string'],
            ],
            'required' => ['name', 'subject', 'level', 'country', 'date'],
            'additionalProperties' => false,
        ]);
        $instruction = "Possible subjects (use the subject and level codes exactly):\n"
            . $treeJson
            . "\n\nReturn JSON:\n"
            . "name: the title, or a short name you create when there is no title.\n"
            . "subject: the closest subject code from the tree.\n"
            . "level: the level code from that subject when it is shown, otherwise an empty string.\n"
            . "country: the country code from that subject when the page shows it, otherwise an empty string.\n"
            . "date: the subject date as YYYY-MM-DD when it is readable, otherwise an empty string.\n";
        if ($isText) {
            $excerpt = file_get_contents($pagePath);
            if ($excerpt === false || $excerpt === '') {
                throw new WSException('Cannot read subject file', 400);
            }
            $this->client->add_text($instruction . "Subject text:\n" . $excerpt);
        } else {
            $this->client->add_file($pagePath, $pageName);
            $this->client->add_text($instruction . "Analyze only the first page.");
        }

        $result = $this->client->call();
        $response = $result['response'] ?? null;
        if (!is_array($response)) {
            $detail = is_string($response) ? $response : 'empty analysis';
            error_log('Subject page analysis failed: ' . $detail);
            throw new WSException('Subject analysis failed', 502);
        }

        return $response;
    }
}
