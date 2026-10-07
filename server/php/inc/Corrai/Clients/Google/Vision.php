<?php

namespace Corrai\Clients\Google;

use Corrai\Clients\LlmClient;
use Corrai\Model\InputFile;
use Corrai\Utils\Http\JsonUtils;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Utils;
use InvalidArgumentException;

/**
 * Google Cloud Vision OCR, using a service account in server/google-keys.json.
 *
 * Drop-in replacement for Corrai\Clients\Eden\GoogleOCRClient: set_file(),
 * set_remote_file(), set_language(), and process() return the same shape.
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
 * Images use images:annotate. PDF and TIFF use files:annotate (five pages per call).
 */
class Vision extends LlmClient
{
    public const IMAGES_ANNOTATE_URL = 'https://vision.googleapis.com/v1/images:annotate';
    public const FILES_ANNOTATE_URL = 'https://vision.googleapis.com/v1/files:annotate';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const SCOPE = 'https://www.googleapis.com/auth/cloud-vision';
    public const FEATURE = 'DOCUMENT_TEXT_DETECTION';
    public const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 Mo (10 MB)
    public const LOG_RESPONSE = true;

    private const PDF_PAGES_PER_REQUEST = 5;
    private const TOKEN_LIFETIME = 3600;

    /** @var array{client_email: string, private_key: string, token_uri: string}|null */
    private static ?array $cachedCredentials = null;

    private static ?string $cachedToken = null;

    private static int $cachedTokenExpiresAt = 0;

    /**
     * @var array{mime: string, bytes: string}|null
     */
    private ?array $inline = null;

    /**
     * http(s) or gs:// URI. Used when the file is not inlined.
     */
    private ?string $remoteUri = null;

    private ?string $remoteMime = null;

    private ?string $language = null;

    private string $keysPath;

    public function __construct(?string $keysPath = null)
    {
        parent::__construct('');
        $this->disableSslVerif = false;
        $envPath = $_ENV['GOOGLE_APPLICATION_CREDENTIALS']
            ?? $_ENV['GOOGLE_KEYS_PATH']
            ?? (getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: null)
            ?? (getenv('GOOGLE_KEYS_PATH') ?: null);
        $this->keysPath = $keysPath ?? $envPath ?? dirname(__DIR__, 5) . '/google-keys.json';
    }

    /**
     * Read a local PDF or image. Same argument order as GoogleOCRClient::set_file().
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

            $this->inline = ['mime' => $mime, 'bytes' => $bytes];
            $this->remoteUri = null;
            $this->remoteMime = null;
        } finally {
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * http(s) or gs:// URI Vision can read. Images are sent as imageUri.
     * PDF and TIFF http(s) URLs are downloaded, because files:annotate has no HTTP source.
     */
    public function set_remote_file(string $file_id_or_url): void
    {
        $file = trim($file_id_or_url);
        if ($file === '') {
            throw new InvalidArgumentException('OCR file is required');
        }
        if (!preg_match('#^(https?://|gs://)#i', $file)) {
            throw new InvalidArgumentException('OCR file must be an http(s) or gs:// URL');
        }
        $this->remoteUri = $file;
        $this->remoteMime = $this->mimeForName(parse_url($file, PHP_URL_PATH) ?: $file);
        $this->inline = null;
    }

    /**
     * BCP-47 language hint, sent as imageContext.languageHints.
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
        if ($this->inline === null && $this->remoteUri === null) {
            throw new InvalidArgumentException('OCR file is required');
        }

        $this->token = $this->accessToken();
        $mime = $this->inline['mime'] ?? $this->remoteMime ?? 'application/octet-stream';
        if ($this->isDocument($mime)) {
            return $this->annotateDocument($mime);
        }
        return $this->annotateImage();
    }

    protected function accessToken(): string
    {
        $credentials = $this->credentials();
        $now = time();
        if (
            self::$cachedToken !== null
            && self::$cachedCredentials !== null
            && self::$cachedCredentials['client_email'] === $credentials['client_email']
            && self::$cachedTokenExpiresAt > $now + 60
        ) {
            return self::$cachedToken;
        }

        $token = $this->fetchAccessToken($credentials, $now);
        self::$cachedCredentials = $credentials;
        self::$cachedToken = $token['access_token'];
        self::$cachedTokenExpiresAt = $now + $token['expires_in'];
        return self::$cachedToken;
    }

    /**
     * Fail as soon as Vision is used without server/google-keys.json.
     */
    private function requireCredentialsFile(): void
    {
        if (!is_file($this->keysPath)) {
            throw new MissingCredentialsException(
                'Google Vision credentials file not found: ' . $this->keysPath
            );
        }
        if (!is_readable($this->keysPath)) {
            throw new MissingCredentialsException(
                'Google Vision credentials file is not readable: ' . $this->keysPath
            );
        }
    }

    /**
     * @return array{client_email: string, private_key: string, token_uri: string}
     */
    private function credentials(): array
    {
        $raw = $_ENV['GOOGLE_KEYS_JSON'] ?? (getenv('GOOGLE_KEYS_JSON') ?: null);
        if (!is_string($raw) || $raw === '') {
            $this->requireCredentialsFile();
            $raw = file_get_contents($this->keysPath);
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new \Exception('Google Vision credentials are not valid JSON');
        }
        $email = $decoded['client_email'] ?? null;
        $key = $decoded['private_key'] ?? null;
        if (!is_string($email) || $email === '' || !is_string($key) || $key === '') {
            throw new \Exception('Google Vision credentials must include client_email and private_key');
        }
        $tokenUri = $decoded['token_uri'] ?? self::TOKEN_URL;
        if (!is_string($tokenUri) || $tokenUri === '') {
            $tokenUri = self::TOKEN_URL;
        }
        return [
            'client_email' => $email,
            'private_key' => $key,
            'token_uri' => $tokenUri,
        ];
    }

    /**
     * @param array{client_email: string, private_key: string, token_uri: string} $credentials
     * @return array{access_token: string, expires_in: int}
     */
    private function fetchAccessToken(array $credentials, int $now): array
    {
        $header = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = $this->base64Url((string) json_encode([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $credentials['token_uri'],
            'iat' => $now,
            'exp' => $now + self::TOKEN_LIFETIME,
        ]));
        $unsigned = $header . '.' . $claim;
        $signature = '';
        $signed = openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);
        if ($signed !== true) {
            throw new \Exception('Failed to sign the Google Vision access token');
        }
        $assertion = $unsigned . '.' . $this->base64Url($signature);

        $curl = curl_init($credentials['token_uri']);
        if ($curl === false) {
            throw new \Exception('Failed to start the Google Vision token request');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => $this->timeout > 0 ? $this->timeout : 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]),
        ]);
        $response = curl_exec($curl);
        $responseCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $this->lastResponseCode = $responseCode;
        if ($this->shouldLog() || $responseCode < 200 || $responseCode > 299) {
            $this->logResponse($response, $responseCode, true);
        }
        if ($response === false) {
            $error = curl_error($curl) ?: 'Unknown error';
            curl_close($curl);
            throw new \Exception($error);
        }
        curl_close($curl);

        $body = is_string($response) ? $response : '';
        $decoded = JsonUtils::decodeArray($body);
        if ($responseCode < 200 || $responseCode > 299 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['error_description'] ?? $decoded['error'] ?? null) : null;
            throw new \Exception(is_string($message) && $message !== '' ? $message : 'Google Vision token request failed', $responseCode);
        }
        $accessToken = $decoded['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new \Exception('Google Vision token response has no access_token');
        }
        $expiresIn = $decoded['expires_in'] ?? self::TOKEN_LIFETIME;
        return [
            'access_token' => $accessToken,
            'expires_in' => is_int($expiresIn) ? $expiresIn : self::TOKEN_LIFETIME,
        ];
    }

    /**
     * @return array{text: string, bounding_boxes: list<array<string, mixed>>, usage: null}
     */
    private function annotateImage(): array
    {
        $image = [];
        if ($this->inline !== null) {
            $image['content'] = base64_encode($this->inline['bytes']);
        } else {
            $image['source'] = ['imageUri' => $this->remoteUri];
        }

        $request = [
            'image' => $image,
            'features' => [['type' => self::FEATURE]],
        ];
        $this->applyLanguage($request);

        $response = $this->QueryArray(self::IMAGES_ANNOTATE_URL, 'POST', $this->common_headers, [
            'requests' => [$request],
        ]);
        if ($response === null) {
            throw new \Exception('Empty OCR response');
        }
        $annotation = $response['responses'][0] ?? null;
        if (!is_array($annotation)) {
            throw new \Exception('Empty OCR response');
        }
        return $this->outputFromAnnotations([$annotation]);
    }

    /**
     * @return array{text: string, bounding_boxes: list<array<string, mixed>>, usage: null}
     */
    private function annotateDocument(string $mime): array
    {
        $source = $this->documentSource($mime);
        $first = $this->annotateDocumentPages($source, [1]);
        $total = $first['totalPages'] ?? 1;
        if (!is_int($total) || $total < 1) {
            $total = 1;
        }
        $annotations = $first['annotations'];
        for ($start = 2; $start <= $total; $start += self::PDF_PAGES_PER_REQUEST) {
            $pages = range($start, min($total, $start + self::PDF_PAGES_PER_REQUEST - 1));
            $batch = $this->annotateDocumentPages($source, $pages);
            $annotations = array_merge($annotations, $batch['annotations']);
        }
        return $this->outputFromAnnotations($annotations);
    }

    /**
     * @param array{mimeType: string, content?: string, gcsSource?: array{uri: string}} $source
     * @param list<int> $pages
     * @return array{totalPages: int, annotations: list<array<string, mixed>>}
     */
    private function annotateDocumentPages(array $source, array $pages): array
    {
        $request = [
            'inputConfig' => $source,
            'features' => [['type' => self::FEATURE]],
            'pages' => $pages,
        ];
        $this->applyLanguage($request);

        $response = $this->QueryArray(self::FILES_ANNOTATE_URL, 'POST', $this->common_headers, [
            'requests' => [$request],
        ]);
        if ($response === null) {
            throw new \Exception('Empty OCR response');
        }
        $fileResponse = $response['responses'][0] ?? null;
        if (!is_array($fileResponse)) {
            throw new \Exception('Empty OCR response');
        }
        if (isset($fileResponse['error'])) {
            throw new \Exception($this->googleError($fileResponse['error']));
        }
        $annotations = $fileResponse['responses'] ?? null;
        if (!is_array($annotations)) {
            throw new \Exception('Empty OCR response');
        }
        $pageAnnotations = [];
        foreach ($annotations as $annotation) {
            if (is_array($annotation)) {
                $pageAnnotations[] = $annotation;
            }
        }
        $totalPages = $fileResponse['totalPages'] ?? 1;
        return [
            'totalPages' => is_int($totalPages) ? $totalPages : 1,
            'annotations' => $pageAnnotations,
        ];
    }

    /**
     * @return array{mimeType: string, content?: string, gcsSource?: array{uri: string}}
     */
    private function documentSource(string $mime): array
    {
        if ($this->inline !== null) {
            return [
                'mimeType' => $mime,
                'content' => base64_encode($this->inline['bytes']),
            ];
        }

        $uri = $this->remoteUri ?? '';
        if (str_starts_with(strtolower($uri), 'gs://')) {
            return [
                'mimeType' => $mime,
                'gcsSource' => ['uri' => $uri],
            ];
        }

        $bytes = $this->download($uri);
        if (strlen($bytes) > self::MAX_FILE_SIZE) {
            throw new \Exception('Image file too heavy');
        }
        return [
            'mimeType' => $mime,
            'content' => base64_encode($bytes),
        ];
    }

    /**
     * @param list<array<string, mixed>> $annotations
     * @return array{text: string, bounding_boxes: list<array<string, mixed>>, usage: null}
     */
    protected function outputFromAnnotations(array $annotations): array
    {
        $texts = [];
        $boxes = [];
        foreach ($annotations as $index => $annotation) {
            if (isset($annotation['error'])) {
                throw new \Exception($this->googleError($annotation['error']));
            }
            $pageNumber = $annotation['context']['pageNumber'] ?? null;
            $pageIndex = is_int($pageNumber) && $pageNumber >= 1 ? $pageNumber - 1 : $index;

            $full = $annotation['fullTextAnnotation'] ?? null;
            if (!is_array($full)) {
                continue;
            }
            if (isset($full['text']) && is_string($full['text']) && $full['text'] !== '') {
                $texts[] = $full['text'];
            }
            foreach ($full['pages'] ?? [] as $page) {
                if (!is_array($page)) {
                    continue;
                }
                foreach ($this->wordsFromPage($page, $pageIndex) as $word) {
                    $boxes[] = $word;
                }
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
            error_log(sprintf("[Vision] Processed OCR Result (HTTP %d):\n%s", $code, $formatted !== false ? $formatted : ''));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $page
     * @return list<array{text: string, left: float, top: float, width: float, height: float, page: int}>
     */
    private function wordsFromPage(array $page, int $pageIndex): array
    {
        $width = $this->dimension($page['width'] ?? null);
        $height = $this->dimension($page['height'] ?? null);
        $words = [];
        foreach ($page['blocks'] ?? [] as $block) {
            if (!is_array($block)) {
                continue;
            }
            foreach ($block['paragraphs'] ?? [] as $paragraph) {
                if (!is_array($paragraph)) {
                    continue;
                }
                foreach ($paragraph['words'] ?? [] as $word) {
                    if (!is_array($word)) {
                        continue;
                    }
                    $text = '';
                    foreach ($word['symbols'] ?? [] as $symbol) {
                        if (is_array($symbol) && isset($symbol['text']) && is_string($symbol['text'])) {
                            $text .= $symbol['text'];
                        }
                    }
                    if ($text === '') {
                        continue;
                    }
                    $box = $word['boundingBox'] ?? [];
                    $fraction = $this->fractionBox(is_array($box) ? $box : [], $width, $height);
                    $words[] = [
                        'text' => $text,
                        'left' => $fraction['left'],
                        'top' => $fraction['top'],
                        'width' => $fraction['width'],
                        'height' => $fraction['height'],
                        'page' => $pageIndex,
                    ];
                }
            }
        }
        return $words;
    }

    /**
     * @param array<string, mixed> $poly
     * @return array{left: float, top: float, width: float, height: float}
     */
    private function fractionBox(array $poly, float $pageWidth, float $pageHeight): array
    {
        $normalized = $poly['normalizedVertices'] ?? null;
        $useNormalized = is_array($normalized) && $normalized !== [];
        $vertices = $useNormalized ? $normalized : ($poly['vertices'] ?? []);
        if (!is_array($vertices)) {
            $vertices = [];
        }

        $xs = [];
        $ys = [];
        foreach ($vertices as $vertex) {
            if (!is_array($vertex)) {
                continue;
            }
            $xs[] = $this->dimension($vertex['x'] ?? 0);
            $ys[] = $this->dimension($vertex['y'] ?? 0);
        }
        if ($xs === []) {
            return ['left' => 0.0, 'top' => 0.0, 'width' => 0.0, 'height' => 0.0];
        }

        $minX = min($xs);
        $maxX = max($xs);
        $minY = min($ys);
        $maxY = max($ys);
        if ($useNormalized) {
            return [
                'left' => $minX,
                'top' => $minY,
                'width' => $maxX - $minX,
                'height' => $maxY - $minY,
            ];
        }
        if ($pageWidth <= 0 || $pageHeight <= 0) {
            throw new \Exception('OCR response has no page size');
        }
        return [
            'left' => $minX / $pageWidth,
            'top' => $minY / $pageHeight,
            'width' => ($maxX - $minX) / $pageWidth,
            'height' => ($maxY - $minY) / $pageHeight,
        ];
    }

    /**
     * @param array<string, mixed> $request
     */
    private function applyLanguage(array &$request): void
    {
        if ($this->language !== null) {
            $request['imageContext'] = ['languageHints' => [$this->language]];
        }
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

    private function isDocument(string $mime): bool
    {
        return $mime === 'application/pdf' || $mime === 'image/tiff';
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

    private function googleError(mixed $error): string
    {
        if (is_string($error) && $error !== '') {
            return $error;
        }
        if (is_array($error)) {
            $message = $error['message'] ?? null;
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }
        return 'OCR failed';
    }

    private function dimension(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        return 0.0;
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
