<?php

namespace Corrai\Utils\Image;

use Corrai\Utils\Http\JsonUtils;
use InvalidArgumentException;
use RuntimeException;

/**
 * Recognizes words and their boxes by sending file bytes to pycorrai.
 *
 * The file is written to the Python process standard input. pycorrai runs
 * with the interpreter from server/python/.venv.
 */
class PythonOCRHelper
{
    /**
     * @var list<array{text: string, page: int, box: array{0: int, 1: int, 2: int, 3: int}}>
     */
    public readonly array $words;

    /**
     * @param string $bytes Encoded image or PDF.
     * @param string $lang PaddleOCR language code.
     */
    public function __construct(string $bytes, string $lang = 'fr')
    {
        if ($bytes === '') {
            throw new InvalidArgumentException('File bytes must not be empty');
        }
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $lang)) {
            throw new InvalidArgumentException('Invalid OCR language code');
        }

        $this->words = self::recognize($bytes, $lang);
    }

    /**
     * Home directory for the OCR process, where PaddleX caches its models.
     *
     * php-fpm runs as www-data, whose home is not writable, so fall back to a
     * directory of our own. It is kept between calls: the models weigh over a
     * hundred megabytes and are downloaded on first use.
     *
     * @param array<string, string> $env
     */
    private static function writableHome(array $env): string
    {
        $home = $env['HOME'] ?? '';
        if (is_string($home) && $home !== '' && is_dir($home) && is_writable($home)) {
            return $home;
        }

        $fallback = sys_get_temp_dir() . '/corrai_ocr_' . posix_geteuid();
        if (!is_dir($fallback) && !@mkdir($fallback, 0700, true) && !is_dir($fallback)) {
            throw new RuntimeException("Failed to create the OCR cache directory: {$fallback}");
        }
        if (!is_writable($fallback)) {
            throw new RuntimeException("OCR cache directory is not writable: {$fallback}");
        }

        return $fallback;
    }

    /**
     * @return list<array{text: string, page: int, box: array{0: int, 1: int, 2: int, 3: int}}>
     */
    private static function recognize(string $bytes, string $lang): array
    {
        $python = dirname(__DIR__, 4) . '/python/.venv/bin/python';
        if (!is_executable($python)) {
            throw new RuntimeException("Python virtualenv not found: {$python}");
        }

        $project = dirname(__DIR__, 4) . '/python/pycorrai';
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $pythonPath = $project;
        if (isset($env['PYTHONPATH']) && $env['PYTHONPATH'] !== '') {
            $pythonPath .= PATH_SEPARATOR . $env['PYTHONPATH'];
        }
        $env['PYTHONPATH'] = $pythonPath;

        $home = self::writableHome($env);
        $env['HOME'] = $home;
        $cache = $env['PADDLE_PDX_CACHE_HOME'] ?? '';
        if (!is_string($cache) || $cache === '') {
            $env['PADDLE_PDX_CACHE_HOME'] = $home . '/.paddlex';
        }

        $stderrPath = tempnam(sys_get_temp_dir(), 'pycorrai_');
        if ($stderrPath === false) {
            throw new RuntimeException('Failed to create a temporary file for OCR errors');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $stderrPath, 'w'],
        ];
        $process = proc_open(
            [$python, '-m', 'pycorrai', '--lang', $lang],
            $descriptors,
            $pipes,
            null,
            $env
        );
        if (!is_resource($process)) {
            @unlink($stderrPath);
            throw new RuntimeException('Failed to start pycorrai');
        }

        try {
            fwrite($pipes[0], $bytes);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $exitCode = proc_close($process);
            $process = null;
            $stderr = file_get_contents($stderrPath);
        } finally {
            if (is_resource($process)) {
                proc_close($process);
            }
            @unlink($stderrPath);
        }

        if ($exitCode !== 0) {
            $detail = trim(is_string($stderr) ? $stderr : '');
            throw new RuntimeException(
                $detail === '' ? "pycorrai exited with status {$exitCode}" : $detail
            );
        }

        $decoded = JsonUtils::decodeArray(is_string($stdout) ? $stdout : null);
        if (!is_array($decoded)) {
            throw new RuntimeException('pycorrai did not return JSON');
        }

        return $decoded;
    }
}
