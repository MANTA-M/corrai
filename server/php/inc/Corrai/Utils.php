<?php

namespace Corrai;

use \Exception;

class Utils
{
    /**
     * Tests if a directory path is writable.
     */
    public static function testDirPath(string $path, bool $throw = false): bool
    {
        if (!file_exists($path)) {
            $msg = "Directory does not exists: " . $path;
        } else if (!is_writable($path)) {
            $msg = "Directory cannot be written: " . $path;
        }
        if (isset($msg)) {
            if ($throw) {
                throw new Exception($msg);
            }
            error_log($msg);
            return false;
        }

        return true;
    }

    /**
     * Read key values pairs file into array
     */
    public static function loadKeyValueFile(string $path, array &$destination): void
    {
        if (!file_exists($path)) {
            throw new Exception("Value key file" . $path . " does not exists");
        }

        $fn = fopen($path, "r");

        while (!feof($fn)) {
            $line = fgets($fn);
            if ($line === false) {
                break;
            }

            $parsed = self::parseEnvLine($line);
            if ($parsed !== null) {
                $destination[$parsed[0]] = $parsed[1];
            }
        }
        fclose($fn);
    }

    /**
     * Parse a KEY=VALUE env line. Surrounding quotes are stripped so
     * values like S3_SECRET_KEY="a|b^c" match the unquoted secret.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function parseEnvLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            return null;
        }

        $eq = strpos($line, '=');
        if ($eq === false) {
            return null;
        }

        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        if ($key === '') {
            return null;
        }

        $len = strlen($value);
        $quoted = $len >= 2
            && (
                ($value[0] === '"' && $value[$len - 1] === '"')
                || ($value[0] === "'" && $value[$len - 1] === "'")
            );

        if ($quoted) {
            $value = substr($value, 1, $len - 2);
        } else {
            $hashPos = strpos($value, '#');
            if ($hashPos !== false) {
                $value = rtrim(substr($value, 0, $hashPos));
            }
        }

        return [$key, $value];
    }

    /**
     * Cast stdClass to concrete object instance.
     */
    public static function recast($className, object $object)
    {
        if (!class_exists($className))
            throw new Exception(sprintf('Inexistant class %s.', $className));

        $new = new $className();

        foreach ($object as $property => &$value) {
            $new->$property = &$value;
        }
        return $new;
    }

    public static function objectPropertiesCopy(object $source, object &$destination): object
    {
        foreach ($source as $property => $value) {
            $destination->{$property} = $value;
        }
        return $destination;
    }

    public static function getOrCreateOutputDir(string $path): string
    {
        if (!file_exists($path)) {
            if (!@mkdir($path, 0775, true)) {
                $error = error_get_last();
                $errorMsg = $error ? $error['message'] : 'Unknown error';
                throw new Exception("Cannot create directory " . $path . ": " . $errorMsg);
            }
            // Try to set ownership to www-data if possible (may fail on volume mounts)
            @chown($path, 'www-data');
            @chgrp($path, 'www-data');
        } else if (!is_writable($path)) {
            throw new Exception("Cannot write file in " . $path);
        }

        return $path;
    }

    public static function getLogDir(): string
    {
        $path = rtrim($_ENV['LOG_DIR'] ?? '/var/log/corrai', '/') . '/';
        return self::getOrCreateOutputDir($path);
    }


    public static function camelCase($str, array $noStrip = [])
    {
        // non-alpha and non-numeric characters become spaces
        $str = preg_replace('/[^a-z0-9' . implode("", $noStrip) . ']+/i', ' ', $str);
        $str = trim($str);
        // uppercase the first character of each word
        $str = ucwords($str);
        $str = str_replace(" ", "", $str);
        $str = lcfirst($str);

        return $str;
    }

    public static function isInProduction(): bool
    {
        return ($_ENV['APP_ENV'] ?? '') === 'prod';
    }
    
    public static function safeEval($code): mixed
    {
        if (preg_match('/[a-zA-Z_]+\s*\(/i', $code)) {
            trigger_error("Error, unsafe eval: " . $code, E_USER_WARNING);
            throw new Exception("Error, unsafe eval: " . $code);
        }
        return eval('return ' . $code . ';');
    }

    public static function cleanXSS(string $html): string
    {
        return preg_replace(
            [
                '/\s?<iframe[^>]*?>.*?<\/iframe>\s?/si',
                '/\s?<style[^>]*?>.*?<\/style>\s?/si',
                '/\s?<script[^>]*?>.*?<\/script>\s?/si',
                '/&lt;\/?script[^&]*?&gt;/si',
                '#\son\w*="[^"]+"#',

            ],

            [
                '',
                '',
                ''
            ],

            $html

        );
    }

    public static function remove_null_values(array $data): array
    {
        $data = array_filter($data, function($value) {
            return $value !== null;
        });
        $data = array_map(function($value) {
            if (is_array($value)) {
                return self::remove_null_values($value);
            }
            return $value;
        }, $data);
        return $data;
    }

    /**
     * Resolve a MIME type for inline display (e.g. img src).
     * Known extensions win over a generic stored type such as application/octet-stream.
     */
    public static function mimeTypeForFilename(string $filename, ?string $storedType = null): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $byExtension = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp',
            'tif' => 'image/tiff',
            'tiff' => 'image/tiff',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain; charset=utf-8',
            'csv' => 'text/csv; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
            'htm' => 'text/html; charset=utf-8',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
        ];
        if ($ext !== '' && isset($byExtension[$ext])) {
            return $byExtension[$ext];
        }
        if (is_string($storedType) && $storedType !== '' && $storedType !== 'application/octet-stream') {
            return $storedType;
        }
        return 'application/octet-stream';
    }
}
