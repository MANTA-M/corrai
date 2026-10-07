<?php

namespace Corrai\Clients\Mindee;

use Corrai\Clients\LlmClient;
use Corrai\Model\InputFile;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Utils;
use InvalidArgumentException;
use Mindee\Input\PathInput;
use Mindee\Input\UrlInputSource;
use Mindee\V2\Client;
use Mindee\V2\Product\Ocr\OcrResponse;
use Mindee\V2\Product\Ocr\Params\OcrParameters;

/**
 * Mindee raw text OCR.
 *
 * Same call sequence as Corrai\Clients\Google\Vision: set_file(),
 * set_remote_file(), set_language(), and process(). process() returns:
 *
 * {
 *   "text": "Une makine",
 *   "bounding_boxes": [
 *     {"text": "Une", "left": 0.04, "top": 0.04, "width": 0.08, "height": 0.02, "page": 0}
 *   ],
 *   "usage": null
 * }
 *
 * Coordinates are fractions of the page, origin top-left. `page` is zero-based.
 * The call follows https://docs.mindee.com/raw-text-ocr-models/sdk-integration/ocr-quick-start :
 * Client, OcrParameters, PathInput or UrlInputSource, then enqueueAndGetResult().
 * The API key is MINDEE_API_KEY. The model id is MINDEE_OCR_MODEL_ID, or the
 * account raw-text model when that variable is unset.
 */
class MindeeOCR extends LlmClient
{
    public const DEFAULT_MODEL_ID = '437173fd-6284-4051-8bc9-8e811b52c0a6';
    public const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 Mo (10 MB)
    public const LOG_RESPONSE = true;

    /**
     * @var array{mime: string, bytes: string, name: string}|null
     */
    private ?array $inline = null;

    /**
     * https URL Mindee can fetch, or an http URL downloaded before enqueue.
     */
    private ?string $remoteUrl = null;

    private ?string $language = null;

    private string $modelId;

    public function __construct(?string $apiKey = null, ?string $modelId = null)
    {
        $key = $apiKey;
        if ($key === null) {
            $fromEnv = $_ENV['MINDEE_API_KEY'] ?? (getenv('MINDEE_API_KEY') ?: '');
            $key = is_string($fromEnv) ? $fromEnv : '';
        }
        parent::__construct('', $key);
        $this->disableSslVerif = false;

        $model = $modelId;
        if ($model === null || $model === '') {
            $fromEnv = $_ENV['MINDEE_OCR_MODEL_ID'] ?? (getenv('MINDEE_OCR_MODEL_ID') ?: '');
            $model = is_string($fromEnv) ? $fromEnv : '';
        }
        $this->modelId = $model !== '' ? $model : self::DEFAULT_MODEL_ID;
    }

    /**
     * Read a local PDF or image. Same argument order as Vision::set_file().
     *
     * @param string|InputFile $file_path Local path to file or an InputFile instance.
     * @param string|InputFile|null $file_name File name (or InputFile if path and file are given).
     * @param InputFile|null $inputFile Optional InputFile instance when file_path is a string path.
     */
    public function set_file(
        string|InputFile $file_path,
        string|InputFile|null $file_name = null,
        ?InputFile $inputFile = null
    ): void {
        $actualInputFile = null;
        $tempPath = null;

        if ($file_path instanceof InputFile) {
            $actualInputFile = $file_path;
            if (is_string($file_name) && is_file($file_name)) {
                $actualPath = $file_name;
                $file_name = $actualInputFile->name;
            } else {
                $actualPath = null;
                $file_name = is_string($file_name) && $file_name !== '' ? $file_name : $actualInputFile->name;
            }
        } else {
            $actualPath = $file_path;
            if ($file_name instanceof InputFile) {
                $actualInputFile = $file_name;
                $file_name = $actualInputFile->name;
            } elseif ($inputFile instanceof InputFile) {
                $actualInputFile = $inputFile;
            }
            if (!is_string($file_name) || $file_name === '') {
                $file_name = basename($file_path);
            }
        }

        if ($actualInputFile !== null && $actualInputFile->size > self::MAX_FILE_SIZE) {
            $this->rejectHeavyFile($actualInputFile);
        }

        try {
            if ($actualPath === null) {
                $store = ObjectStore::getInstance();
                $tempPath = $store->downloadToTemp($actualInputFile->contentKey());
                $actualPath = $tempPath;
            }

            if (!is_readable($actualPath)) {
                throw new \Exception("Cannot read file: $file_name");
            }

            $size = filesize($actualPath);
            if ($size !== false && $size > self::MAX_FILE_SIZE) {
                $this->rejectHeavyFile($actualInputFile);
            }

            $mime = $this->mimeForName($file_name);
            $bytes = file_get_contents($actualPath);
            if ($bytes === false) {
                throw new \Exception("Cannot read file: $file_name");
            }

            $this->inline = ['mime' => $mime, 'bytes' => $bytes, 'name' => $file_name];
            $this->remoteUrl = null;
        } finally {
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * http(s) URL. https is sent as a Mindee URL input. http is downloaded first,
     * because UrlInputSource accepts https only.
     */
    public function set_remote_file(string $file_id_or_url): void
    {
        $file = trim($file_id_or_url);
        if ($file === '') {
            throw new InvalidArgumentException('OCR file is required');
        }
        if (!preg_match('#^https?://#i', $file)) {
            throw new InvalidArgumentException('OCR file must be an http(s) URL');
        }
        $this->mimeForName(parse_url($file, PHP_URL_PATH) ?: $file);
        $this->remoteUrl = $file;
        $this->inline = null;
    }

    /**
     * BCP-47 language hint, same method as Vision::set_language().
     */
    public function set_language(string $language): void
    {
        $language = trim($language);
        if ($language === '') {
            throw new InvalidArgumentException('Language must not be empty');
        }
        $this->language = $language;
    }

    /**
     * Run OCR and return one word and its box for each recognized word.
     *
     * @return array{
     *   text: string,
     *   bounding_boxes: list<array{text: string, left: float, top: float, width: float, height: float, page: int}>,
     *   usage: null
     * }
     */
    public function process(): array
    {
        if ($this->inline === null && $this->remoteUrl === null) {
            throw new InvalidArgumentException('OCR file is required');
        }

        $pages = $this->requestPages();
        return $this->outputFromPages($pages);
    }

    /**
     * Enqueue the document and poll until Mindee returns the OCR pages.
     *
     * @return list<array{content: string, words: list<array{content: string, polygon: list<array{0: float, 1: float}>}>}>
     */
    protected function requestPages(): array
    {
        if ($this->token === '') {
            throw new InvalidArgumentException('MINDEE_API_KEY is required');
        }

        $cleanup = [];
        try {
            $input = $this->inputSource($cleanup);
            $client = new Client($this->token);
            $response = $client->enqueueAndGetResult(
                OcrResponse::class,
                $input,
                new OcrParameters($this->modelId)
            );
            $pages = $response->inference->result->pages ?? null;
            if (!is_array($pages) || $pages === []) {
                throw new \Exception('Empty OCR response');
            }

            $out = [];
            foreach ($pages as $page) {
                $words = [];
                foreach ($page->words as $word) {
                    $polygon = [];
                    foreach ($word->polygon->getCoordinates() ?? [] as $point) {
                        $polygon[] = [$point->getX(), $point->getY()];
                    }
                    $words[] = [
                        'content' => $word->content,
                        'polygon' => $polygon,
                    ];
                }
                $out[] = [
                    'content' => $page->content,
                    'words' => $words,
                ];
            }
            return $out;
        } finally {
            $this->cleanupPaths($cleanup);
        }
    }

    /**
     * @param list<string> $cleanup
     */
    private function inputSource(array &$cleanup): PathInput|UrlInputSource
    {
        if ($this->inline !== null) {
            return new PathInput($this->writeTempFile($this->inline['bytes'], $this->inline['name'], $cleanup));
        }

        $url = $this->remoteUrl ?? '';
        if (str_starts_with(strtolower($url), 'https://')) {
            return new UrlInputSource($url);
        }

        $bytes = $this->download($url);
        if (strlen($bytes) > self::MAX_FILE_SIZE) {
            throw new \Exception('Image file too heavy');
        }
        $name = basename(parse_url($url, PHP_URL_PATH) ?: 'document');
        return new PathInput($this->writeTempFile($bytes, $name, $cleanup));
    }

    /**
     * @param list<string> $cleanup
     */
    private function writeTempFile(string $bytes, string $fileName, array &$cleanup): string
    {
        $safeName = basename(str_replace('\\', '/', $fileName));
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            $safeName = 'document';
        }
        $dir = sys_get_temp_dir() . '/mindee_ocr_' . bin2hex(random_bytes(6));
        if (!mkdir($dir) && !is_dir($dir)) {
            throw new \Exception('Failed to prepare the OCR file');
        }
        $path = $dir . '/' . $safeName;
        if (file_put_contents($path, $bytes) === false) {
            @rmdir($dir);
            throw new \Exception("Cannot read file: $fileName");
        }
        $cleanup[] = $path;
        return $path;
    }

    /**
     * @param list<string> $paths
     */
    private function cleanupPaths(array $paths): void
    {
        $dirs = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
            $dirs[dirname($path)] = true;
        }
        foreach (array_keys($dirs) as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @return array{text: string, bounding_boxes: list<array<string, mixed>>, usage: null}
     */
    private function outputFromPages(array $pages): array
    {
        if ($pages === []) {
            throw new \Exception('Empty OCR response');
        }

        $texts = [];
        $boxes = [];
        foreach ($pages as $index => $page) {
            if (!is_array($page)) {
                continue;
            }
            $content = $page['content'] ?? null;
            if (is_string($content) && $content !== '') {
                $texts[] = $content;
            }
            foreach ($page['words'] ?? [] as $word) {
                if (!is_array($word)) {
                    continue;
                }
                $text = $word['content'] ?? null;
                if (!is_string($text) || $text === '') {
                    continue;
                }
                $fraction = $this->fractionBox($word['polygon'] ?? []);
                $boxes[] = [
                    'text' => $text,
                    'left' => $fraction['left'],
                    'top' => $fraction['top'],
                    'width' => $fraction['width'],
                    'height' => $fraction['height'],
                    'page' => $index,
                ];
            }
        }

        if ($texts === []) {
            throw new \Exception('OCR response has no text');
        }
        if ($boxes === []) {
            throw new \Exception('OCR response has no bounding boxes');
        }

        $result = [
            'text' => implode("\n", $texts),
            'bounding_boxes' => $boxes,
            'usage' => null,
        ];

        if (self::LOG_RESPONSE) {
            $code = $this->lastResponseCode ?: 200;
            $formatted = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            error_log(sprintf("[MindeeOCR] Processed OCR Result (HTTP %d):\n%s", $code, $formatted !== false ? $formatted : ''));
        }

        return $result;
    }

    /**
     * Mindee word polygons are already fractions of the page: [x, y] pairs.
     *
     * @return array{left: float, top: float, width: float, height: float}
     */
    private function fractionBox(mixed $polygon): array
    {
        $empty = ['left' => 0.0, 'top' => 0.0, 'width' => 0.0, 'height' => 0.0];
        if (!is_array($polygon) || $polygon === []) {
            return $empty;
        }

        $xs = [];
        $ys = [];
        foreach ($polygon as $point) {
            if (!is_array($point)) {
                continue;
            }
            $x = $point[0] ?? $point['x'] ?? null;
            $y = $point[1] ?? $point['y'] ?? null;
            if (!is_numeric($x) || !is_numeric($y)) {
                continue;
            }
            $xs[] = (float) $x;
            $ys[] = (float) $y;
        }
        if ($xs === []) {
            return $empty;
        }

        $minX = min($xs);
        $maxX = max($xs);
        $minY = min($ys);
        $maxY = max($ys);
        return [
            'left' => $minX,
            'top' => $minY,
            'width' => $maxX - $minX,
            'height' => $maxY - $minY,
        ];
    }

    private function download(string $url): string
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \Exception('Failed to download the OCR file');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => $this->timeout > 0 ? $this->timeout : 30,
        ]);
        $bytes = curl_exec($curl);
        $responseCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $this->lastResponseCode = $responseCode;
        if ($bytes === false) {
            $error = curl_error($curl) ?: 'Unknown error';
            curl_close($curl);
            throw new \Exception($error);
        }
        curl_close($curl);
        if ($responseCode < 200 || $responseCode > 299 || !is_string($bytes)) {
            throw new \Exception('Failed to download the OCR file', $responseCode);
        }
        return $bytes;
    }

    private function mimeForName(string $file_name): string
    {
        $mime = strtolower(trim(explode(';', Utils::mimeTypeForFilename($file_name))[0]));
        if ($mime !== 'application/pdf' && !str_starts_with($mime, 'image/')) {
            throw new InvalidArgumentException("Unsupported OCR file type: $file_name");
        }
        return $mime;
    }

    private function rejectHeavyFile(?InputFile $file): void
    {
        if ($file !== null) {
            try {
                $file->appendEvent('Image file too heavy');
            } catch (\Throwable $e) {
                error_log($e->getMessage());
            }
        }
        throw new \Exception('Image file too heavy');
    }
}
