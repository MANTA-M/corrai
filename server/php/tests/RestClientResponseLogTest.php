<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Utils\RestClient;
use PHPUnit\Framework\TestCase;

class RestClientResponseLogTest extends TestCase
{
    private TestableRestClient $client;

    protected function setUp(): void
    {
        $this->client = new TestableRestClient();
    }

    public function testJsonContentStartingWithBraceIsParsedIntoObject(): void
    {
        $rawResponse = json_encode([
            'id' => 'chatcmpl-123',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"errors": [{"kind": "orthographe", "student": "pomme"}], "score": 18}',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $formatted = $this->client->formatResponseForLog($rawResponse);

        // Content should be formatted as a nested JSON object, not an escaped string
        $this->assertStringContainsString('"content": {', $formatted);
        $this->assertStringContainsString('"kind": "orthographe"', $formatted);
        $this->assertStringContainsString('"student": "pomme"', $formatted);
        $this->assertStringContainsString('"score": 18', $formatted);
    }

    public function testJsonContentWithEscapedQuotesIsCleanedAndParsed(): void
    {
        // LLM response where content string has literal \" inside: {\"status\": \"ok\"}
        $rawResponse = json_encode([
            'id' => 'gen-1',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{\"status\": \"ok\", \"count\": 5}',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $formatted = $this->client->formatResponseForLog($rawResponse);

        $this->assertStringContainsString('"content": {', $formatted);
        $this->assertStringContainsString('"status": "ok"', $formatted);
        $this->assertStringContainsString('"count": 5', $formatted);
    }

    public function testMultilineContentWithNewlinesHasLineBreaksForLogs(): void
    {
        $rawResponse = json_encode([
            'id' => 'chatcmpl-456',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => "<?php\n\$GD_directives = [\n    ['fn' => 'imageline']\n];",
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $formatted = $this->client->formatResponseForLog($rawResponse);

        // Content should have real line breaks
        $this->assertStringContainsString("<?php\n", $formatted);
        $this->assertStringContainsString("\$GD_directives = [\n", $formatted);
        $this->assertStringNotContainsString('<?php\n', $formatted);
    }

    public function testLiteralSlashNIsReplacedByLineBreaks(): void
    {
        $rawResponse = '{"id":"gen-2","choices":[{"message":{"role":"assistant","content":"Line 1\\nLine 2\\nLine 3"}}]}';

        $formatted = $this->client->formatResponseForLog($rawResponse);

        $this->assertStringContainsString("Line 1\nLine 2\nLine 3", $formatted);
    }

    public function testMultipleChoicesAreAllProcessed(): void
    {
        $rawResponse = json_encode([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"type": "first"}',
                    ],
                ],
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => "Line A\nLine B",
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $formatted = $this->client->formatResponseForLog($rawResponse);

        $this->assertStringContainsString('"type": "first"', $formatted);
        $this->assertStringContainsString("Line A\nLine B", $formatted);
    }

    public function testNonLlmResponseIsFormattedNormally(): void
    {
        $rawResponse = json_encode(['status' => 'success', 'data' => [1, 2, 3]], JSON_THROW_ON_ERROR);

        $formatted = $this->client->formatResponseForLog($rawResponse);

        $this->assertStringContainsString('"status": "success"', $formatted);
    }
}

class TestableRestClient extends RestClient
{
    public function __construct()
    {
        parent::__construct('https://example.com');
    }
}
