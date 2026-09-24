<?php

use Corrai\Utils\Request;

try {
    /**
     * CORS OPTIONS handler
     * Handles preflight CORS requests and sets appropriate headers
     * for same domain or localhost requests
     */

    // Get the Origin header from the request
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    // Get the server name/host
    $serverName = $_SERVER['SERVER_NAME'] ?? 'corrai.local';
    $requestHost = $_SERVER['HTTP_HOST'] ?? $serverName;
    // Remove port from request host if present
    $requestHost = preg_replace('/:\d+$/', '', $requestHost);

    // Determine if CORS should be allowed
    $allowCors = false;

    // Check if origin matches the server domain or localhost
    if ($origin) {
        $originHost = parse_url($origin, PHP_URL_HOST);
        
        if ($originHost) {
            // Remove port from origin host if present
            $originHost = preg_replace('/:\d+$/', '', $originHost);
            
            // Allow if same domain
            if ($originHost === $serverName || $originHost === $requestHost) {
                $allowCors = true;
            }
            
            // Allow localhost variations
            if (in_array($originHost, ['localhost', '127.0.0.1', '::1'])) {
                $allowCors = true;
            }
        }
    }

    // Set CORS headers if allowed
    if ($allowCors && $origin) {
        header("Access-Control-Allow-Origin: " . $origin);
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Max-Age: 86400"); // 24 hours
    }

    // For OPTIONS requests, return 200 OK
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    // For other methods, just set headers and continue (if needed)
    // This file is primarily for OPTIONS, but can handle CORS headers for other requests too
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

