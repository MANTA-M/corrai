<?php

namespace Corrai\Utils\Http;

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
    public int $lastResponseCode = 0;

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
     * Build a configured cURL handle for a request.
     * responseHeaders is an object so HEADERFUNCTION mutations stay visible to the caller.
     *
     * @return array{curl: \CurlHandle, responseHeaders: \stdClass}
     */
    private function createCurlHandle(string $path, string $method = 'GET', $headers = null, $postData = null): array
    {
        [$defaultContentType, $payload] = $this->buildPayload($postData);

        /** @var \stdClass&object{headers: array<string, list<string>>} $responseHeaders */
        $responseHeaders = new \stdClass();
        $responseHeaders->headers = [];

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
        if ($this->shouldLog()) {
            error_log('REST QUERY ' . $method . ' ' . $path);
            if ($payload !== null) {
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

        $opts[CURLOPT_HEADERFUNCTION] = function ($ch, $header) use ($responseHeaders) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) >= 2) {
                $responseHeaders->headers[strtolower(trim($parts[0]))][] = trim($parts[1]);
            }
            return $len;
        };

        $curl = curl_init();
        curl_setopt_array($curl, $opts);

        return [
            'curl' => $curl,
            'responseHeaders' => $responseHeaders,
        ];
    }

    /**
     * Process a completed cURL response: update timer/code, log, optionally throw.
     *
     * @param array<string, list<string>> $responseHeaders
     * @return string|null Response body or null on empty
     */
    private function finalizeResponse($curl, $response, array $responseHeaders, bool $throwOnError): ?string
    {
        $responseCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $this->lastResponseCode = $responseCode;
        $contentType = $responseHeaders['content-type'][0] ?? '';
        $isJson = str_contains($contentType, 'json') || ($response !== false && $response !== '' && $response[0] === '{');

        // Log received response, even if it's an error
        if ($this->shouldLog() || $responseCode < 200 || $responseCode > 299) {
            $this->logResponse($response, $responseCode, $isJson);
        }

        if ($responseCode < 200 || $responseCode > 299) {
            $errorMessage = $this->extractErrorMessage($response, $contentType, $curl);
            if ($throwOnError) {
                throw new Exception($errorMessage, $responseCode);
            }
            error_log('[RestClient] ' . $errorMessage);
        }

        return $response !== false ? $response : null;
    }

    /**
     * Create a query
     *
     * @return string|null Response body or null on empty
     */
    public function Query(string $path, string $method = 'GET', $headers = null, $postData = null): ?string
    {
        $bundle = $this->createCurlHandle($path, $method, $headers, $postData);
        $curl = $bundle['curl'];

        $then = (int) round(microtime(true) * 1000);
        $response = curl_exec($curl);
        $duration = (int) round(microtime(true) * 1000) - $then;
        self::$timer += $duration;

        try {
            return $this->finalizeResponse($curl, $response, $bundle['responseHeaders']->headers, true);
        } finally {
            curl_close($curl);
        }
    }

    /**
     * Run multiple queries in parallel via curl_multi.
     * The callback is invoked as each request completes (including HTTP errors).
     *
     * @param list<array{path: string, method?: string, headers?: ?array, postData?: mixed}> $requests
     * @param callable(array $params, ?string $response, int $httpCode): void $onResponse
     */
    public function QueryMulti(array $requests, callable $onResponse): void
    {
        if ($requests === []) {
            return;
        }

        $multi = curl_multi_init();
        /** @var array<int, array{params: array, curl: \CurlHandle, responseHeaders: \stdClass}> $pending */
        $pending = [];

        foreach ($requests as $params) {
            $path = $params['path'] ?? '';
            $method = $params['method'] ?? 'GET';
            $headers = $params['headers'] ?? null;
            $postData = $params['postData'] ?? null;

            $bundle = $this->createCurlHandle($path, $method, $headers, $postData);
            curl_multi_add_handle($multi, $bundle['curl']);
            $pending[spl_object_id($bundle['curl'])] = [
                'params' => $params,
                'curl' => $bundle['curl'],
                'responseHeaders' => $bundle['responseHeaders'],
            ];
        }

        $active = null;
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi);
            }

            while ($info = curl_multi_info_read($multi)) {
                $curl = $info['handle'];
                $id = spl_object_id($curl);
                if (!isset($pending[$id])) {
                    continue;
                }

                $entry = $pending[$id];
                unset($pending[$id]);

                $duration = (int) round((float) curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000);
                self::$timer += $duration;

                $response = curl_multi_getcontent($curl);
                $body = $this->finalizeResponse($curl, $response, $entry['responseHeaders']->headers, false);
                $httpCode = $this->lastResponseCode;

                $onResponse($entry['params'], $body, $httpCode);

                curl_multi_remove_handle($multi, $curl);
                curl_close($curl);
            }
        } while ($active && $status === CURLM_OK);

        // Clean up any remaining handles (e.g. after unexpected multi status)
        foreach ($pending as $entry) {
            curl_multi_remove_handle($multi, $entry['curl']);
            curl_close($entry['curl']);
        }

        curl_multi_close($multi);
    }

    protected function shouldLog(): bool
    {
        return $this->verbose;
    }

    /**
     * Log POST payload using error_log.
     */
    protected function logPayload(string $payload, string $contentType, string $method): void
    {
        $logMessage = sprintf('[RestClient] %s Payload (%s):', $method, $contentType);
        error_log($logMessage . "\n" . $this->formatResponseForLog($payload));
    }

    /**
     * Log received response using error_log.
     */
    protected function logResponse($response, int $responseCode, bool $isJson): void
    {
        if ($response === false || $response === '') {
            error_log(sprintf('[RestClient] Response (HTTP %d): Empty or failed', $responseCode));
            return;
        }

        $logMessage = sprintf('[RestClient] Response (HTTP %d):', $responseCode);
        $formatted = $this->formatResponseForLog((string) $response);
        error_log($logMessage . "\n" . $formatted);
    }

    /**
     * Format a response body for logging in generic RestClient (basic JSON pretty-print).
     */
    public function formatResponseForLog(string $response): string
    {
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $response;
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
