<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Llm\Eden\GoogleOCRClient;
use Corrai\Llm\LlmClient;
use Corrai\Llm\Mistral\MistralClient;
use Corrai\Llm\Openrouter\ClaudeSonnetClient;
use Corrai\Llm\Openrouter\OpenrouterClient;
use Corrai\Utils\Http\RestClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class LlmClientTest extends TestCase
{
    public function testLlmClientExtendsRestClient(): void
    {
        $reflection = new ReflectionClass(LlmClient::class);
        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->isSubclassOf(RestClient::class));
    }

    public function testOpenrouterClientMistralClientAndGoogleOCRClientExtendLlmClient(): void
    {
        $this->assertTrue(is_subclass_of(OpenrouterClient::class, LlmClient::class));
        $this->assertTrue(is_subclass_of(MistralClient::class, LlmClient::class));
        $this->assertTrue(is_subclass_of(GoogleOCRClient::class, LlmClient::class));
    }

    public function testDebugAttributeIsDeclaredOnLlmClientAndNotOnClaudeSonnetClient(): void
    {
        $claudeReflection = new ReflectionClass(ClaudeSonnetClient::class);
        $this->assertTrue($claudeReflection->hasProperty('debug'));

        // The property must be declared on LlmClient, NOT directly on ClaudeSonnetClient
        $declaringClass = $claudeReflection->getProperty('debug')->getDeclaringClass()->getName();
        $this->assertSame(LlmClient::class, $declaringClass);

        $llmReflection = new ReflectionClass(LlmClient::class);
        $this->assertTrue($llmReflection->hasProperty('debug'));
    }

    public function testDebugAttributeExistsOnAllClientsAndDefaultsToFalse(): void
    {
        $claude = new ClaudeSonnetClient();
        $this->assertFalse($claude->debug);
        $claude->debug = true;
        $this->assertTrue($claude->debug);

        $mistral = new MistralClient();
        $this->assertFalse($mistral->debug);
        $mistral->debug = true;
        $this->assertTrue($mistral->debug);

        $google = new GoogleOCRClient();
        $this->assertFalse($google->debug);
        $google->debug = true;
        $this->assertTrue($google->debug);
    }

    public function testShouldLogWhenVerboseOrDebug(): void
    {
        $client = new ConcreteTestLlmClient();

        // Default: verbose = true, debug = false => should log
        $this->assertTrue($client->testShouldLog());

        // verbose = false, debug = false => should not log
        $client->verbose = false;
        $client->debug = false;
        $this->assertFalse($client->testShouldLog());

        // verbose = false, debug = true => should log
        $client->debug = true;
        $this->assertTrue($client->testShouldLog());
    }

    public function testFormatResponseForLogRedactsBase64AndExpandsChoices(): void
    {
        $client = new ConcreteTestLlmClient();

        $rawResponse = json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => '{"result": "ok"}',
                    ],
                ],
            ],
            'image' => 'data:image/png;base64,' . str_repeat('A', 300),
        ], JSON_THROW_ON_ERROR);

        $formatted = $client->formatResponseForLog($rawResponse);

        $this->assertStringContainsString('"result": "ok"', $formatted);
        $this->assertStringContainsString('data:image/png;base64,[omitted 300 chars]', $formatted);
    }
}

class ConcreteTestLlmClient extends LlmClient
{
    public function __construct()
    {
        parent::__construct('https://example.com', 'test-token');
    }

    public function testShouldLog(): bool
    {
        return $this->shouldLog();
    }
}
