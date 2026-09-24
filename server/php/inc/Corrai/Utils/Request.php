<?php

namespace Corrai\Utils;

use Generator;
use Throwable;
use Corrai\Utils\WSException;


/**
 * Utility functions class.
 */
class Request
{

    public static function getPostStr(): ?String
    {
        $res = file_get_contents('php://input');
        return $res ? $res : null;
    }

    public static function getPostDataArray(): ?array
    {
        return JsonUtils::decodeStrict(self::getPostStr());
    }

    public static function GetIpAddress(): string
    {
        if (array_key_exists('HTTP_CLIENT_IP', $_SERVER)) {
            //ip from share internet
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) {
            //ip pass from proxy
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        if (array_key_exists('REMOTE_ADDR', $_SERVER)) {
            //ip pass from proxy
            return $_SERVER['REMOTE_ADDR'];
        }
        return 'No IP';
    }

    public static function add_output(string $property_name,  mixed $content): void
    {
        $existçoutput = $_REQUEST['output'] ?? [];
        $existçoutput[$property_name] = $content;
        $_REQUEST['output'] = $existçoutput;
    }

    /**
     * Output generator on the run
     */
    public static function output_generator(string $property_name,  Generator $generator): void
    {
        self::addHeader("Content-Type", "application/json");
        print("{\"$property_name\": [");
        $prev = null;
        $messages = [];
        foreach ($generator as $current) {
            try {
                if ($prev !== null) {
                    print (json_encode($prev)) . ', ';
                }
                $prev = $current;
            } catch (\Throwable $th) {
                $messages[] = $th->getMessage();
            }
        }
        if ($prev !== null) {
            print(json_encode($prev));
        }
        print("]");
        if ($messages) {
            print(", \"messages\":" . json_encode($messages));
        }
        print("}");
    }

    public static function add_error_message(string $level, string $message): void
    {
        if ($level == "error" && !headers_sent())
            http_response_code("400");
        if (!isset($_REQUEST['messages']))
            $_REQUEST['messages'] = [];
        $new = array("level" => $level, "message" => $message);
        array_push($_REQUEST['messages'], $new);
    }

    public static function handle_throwable(Throwable $th): void
    {
        if (!headers_sent()) {
            if ($th instanceof WSException) {
                $code = (int) $th->getCode();
                // Custom status codes (432-499) are surfaced via error_code; HTTP stays 400
                if ($code >= 432 && $code <= 499) {
                    http_response_code(400);
                    $_REQUEST['error_code'] = $code;
                } elseif ($code >= 400 && $code <= 599) {
                    http_response_code($code);
                } else {
                    http_response_code(400);
                }
            } else {
                http_response_code(400);
            }
        }
        if (!isset($_REQUEST['messages']))
            $_REQUEST['messages'] = [];
        $new = array("level" => "error", "message" => $th->getMessage());
        array_push($_REQUEST['messages'], $new);
        error_log($th->getMessage());
        if (!($th instanceof WSException))
            error_log($th->getTraceAsString());
    }

    public static function output_all(): void
    {
        self::addHeader("Content-Type", "application/json");
        $arr = $_REQUEST['output'] ?? [];
        if (isset($_REQUEST['messages']))
            $arr['messages'] = $_REQUEST['messages'];
        if (isset($_REQUEST['error_code']))
            $arr['error_code'] = $_REQUEST['error_code'];
        print(json_encode($arr));
    }

    public static function start_output_json_array(): void
    {
        self::addHeader("Content-Type", "application/json");
        $arr = $_REQUEST['output'] ?? [];
        if (isset($_REQUEST['messages']))
            $arr['messages'] = $_REQUEST['messages'];
        print(json_encode($arr));
    }

    public static function getStringParam(String $name): ?String
    {
        return isset($_REQUEST[$name]) ? filter_var($_REQUEST[$name]) : null;
    }

    public static function getArrayParam(String $name): array
    {
        if (!isset($_REQUEST[$name])) return [];
        if (is_string($_REQUEST[$name])) return [$_REQUEST[$name]];
        return $_REQUEST[$name];
    }

    public static function getIntParam(String $name): ?int
    {
        return isset($_REQUEST[$name]) ? filter_var($_REQUEST[$name], FILTER_VALIDATE_INT) : null;
    }

    public static function getFloatParam(String $name): ?float
    {
        return isset($_REQUEST[$name]) ? filter_var($_REQUEST[$name], FILTER_VALIDATE_FLOAT) : null;
    }

    public static function getBooleanParam(String $name): ?bool
    {
        return isset($_REQUEST[$name]) ? filter_var($_REQUEST[$name], FILTER_VALIDATE_BOOLEAN) : null;
    }

    public static function getStringSessionParam(String $name): ?String
    {
        return isset($_SESSION[$name]) ? filter_var($_SESSION[$name]) : null;
    }

    public static function getSessionParam(String $name)
    {
        return isset($_SESSION[$name]) ? ($_SESSION[$name]) : null;
    }

    public static function getIntSessionParam(String $name): ?int
    {
        return isset($_SESSION[$name]) ? intval($_SESSION[$name]) : null;
    }

    public static function setSessionParam(String $name, $value): void
    {
        $_SESSION[$name] = $value;
    }

    public static function getFloatSessionParam(String $name): ?float
    {
        return isset($_SESSION[$name]) ? floatval($_SESSION[$name]) : null;
    }

    public static function endSession(): void
    {
        $key = 'PHPSESSID';
        if (isset($_COOKIE[$key])) {
            unset($_COOKIE[$key]);
            setcookie($key, "", -1, '/');
        }
        session_destroy();
    }

    public static function getHeader(string $propertyName): ?string
    {
        return $_SERVER['HTTP_' + strtoupper($propertyName)] ?? null;
    }

    /**
     * Get the Authorization header from the request
     * Returns the raw header value or null if not found
     */
    public static function getAuthorizationHeader(): ?string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

        if (!$authHeader && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        }

        return $authHeader;
    }

    /**
     * Extract user id from Authorization Bearer header.
     * Returns the author ID or throws if not found/invalid.
     */
    public static function get_mandatory_author(): string
    {
        $authHeader = self::getAuthorizationHeader();

        if ($authHeader === null) {
            throw new WSException("No authorization header", 401);
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            throw new WSException("Invalid authorization header with no Bearer prefix", 401);
        }

        $parts = explode(' ', $authHeader);
        if (count($parts) < 2) {
            throw new WSException("Invalid authorization header with no token", 401);
        }

        $token = $parts[1];
        if (!HashId::isValid($token)) {
            throw new WSException("Invalid authorization token", 401);
        }

        return $token;
    }

    /**
     * Extract user id from Authorization Bearer header.
     * Returns the author ID or throws if not found/invalid.
     */
    public static function get_author(): ?string
    {
        $authHeader = self::getAuthorizationHeader();

        if ($authHeader === null) {
            return null;
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $parts = explode(' ', $authHeader);
        if (count($parts) < 2) {
            return null;
        }

        return $parts[1];
    }

    public static function addHeader(string $propertyName, string $propertyValue): void
    {
        if (!headers_sent()) {
            header($propertyName . ": " . $propertyValue);
        }
    }

    public static function init_server_events(): void
    {
        ini_set('output_buffering', 'off');
        ini_set('zlib.output_compression', 'off');
        ini_set('implicit_flush', 'on');
        ob_implicit_flush(true);

        // Clear any existing output buffers
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        self::addHeader("Content-Type", "text/event-stream");
        self::addHeader("Transfer-Encoding", "chunked");
        self::addHeader("Connection", "keep-alive");
        self::addHeader("X-Accel-Buffering", "no");
        set_time_limit(0);
    }

    public static function send_chunk(string $chunk)
    {
        $length = strlen($chunk);
        $hexLen = dechex($length);
        echo "$hexLen\r\n";
        echo $chunk . "\r\n";
        @flush();
    }

    public static function send_event(mixed $event)
    {
        self::send_chunk("data: " . json_encode($event) . "\n\n");
    }

    /**
     * Set CORS headers for cross-origin requests
     * Allows requests from localhost and same domain origins
     */
    public static function setCorsHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        // Get the Origin header from the request
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (!$origin) {
            return;
        }

        // Get the server name/host
        $serverName = $_SERVER['SERVER_NAME'] ?? 'corrai.local';
        $requestHost = $_SERVER['HTTP_HOST'] ?? $serverName;
        // Remove port from request host if present
        $requestHost = preg_replace('/:\d+$/', '', $requestHost);

        // Determine if CORS should be allowed
        $allowCors = false;

        // Check if origin matches the server domain or localhost
        $originHost = parse_url($origin, PHP_URL_HOST);

        if ($originHost) {
            // Remove port from origin host if present
            $originHostNoPort = preg_replace('/:\d+$/', '', $originHost);

            // Allow if same domain
            if ($originHostNoPort === $serverName || $originHostNoPort === $requestHost) {
                $allowCors = true;
            }

            // Allow localhost variations
            if (in_array($originHostNoPort, ['localhost', '127.0.0.1', '::1'])) {
                $allowCors = true;
            }
        }

        // Set CORS headers if allowed
        if ($allowCors) {
            self::addHeader("Access-Control-Allow-Origin", $origin);
            self::addHeader("Access-Control-Allow-Methods", "GET, POST, PUT, DELETE, OPTIONS");
            self::addHeader("Access-Control-Allow-Headers", "Content-Type, Authorization, X-Requested-With");
            self::addHeader("Access-Control-Allow-Credentials", "true");
            self::addHeader("Access-Control-Max-Age", "86400"); // 24 hours
        }
    }
}
