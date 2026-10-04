<?php

namespace Corrai\Llm\Openrouter;

use Exception;

class LlmClientFactory
{
    public static function create(string $model): OpenrouterClient
    {
        if (stripos($model, 'qwen') !== false) {
            return new Qwen25Vl72bInstructClient($model);
        }
        if (stripos($model, 'deepseek') !== false) {
            return new DeepSeekV32Client($model);
        }
        if (stripos($model, 'gpt-4o-mini') !== false || $model === 'openai/gpt-4o-mini') {
            return new Gpt4oMiniClient($model);
        }
        if (stripos($model, 'anthropic') !== false || stripos($model, 'claude') !== false) {
            return new ClaudeSonnetClient($model);
        }
        if (stripos($model, 'google') !== false || stripos($model, 'gemini') !== false) {
            if (stripos($model, 'flash-lite') !== false) {
                return new Gemini2FlashLiteClient();
            }
            if (stripos($model, '2.5-flash') !== false && stripos($model, 'flash-image') === false) {
                return new Gemini25FlashClient();
            }
            return new Gemini3Client($model);
        }
        if (stripos($model, 'mistral') !== false) {
            return new MistralLargeClient();
        }
        throw new Exception("Model not supported: " . $model);
    }
}
