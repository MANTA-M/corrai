<?php

namespace Corrai\LlmClient;

use Exception;

class LlmClientFactory
{
    public static function create(string $model): LlmClient
    {
        if (stripos($model, 'google') !== false) {
            return new Gemini2FlashLiteClient($model);
        }
        if (stripos($model, 'mistral') !== false) {
            return new MistralLargeClient($model);
        }
        throw new Exception("Model not supported: " . $model);
    }
}
