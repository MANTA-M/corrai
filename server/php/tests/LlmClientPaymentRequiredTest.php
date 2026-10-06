<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Llm\Openrouter\ClaudeSonnetClient;
use Corrai\Utils\Http\WSException;
use PHPUnit\Framework\TestCase;

class LlmClientPaymentRequiredTest extends TestCase
{
    public function testCallTextTurnsHttp402IntoAiError(): void
    {
        $client = new PaymentRequiredClaudeClient();

        $this->expectException(WSException::class);
        $this->expectExceptionCode(500);
        $this->expectExceptionMessage('AI error');
        $client->call_text();
    }

    public function testCallTurnsHttp402IntoAiError(): void
    {
        $client = new PaymentRequiredClaudeClient();

        $this->expectException(WSException::class);
        $this->expectExceptionCode(500);
        $this->expectExceptionMessage('AI error');
        $client->call();
    }

    public function testCallAnnotationTurnsHttp402IntoAiError(): void
    {
        $client = new PaymentRequiredClaudeClient();

        $this->expectException(WSException::class);
        $this->expectExceptionCode(500);
        $this->expectExceptionMessage('AI error');
        $client->call_annotation();
    }
}

class PaymentRequiredClaudeClient extends ClaudeSonnetClient
{
    public function QueryArray(string $path, string $method = 'GET', $headers = null, $postData = null): ?array
    {
        throw new \Exception('Payment Required', 402);
    }
}
