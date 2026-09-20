<?php

namespace Corrai;

use Exception;

/**
 * Base class for REST API calls
 */
class Restclient
{
    public static int $timer = 0;
    protected bool $disableSslVerif = true;
    public bool $compress = false;
    public array $common_headers = [];
    public bool $send_length = false;
    public bool $verbose = true;

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

    /**
     * Log POST payload using error_log, formatting JSON if applicable.
     */
    private function logPayload(string $payload, string $contentType, string $method): void
    {
        $isJson = str_contains($contentType, 'json');
        $logMessage = sprintf('[RestClient] %s Payload (%s):', $method, $contentType);
        
        if ($isJson) {
            // Try to format JSON nicely
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $formatted = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                error_log($logMessage . "\n" . $formatted);
            } else {
                // Not valid JSON despite content-type, log as-is
                error_log($logMessage . "\n" . $payload);
            }
        } else {
            error_log($logMessage . "\n" . $payload);
        }
    }

    /**
     * Log received response using error_log, formatting JSON if applicable.
     */
    private function logResponse($response, int $responseCode, bool $isJson): void
    {
        if ($response === false || $response === '') {
            error_log(sprintf('[RestClient] Response (HTTP %d): Empty or failed', $responseCode));
            return;
        }

        $logMessage = sprintf('[RestClient] Response (HTTP %d):', $responseCode);
        
        if ($isJson) {
            // Try to format JSON nicely
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $formatted = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                error_log($logMessage . "\n" . $formatted);
            } else {
                // Not valid JSON despite content-type, log as-is
                error_log($logMessage . "\n" . $response);
            }
        } else {
            error_log($logMessage . "\n" . $response);
        }
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
