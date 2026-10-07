<?php

namespace Corrai\Clients;

use Corrai\Utils\Http\RestClient;

/**
 * Abstract base class for LLM REST clients.
 * Manages request/response logging, binary redaction, LLM choice formatting, and debug mode.
 */
abstract class LlmClient extends RestClient
{
    public bool $debug = true;

    protected const BASE64_REDACT_MIN_LEN = 200;
    protected const NEWLINE_PLACEHOLDER = '###CORRAI_LOG_NL###';

    public function __construct(string $baseUrl = '', string $token = '')
    {
        parent::__construct($baseUrl, $token);
        $this->send_length = true;
        $this->verbose = true;
        $this->timeout = 180;
    }

    protected function shouldLog(): bool
    {
        return $this->verbose || $this->debug;
    }

    /**
     * Log POST payload using error_log, formatting JSON if applicable.
     * Binary/base64 bodies are replaced with short placeholders.
     */
    protected function logPayload(string $payload, string $contentType, string $method): void
    {
        $isJson = str_contains($contentType, 'json');
        $logMessage = sprintf('[LlmClient] %s Payload (%s):', $method, $contentType);

        if ($isJson) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $redacted = $this->redactBinaryForLog($decoded);
                $formatted = json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                error_log($logMessage . "\n" . $formatted);
            } else {
                error_log($logMessage . "\n" . $this->redactBinaryString($payload));
            }
        } else {
            error_log($logMessage . "\n" . $this->redactBinaryString($payload));
        }
    }

    /**
     * Log received response using error_log, formatting JSON if applicable.
     * Binary/base64 bodies are replaced with short placeholders.
     */
    protected function logResponse($response, int $responseCode, bool $isJson): void
    {
        if ($response === false || $response === '') {
            error_log(sprintf('[LlmClient] Response (HTTP %d): Empty or failed', $responseCode));
            return;
        }

        $logMessage = sprintf('[LlmClient] Response (HTTP %d):', $responseCode);

        if ($isJson) {
            $formatted = $this->formatResponseForLog((string) $response);
            error_log($logMessage . "\n" . $formatted);
        } else {
            error_log($logMessage . "\n" . $this->redactBinaryString($response));
        }
    }

    /**
     * Format a response body for logging.
     */
    public function formatResponseForLog(string $response): string
    {
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $hasReplacedNewlines = $this->processChoicesForLog($decoded);
            $redacted = $this->redactBinaryForLog($decoded);
            $formatted = json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($hasReplacedNewlines) {
                $formatted = str_replace(self::NEWLINE_PLACEHOLDER, "\n", $formatted);
            }
            return $formatted;
        }

        return $this->redactBinaryString($response);
    }

    /**
     * Pre-process choices[*].message.content in LLM responses before formatting JSON for log.
     * - If content starts with '{', replace '\"' with '"' and parse JSON into an object.
     * - If content contains '\n', replace them with line breaks for log output.
     */
    protected function processChoicesForLog(array &$decoded): bool
    {
        if (!isset($decoded['choices']) || !is_array($decoded['choices'])) {
            return false;
        }

        $hasReplacedNewlines = false;

        foreach ($decoded['choices'] as &$choice) {
            if (!isset($choice['message']['content']) || !is_string($choice['message']['content'])) {
                continue;
            }

            $content = $choice['message']['content'];
            $trimmed = trim($content);

            if (str_starts_with($trimmed, '{')) {
                $cleaned = str_replace('\"', '"', $content);
                $parsed = json_decode($cleaned);
                if ($parsed === null) {
                    $parsed = json_decode(str_replace(['\r\n', '\n', '\r'], ["\r\n", "\n", "\r"], $cleaned));
                }
                if ($parsed !== null && (is_object($parsed) || is_array($parsed))) {
                    $choice['message']['content'] = $parsed;
                    continue;
                }
            }

            if (str_contains($content, "\n") || str_contains($content, '\n') || str_contains($content, "\r")) {
                $choice['message']['content'] = str_replace(
                    ["\r\n", "\n", "\r", '\r\n', '\n', '\r'],
                    self::NEWLINE_PLACEHOLDER,
                    $content
                );
                $hasReplacedNewlines = true;
            }
        }
        unset($choice);

        return $hasReplacedNewlines;
    }

    /**
     * Recursively redact data-URL and long base64 strings in a decoded JSON value.
     */
    protected function redactBinaryForLog(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactBinaryString($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->redactBinaryForLog($item);
            }
            return $out;
        }
        if (is_object($value)) {
            $out = clone $value;
            foreach ($out as $key => $item) {
                $out->$key = $this->redactBinaryForLog($item);
            }
            return $out;
        }
        return $value;
    }

    /**
     * Replace data-URL / long base64 bodies with a short placeholder.
     */
    protected function redactBinaryString(string $value): string
    {
        if (preg_match('#^(data:[^;]+;base64,)(.+)$#s', $value, $matches)) {
            return $matches[1] . '[omitted ' . strlen($matches[2]) . ' chars]';
        }
        if (strlen($value) >= self::BASE64_REDACT_MIN_LEN
            && preg_match('#^[A-Za-z0-9+/=\s]+$#', $value)
        ) {
            return '[base64 omitted, ' . strlen($value) . ' chars]';
        }
        return $value;
    }
}
