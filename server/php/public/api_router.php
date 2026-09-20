<?php

/**
 * API Router
 * Routes requests to the appropriate PHP file based on HTTP method and path
 * 
 * Examples:
 * - GET /api/exam -> includes get_exam.php
 * - POST /api/exam -> includes post_exam.php
 * - PUT /api/exam -> includes put_exam.php
 * - DELETE /api/exam -> includes delete_exam.php
 * - OPTIONS /api/* -> includes options.php
 */

// Get the HTTP request method (validate against allowed methods)
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'];
if (!in_array($method, $allowedMethods, true)) {
    http_response_code(405);
    exit('Method not allowed');
}

// Get the request URI
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

// Remove query string if present
$requestUri = strtok($requestUri, '?');

// Extract the path after /api/
$path = '';
if (preg_match('#^/api/(.*)$#', $requestUri, $matches)) {
    $path = trim($matches[1], '/');
}

// Security: Validate path contains only safe characters (alphanumeric, underscore, dash, dot)
// This prevents directory traversal attacks (../) and other path manipulation
// Empty path is allowed (for /api/ requests, though they won't match any endpoint)
if ($path !== '' && !preg_match('/^[a-zA-Z0-9_.-]+$/', $path)) {
    include_once(dirname(__DIR__) . '/inc/common.php');
    error_log("Invalid API path: $path");    
    http_response_code(400);
    exit('Invalid API path');
}

// Define API directory (parent directory of public, then api subdirectory)
$apiDir = dirname(__DIR__) . '/api';

// Handle OPTIONS requests
if ($method === 'OPTIONS') {
    $targetFile = $apiDir . '/options.php';
    if (file_exists($targetFile) && is_file($targetFile)) {
        include_once(dirname(__DIR__) . '/inc/common.php');
        include_once($targetFile);
        exit;
    } else {
        include_once(dirname(__DIR__) . '/inc/common.php');
        error_log("Options API endpoint not found: $path");  
        http_response_code(404);
        exit('Options API endpoint not found');
    }
}

// Construct the target file name: {method}_{path}.php
$targetFile = $apiDir . '/' . strtolower($method) . '_' . $path . '.php';

// Security: Ensure the resolved path is still within the API directory
// This provides defense-in-depth against path traversal attempts via symbolic links
$realApiDir = realpath($apiDir);
$realTargetFile = realpath($targetFile);
if ($realApiDir === false || $realTargetFile === false) {
    include_once(dirname(__DIR__) . '/inc/common.php');
    error_log("Invalid API path check 1: $path $apiDir $targetFile");  
    http_response_code(404);
    exit('API endpoint not found 1');
}
// Check that the target file path is within the API directory
// Add directory separator to prevent false matches (e.g., /api vs /api_backup)
$apiDirWithSeparator = $realApiDir . DIRECTORY_SEPARATOR;
if (strpos($realTargetFile, $apiDirWithSeparator) !== 0 && $realTargetFile !== $realApiDir) {
    include_once(dirname(__DIR__) . '/inc/common.php');
    error_log("Invalid API path check 2: $path $apiDir $targetFile");  
    http_response_code(404);
    exit('API endpoint not found 2');
}

// Check if the file exists and is actually a file (not a directory)
if (!file_exists($targetFile) || !is_file($targetFile)) {
    include_once(dirname(__DIR__) . '/inc/common.php');
    error_log("Invalid API path check 3: $path $apiDir $targetFile");  
    http_response_code(404);
    exit('API endpoint not found 3');
}

// Include the target file
include_once(dirname(__DIR__) . '/inc/common.php');
include_once($targetFile);

