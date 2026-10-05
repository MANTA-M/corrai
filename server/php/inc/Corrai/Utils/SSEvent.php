<?php

namespace Corrai\Utils;

/**
 * Publishes a JSON payload on an Nchan channel.
 *
 * Assessment pages listen on assessment:{id}. Student pages listen on student:{id}.
 */
class SSEvent
{
    public static function assessmentChannel(string $assessmentId): string
    {
        return 'assessment:' . $assessmentId;
    }

    public static function studentChannel(string $studentId): string
    {
        return 'student:' . $studentId;
    }

    /**
     * POST $payload as JSON. A failed publish is logged and does not throw.
     *
     * @param array<string, mixed> $payload
     */
    public static function publish(string $channel, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            error_log('SSEvent: failed to encode payload for ' . $channel);
            return;
        }

        $base = rtrim(self::env('NCHAN_PUBLISH_URL', 'https://127.0.0.1/corrai_test/internal/pub'), '/');
        $url = $base . '/' . rawurlencode($channel);
        $host = self::env('NCHAN_PUBLISH_HOST', 'mantam.eu');

        $ch = curl_init($url);
        if ($ch === false) {
            error_log('SSEvent: curl_init failed for ' . $channel);
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Host: ' . $host,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            error_log('SSEvent: publish ' . $channel . ' failed: ' . $error);
            return;
        }
        if ($status < 200 || $status >= 300) {
            $detail = is_string($body) ? trim($body) : '';
            error_log('SSEvent: publish ' . $channel . ' returned HTTP ' . $status . ($detail !== '' ? ' ' . $detail : ''));
        }
    }

    private static function env(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if (!is_string($value) || $value === '') {
            return $default;
        }
        return $value;
    }
}
