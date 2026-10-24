<?php

use Corrai\Utils\Http\JsonUtils;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Http\WSException;
use Corrai\Utils\Utils;

const LOG_BODY_MAX_BYTES = 2048;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new WSException('Method not allowed', 405);
    }

    $expectedKey = $_ENV['LOG_API_KEY'] ?? (getenv('LOG_API_KEY') ?: '');
    if ($expectedKey === '') {
        throw new WSException('LOG_API_KEY is not configured', 500);
    }

    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
    if ($providedKey === null && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $providedKey = $headers['X-Api-Key'] ?? $headers['x-api-key'] ?? null;
    }
    if (!is_string($providedKey) || !hash_equals($expectedKey, $providedKey)) {
        throw new WSException('Unauthorized', 401);
    }

    $appId = Request::getStringParam('app') ?? Request::getStringParam('appid');
    if ($appId === null || $appId === '') {
        Request::add_error_message('error', 'Missing app or appid URL parameter');
        Request::output_all();
        exit();
    }
    if (!is_string($appId)) {
        Request::add_error_message('error', 'app must be a string');
        Request::output_all();
        exit();
    }
    if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $appId)) {
        Request::add_error_message('error', 'Invalid app id');
        Request::output_all();
        exit();
    }

    $raw = Request::getPostStr();
    if ($raw === null || $raw === '') {
        Request::add_error_message('error', 'Empty request body');
        Request::output_all();
        exit();
    }
    if (strlen($raw) > LOG_BODY_MAX_BYTES) {
        Request::add_error_message('error', 'JSON body must be at most 2KB');
        Request::output_all();
        exit();
    }

    try {
        $data = JsonUtils::decodeArray($raw);
    } catch (\Throwable $e) {
        Request::add_error_message('error', 'Invalid JSON in POST request body');
        Request::output_all();
        exit();
    }

    if (!is_array($data) || array_is_list($data)) {
        Request::add_error_message('error', 'JSON body must be an object');
        Request::output_all();
        exit();
    }

    $data['ts'] = date('c');

    $logDir = Utils::getLogDir();
    $logFile = $logDir . $appId . '.log';
    $line = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        Request::add_error_message('error', 'Failed to encode log entry');
        Request::output_all();
        exit();
    }

    if (file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new WSException('Failed to write log file', 500);
    }

    Request::add_output('ok', true);
    Request::add_output('app', $appId);
    if (!headers_sent()) {
        http_response_code(200);
    }
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
