<?php
use Corrai\Utils;
use Corrai\Request;

error_reporting(E_ALL);
session_start();
include_once(__DIR__ . '/autoload.php');
Utils::loadKeyValueFile(__DIR__ . "/../.env", $_ENV);

// Fill gaps from the process environment (Docker Compose env_file / PHP-FPM).
// Values already set by .env win.
$processEnv = getenv();
if (is_array($processEnv)) {
    foreach ($processEnv as $key => $value) {
        if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
            $_ENV[$key] = $value;
        }
    }
}

// Set CORS headers for all API requests
Request::setCorsHeaders();
// Logging
// Check if we should log to stderr (for Docker) or to a file
// Default to stderr for Docker compatibility - errors will appear in docker logs
$logToStderr = ($_ENV['PHP_ERROR_LOG_STDERR'] ?? 'true') !== 'false';

if ($logToStderr) {
    // Apache is configured to send PHP errors to /dev/stderr via php_admin_value
    // This ensures error_log() calls appear in Docker logs
    // We still set it here as a fallback, but Apache's php_admin_value takes precedence
    ini_set('error_log', '/dev/stderr');
    
    // Also ensure output buffering doesn't delay log output
    if (ini_get('output_buffering')) {
        ini_set('output_buffering', 'Off');
    }
    
    // Create a helper function that writes directly to stderr for guaranteed output
    // This bypasses any ini_set issues and writes directly to Docker logs
    if (!function_exists('log_to_docker')) {
        function log_to_docker($message) {
            $timestamp = date('[Y-m-d H:i:s] ');
            file_put_contents('php://stderr', $timestamp . $message . PHP_EOL, FILE_APPEND);
        }
    }
} else {
    // File-based logging (if explicitly configured)
    $logPath = Utils::getLogDir();
    if (!Utils::testDirPath($logPath)) {
        error_log("$logPath does not exists");
        exit(1);
    }
    $logFile = 'corrai_' . ($_ENV['APP_ENV'] ?? '') . '_error.log';
    ini_set('error_log', $logPath . $logFile);
}

function request_error_handler($errno, $errstr) {
    $msg = "Error: [$errno] $errstr";
    error_log($msg);
    Corrai\Request::add_error_message("error", $msg);
    // Don't call output_all() here - let the application code handle output
}
set_error_handler("request_error_handler");
?>