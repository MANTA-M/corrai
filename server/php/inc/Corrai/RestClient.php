<?php

namespace Corrai;

use Exception;

/**
 * Base class for REST API calls
 */
class RestClient
{
    public static int $timer = 0;
    protected bool $disableSslVerif = true;
    public bool $compress = false;
    public array $common_headers = [];
    public bool $send_length = false;
    public bool $verbose = true;
    public int $timeout = 0;

    public function __construct(protected string $baseUrl, protected string $token = '') {}

    public function QueryArray(string $path, string $method = 'GET', $headers = null, $postData = null): ?array
    {
        $str = $this->Query($path, $method, $headers, $postData);
        return $str !== null ? JsonUtils::decodeArray($this->normalizeJson($str)) : null;
    }

    public function QueryObject(string $path, string $method = 'GET', $headers = null, $postData = null): ?object
    {
        $str = $this->Query($path, $method, $headers, $postData);
        return $str !== null ? JsonUtils::decodeObj($this->normalizeJson($str)) : null;
    }

    /**
     * Normalize JSON string for decoding (e.g. NaN handling).
     */
    private function normalizeJson(string $str): string
    {
        return str_replace(':NaN', ':"NaN"', $str);
    }

    /**
     * Build payload and default content-type from post data.
     *
     * @return array{string, string|null} [contentType, payload or null]
     */
    private function buildPayload($postData): array
    {                   
        return ['application/json', $postData !== null ? json_encode($postData) : null];
    }

    /**
     * Create a query
     *
     * @return string|null Response body or null on empty
     */
    public function Query(string $path, string $method = 'GET', $headers = null, $postData = null): ?string
    {
        [$defaultContentType, $payload] = $this->buildPayload($postData);

        $curl = curl_init();
        $opts = [
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($this->timeout > 0) {
            $opts[CURLOPT_TIMEOUT] = $this->timeout;
        }

        $restDebug = $_ENV['REST_TRACE_LEVEL'] ?? 'none';
        if ($restDebug !== 'none') {
            $opts[CURLOPT_VERBOSE] = true;
            $opts[CURLOPT_FOLLOWLOCATION] = true;
        }

        if ($this->disableSslVerif) {
            $opts[CURLOPT_SSL_VERIFYHOST] = false;
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
        }

        $headers = is_array($headers) ? $headers : [];
        if ($headers === []) {
            $headers[] = 'Content-type: ' . $defaultContentType;
            $headers[] = 'Accept: application/json';
        }
        if ($this->send_length && $payload !== null) {
            $headers[] = 'Content-Length: ' . strlen($payload);
        }
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        // Log payload if verbose is enabled (before compression)
        if ($this->verbose) {
            error_log('REST QUERY ' . $method . ' ' . $path);
            if($payload !== null){
                $this->logPayload($payload, $defaultContentType, $method);
            }
        }
        if ($payload !== null && $this->compress) {
            $headers[] = 'Content-Encoding: gzip';
            $payload = gzencode($payload);
        }
        $opts[CURLOPT_HTTPHEADER] = array_merge($headers, $this->common_headers);
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = $payload;
        }

        $responseHeaders = [];
        $opts[CURLOPT_HEADERFUNCTION] = function ($ch, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) >= 2) {
                $responseHeaders[strtolower(trim($parts[0]))][] = trim($parts[1]);
            }
            return $len;
        };

        curl_setopt_array($curl, $opts);
        $then = (int) round(microtime(true) * 1000);
        $response = curl_exec($curl);
        $duration = (int) round(microtime(true) * 1000) - $then;
        self::$timer += $duration;

        $responseCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contentType = $responseHeaders['content-type'][0] ?? '';
        $isJson = str_contains($contentType, 'json') || ($response !== false && $response !== '' && $response[0] === '{');

        // Log received response, even if it's an error
        $this->logResponse($response, $responseCode, $isJson);

        if ($responseCode < 200 || $responseCode > 299) {
            $errorMessage = $this->extractErrorMessage($response, $contentType, $curl);
            throw new Exception($errorMessage, $responseCode);
        }

        return $response !== false ? $response : null;
    }

    private const BASE64_REDACT_MIN_LEN = 200;

    /**
     * Log POST payload using error_log, formatting JSON if applicable.
     * Binary/base64 bodies are replaced with short placeholders.
     */
    private function logPayload(string $payload, string $contentType, string $method): void
    {
        $isJson = str_contains($contentType, 'json');
        $logMessage = sprintf('[RestClient] %s Payload (%s):', $method, $contentType);
        
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
    private function logResponse($response, int $responseCode, bool $isJson): void
    {
        if ($response === false || $response === '') {
            error_log(sprintf('[RestClient] Response (HTTP %d): Empty or failed', $responseCode));
            return;
        }

        $logMessage = sprintf('[RestClient] Response (HTTP %d):', $responseCode);
        
        if ($isJson) {
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $redacted = $this->redactBinaryForLog($decoded);
                $formatted = json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                error_log($logMessage . "\n" . $formatted);
            } else {
                error_log($logMessage . "\n" . $this->redactBinaryString($response));
            }
        } else {
            error_log($logMessage . "\n" . $this->redactBinaryString($response));
        }
    }

    /**
     * Recursively redact data-URL and long base64 strings in a decoded JSON value.
     */
    private function redactBinaryForLog(mixed $value): mixed
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
        return $value;
    }

    /**
     * Replace data-URL / long base64 bodies with a short placeholder.
     */
    private function redactBinaryString(string $value): string
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

    /**
     * Extract error message from failed response body or curl.
     */
    private function extractErrorMessage($response, string $contentType, $curl): string
    {
        if ($response === false || $response === '') {
            return curl_error($curl) ?: 'Unknown error';
        }
        if (str_contains($contentType, 'json') || (isset($response[0]) && $response[0] === '{')) {
            try {
                $obj = JsonUtils::decodeObj($response);
                return $obj->error->message ?? $obj->messages[0]->text ?? $response;
            } catch (\Throwable) {
                // fall through to return raw response
            }
        }
        return $response;
    }
}
