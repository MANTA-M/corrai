<?php

namespace Corrai\LlmClient;

use Exception;

class LlmClientFactory
{
    public static function create(?string $model = null): LlmClient
    {
        $model = $model ?: ($_ENV['OPENROUTER_MODEL'] ?? '');
        if (stripos($model, 'anthropic') !== false || stripos($model, 'claude') !== false) {
            return new ClaudeSonnetClient($model);
        }
        if (stripos($model, 'google') !== false || stripos($model, 'gemini') !== false) {
            if (stripos($model, 'flash-lite') !== false) {
                return new Gemini2FlashLiteClient();
            }
            return new Gemini3Client($model);
        }
        if (stripos($model, 'mistral') !== false) {
            return new MistralLargeClient();
        }
        throw new Exception("Model not supported: " . $model);
    }
}
