<?php

namespace Corrai\Subject\DictationFranceCM2;

use Corrai\Model\Exam;
use Corrai\Utils\OCR;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\ReferenceChanger;
use Corrai\Utils\WSException;
use Corrai\LlmClient\ClaudeSonnetClient;

class Pipeline
{
    public const SUBJECT = 'Dictation';
    public const LEVEL = 'CM2';
    public const COUNTRY = 'fr';
    public const NAMES = [
        'en' => 'Dictation CM2 France',
        'fr' => 'Dictée CM2 France',
        'ru' => 'Диктант CM2 Франция',
        'uk' => 'Диктант CM2 Франція',
        'es' => 'Dictado CM2 Francia',
        'pt' => 'Ditado CM2 Portugal',
        'ro' => 'Dictare CM2 Franța',
        'de' => 'Diktat CM2 Frankreich',
    ];

    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    /** Minimum half-length of each arm, in pixels. */
    private const MAGENTA_CROSS_HALF = 7;

    private const MAGENTA_CROSS_THICKNESS = 2;

    private const ERROR_BOX_THICKNESS = 3;

    /** PaddleOCR language code of the dictation. */
    private const OCR_LANG = 'fr';

    private ?int $imageWidth = null;
    private ?int $imageHeight = null;

    private ?string $errorBoxesPng = null;

    private ?ReferenceChanger $referenceChanger = null;

    /**
     * Compare a dictation copy to the corrigé, then draw the errors with GD.
     *
     * @return array Updated exam file list
     */
    public function run(Exam $exam, string $filename, string $language): array
    {
        $tags = $exam->loadFileTags();
        $student = $tags[$filename]['student'] ?? '';
        $languageName = trim($language) !== '' ? trim($language) : 'French';
        $base = pathinfo($filename, PATHINFO_FILENAME);

        $store = ObjectStore::getInstance();
        $key = $exam->unassignedFileKey($filename);
        $tmpPath = $store->downloadToTemp($key);
        $solutionPath = null;

        try {
            $exam->deleteFilesOfType('debug', $student);
            $exam->deleteFilesOfType('correction', $student);

            $size = @getimagesize($tmpPath);
            $this->imageWidth = is_array($size) ? (int) $size[0] : null;
            $this->imageHeight = is_array($size) ? (int) $size[1] : null;
            if ($this->imageWidth === null || $this->imageHeight === null
                || $this->imageWidth < 1 || $this->imageHeight < 1) {
                throw new WSException('The source file is not an image GD can annotate', 400);
            }

            $calibration_centers_in_image = $this->getCallibrationCrossCenters($this->imageWidth, $this->imageHeight);
            $marked = $this->markImage($tmpPath, $calibration_centers_in_image);
            $exam->createFile(
                $base . '_marked.' . $marked['extension'],
                $marked['bytes'],
                $marked['mime'],
                'debug',
                $student
            );

            $exam->createFile(
                $base . '_ocr.json',
                $this->ocrJson($marked['bytes']),
                'application/json',
                'debug',
                $student
            );

            $solution = $this->firstSolutionFile($exam);
            $solutionPath = $store->downloadToTemp($exam->unassignedFileKey($solution['name']));

            $correction = $this->findErrors(
                $exam,
                $tmpPath,
                $base . '_marked.' . $marked['extension'],
                $solutionPath,
                $solution['name'],
                $languageName
            );
            $calibration_centers_in_llm = $this->calibrationCentersInLlm($correction);
            $this->referenceChanger = new ReferenceChanger(
                $calibration_centers_in_llm,
                $calibration_centers_in_image
            );
            $exam->createFile(
                $base . ' correction.txt',
                $correction,
                'text/plain; charset=utf-8',
                'debug',
                $student
            );

            $directivesPhp = $this->gdDirectives($tmpPath, $base . '_marked.' . $marked['extension'], $correction);
            $exam->createFile(
                $base . ' directives.php',
                $directivesPhp,
                'text/plain; charset=utf-8',
                'debug',
                $student
            );

            if (is_string($this->errorBoxesPng) && $this->errorBoxesPng !== '') {
                $exam->createFile(
                    $base . '_boxes.png',
                    $this->errorBoxesPng,
                    'image/png',
                    'debug',
                    $student
                );
            }

            $png = $this->renderCorrection($tmpPath, $directivesPhp);
            $exam->createFile(
                $base . ' correction.png',
                $png,
                'image/png',
                'correction',
                $student
            );
        } catch (WSException $e) {
            throw $e;
        } catch (\Throwable $th) {
            throw new WSException($th->getMessage(), 400);
        } finally {
            @unlink($tmpPath);
            if ($solutionPath !== null) {
                @unlink($solutionPath);
            }
        }

        return $exam->list_files();
    }

    /**
     * Apply calibration marks on the copy and return the resulting image bytes.
     *
     * @return array{bytes: string, mime: string, extension: string}
     */
    private function markImage(string $imagePath, array $centers): array
    {
        $this->drawMagentaCrosses($imagePath, $centers);

        $bytes = file_get_contents($imagePath);
        if ($bytes === false) {
            throw new WSException('Cannot read the marked image', 500);
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new WSException('The marked file is not an image', 500);
        }
        $extension = image_type_to_extension((int) $info[2], false);
        if (!is_string($extension) || $extension === '') {
            $extension = 'png';
        }

        return [
            'bytes' => $bytes,
            'mime' => is_string($info['mime'] ?? null) ? $info['mime'] : 'image/png',
            'extension' => $extension,
        ];
    }

    /**
     * Recognize the words of the standardised copy as pretty-printed JSON.
     *
     * Boxes are pixels of the standardised image, so they share the frame of
     * the error boxes drawn later.
     */
    protected function ocrJson(string $imageBytes): string
    {
        $words = (new OCR($imageBytes, self::OCR_LANG))->words;
        $json = json_encode($words, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new WSException('Cannot encode the OCR result', 500);
        }

        return $json . "\n";
    }

    /**
     * Draw two pure-magenta calibration crosses at (1/3, 1/3) and (2/3, 2/3).
     */
    private function drawMagentaCrosses(string $imagePath, array $centers): void
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new WSException('PHP GD is not available', 500);
        }

        $bytes = file_get_contents($imagePath);
        if ($bytes === false) {
            throw new WSException('Cannot read the source image', 400);
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new WSException('The source file is not an image GD can annotate', 400);
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new WSException('The source file is not an image GD can annotate', 400);
        }

        try {
            if (!imageistruecolor($image)) {
                imagepalettetotruecolor($image);
            }
            imagealphablending($image, true);

            $magenta = imagecolorallocate($image, 255, 0, 255);
            if ($magenta === false) {
                throw new WSException('GD could not allocate a color', 500);
            }

            $width = imagesx($image);
            $height = imagesy($image);
            $geometry = $this->magentaCrossGeometry($width, $height);
            foreach ($centers as $center) {
                $this->drawMagentaCross(
                    $image,
                    (int) $center[0],
                    (int) $center[1],
                    $geometry['half'],
                    $geometry['thickness'],
                    $magenta
                );
            }

            imagesavealpha($image, false);
            if (imagepng($image, $imagePath) !== true) {
                throw new WSException('GD did not produce an image', 500);
            }
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * @return array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}
     */
    private function getCallibrationCrossCenters(int $width, int $height): array
    {
        return [
            [
                (int) round($width / 3),
                (int) round($height / 3),
            ],
            [
                (int) round(2 * $width / 3),
                (int) round(2 * $height / 3),
            ],
        ];
    }

    /**
     * Arm length grows with the page so the mark stays visible on a scan.
     *
     * @return array{half: int, thickness: int}
     */
    private function magentaCrossGeometry(int $width, int $height): array
    {
        $half = max(self::MAGENTA_CROSS_HALF, (int) round(min($width, $height) * 0.02));

        return [
            'half' => $half,
            'thickness' => self::MAGENTA_CROSS_THICKNESS,
        ];
    }

    private function drawMagentaCross(\GdImage $image, int $cx, int $cy, int $half, int $thickness, int $color): void
    {
        $end = $thickness - 1;
        imagefilledrectangle($image, $cx - $half, $cy, $cx + $half, $cy + $end, $color);
        imagefilledrectangle($image, $cx, $cy - $half, $cx + $end, $cy + $half, $color);
    }

    /**
     * @return array{name: string, type: string, student: string}
     */
    private function firstSolutionFile(Exam $exam): array
    {
        foreach ($exam->list_files() as $file) {
            if (($file['type'] ?? '') === 'solution') {
                return $file;
            }
        }
        throw new WSException('No corrigé file on this exam', 400);
    }

    protected function findErrors(
        Exam $exam,
        string $copyPath,
        string $copyName,
        string $solutionPath,
        string $solutionName,
        string $languageName
    ): string {

        $size = @getimagesize($copyPath);
        $width = is_array($size) ? (int) $size[0] : 0;
        $height = is_array($size) ? (int) $size[1] : 0;
        if ($width < 1 || $height < 1) {
            throw new WSException('The source file is not an image GD can annotate', 400);
        }

        $instructionText = $exam->instructionFilesText();
        $request = $this->createClaudeSonnetClient();
        $request->set_system_content(
            'First step, find the errors: You decipher a student dictation copy by reading it against the official corrigé. '
            . 'Identify every error compared with the corrigé: spelling, accents, missing or extra words, '
            . 'punctuation, word order, and passages that are unreadable. '
            . 'Gather the coordinates of the box containing the error in the original image using the main lines of the grid. . '
            . 'Second step, filter the errors: Do not get missing space errors. '
            . 'Do not count as errors badly written letters and keep only clear spelling or grammar errors. '
            . 'Step three, write the correction: Do not rewrite the full dictation. List only the errors. '
            . 'For each error give the student writing, the expected text from the corrigé, and the kind of mistake. '
            . 'Write text fields in ' . $languageName . '. '
            . 'All coordinates are pixels of the image you receive, origin (0,0) is top-left. IMPORTANT: magenta crosses coordinates and error boxes coordinates are in the same pixel space! '
            . "Follow these exam-specific instructions:\n"
            . $instructionText
        );
        $request->set_json_response('dictation_errors', [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['magenta_crosses', 'errors'],
            'properties' => [
                'magenta_crosses' => [
                    'type' => 'array',
                    'description' => 'Centres of the two pure-magenta (255,0,255) calibration crosses. If no cross, return empty array.',
                    'minItems' => 2,
                    'maxItems' => 2,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['x', 'y'],
                        'properties' => [
                            'x' => ['type' => 'number'],
                            'y' => ['type' => 'number'],
                        ],
                    ],
                ],
                'errors' => [
                    'type' => 'array',
                    'description' => 'Clear spelling or grammar errors only. Omit missing spaces and badly written letters. Do not rewrite the dictation.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['student', 'expected', 'kind', 'box'],
                        'properties' => [
                            'student' => [
                                'type' => 'string',
                                'description' => 'What the student wrote, in ' . $languageName,
                            ],
                            'expected' => [
                                'type' => 'string',
                                'description' => 'Expected text from the corrigé, in ' . $languageName,
                            ],
                            'kind' => [
                                'type' => 'string',
                                'description' => 'Kind of mistake, in ' . $languageName,
                            ],
                            'box' => [
                                'type' => 'object',
                                'description' => 'The error bounding box coordinates.',
                                'additionalProperties' => false,
                                'required' => ['x1', 'y1', 'x2', 'y2'],
                                'properties' => [
                                    'x1' => ['type' => 'number'],
                                    'y1' => ['type' => 'number'],
                                    'x2' => ['type' => 'number'],
                                    'y2' => ['type' => 'number'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $request->add_text('Official corrigé:');
        $request->add_file($solutionPath, $solutionName);
        $request->add_text('Student copy to decipher:');
        $request->add_file($copyPath, $copyName);
        return $request->call_text();
    }

    /**
     * Centres of the two calibration marks as reported by the model, as a 2×2 array.
     *
     * @return array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}
     */
    private function calibrationCentersInLlm(string $correction): array
    {
        $data = json_decode($correction, true);
        if (!is_array($data)) {
            $trimmed = trim($correction);
            if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $trimmed, $matches)) {
                $data = json_decode(trim($matches[1]), true);
            }
        }
        if (!is_array($data)) {
            throw new WSException('Invalid correction JSON', 400);
        }

        $crosses = $data['magenta_crosses'] ?? null;
        if (!is_array($crosses) || count($crosses) < 2 || !array_is_list($crosses)) {
            throw new WSException('Missing magenta cross coordinates', 400);
        }

        return [
            $this->llmCenter($crosses[0]),
            $this->llmCenter($crosses[1]),
        ];
    }

    /**
     * @param mixed $center
     * @return array{0: int, 1: int}
     */
    private function llmCenter(mixed $center): array
    {
        if (!is_array($center)) {
            throw new WSException('Invalid magenta cross coordinates', 400);
        }

        $x = $center['x'] ?? $center[0] ?? null;
        $y = $center['y'] ?? $center[1] ?? null;
        if (!is_numeric($x) || !is_numeric($y)) {
            throw new WSException('Invalid magenta cross coordinates', 400);
        }

        return [(int) round((float) $x), (int) round((float) $y)];
    }

    protected function gdDirectives(string $copyPath, string $copyName, string $correction): string
    {
        $size = @getimagesize($copyPath);
        $width = is_array($size) ? (int) $size[0] : 0;
        $height = is_array($size) ? (int) $size[1] : 0;
        if ($width < 1 || $height < 1) {
            throw new WSException('The source file is not an image GD can annotate', 400);
        }

        $this->imageWidth = $width;
        $this->imageHeight = $height;

        $data = json_decode($correction, true);
        if (!is_array($data)) {
            $trimmed = trim($correction);
            if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $trimmed, $matches)) {
                $data = json_decode(trim($matches[1]), true);
            }
        }
        if (!is_array($data)) {
            throw new WSException('Invalid correction JSON', 400);
        }

        $data = $this->translateErrorBoxes($data);
        $this->errorBoxesPng = $this->renderErrorBoxes($copyPath, $data);
        $correctedCorrection = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $request = $this->createClaudeSonnetClient();
        $request->set_system_content(
            'You annotate a scanned dictation by writing PHP GD directives. '
            . 'Return only a PHP file that assigns an array to $GD_directives. No markdown, no explanation. '
            . 'Place marks on the student writing that the correction lists as wrong. '
            . 'The image is ' . $this->imageWidth . 'x' . $this->imageHeight . ' pixels. '
            . 'Allowed entries, and nothing else:' . "\n"
            . '- ["fn" => "imagecolorallocate", "as" => "red", "rgb" => [R, G, B]]' . "\n"
            . '- ["fn" => "imagesetthickness", "args" => [pixels]]' . "\n"
            . '- ["fn" => "imageline", "args" => [x1, y1, x2, y2], "color" => "red"]' . "\n"
            . '- ["fn" => "imagerectangle", "args" => [x1, y1, x2, y2], "color" => "red"]' . "\n"
            . '- ["fn" => "imageellipse", "args" => [cx, cy, width, height], "color" => "red"]' . "\n"
            . '- ["fn" => "imagettftext", "args" => [size, angle, x, y], "color" => "red", "text" => "short note"]' . "\n"
            . 'Use a red underline or circle on each error and a short imagettftext note beside it. '
            . 'Example:' . "\n"
            . "<?php\n"
            . '$GD_directives = [' . "\n"
            . '    ["fn" => "imagecolorallocate", "as" => "red", "rgb" => [200, 30, 30]],' . "\n"
            . '    ["fn" => "imagesetthickness", "args" => [3]],' . "\n"
            . '    ["fn" => "imageline", "args" => [40, 120, 260, 120], "color" => "red"],' . "\n"
            . '    ["fn" => "imagettftext", "args" => [18, 0, 270, 120], "color" => "red", "text" => "et"],' . "\n"
            . '];'
        );
        $request->add_file($copyPath, $copyName);
        $request->add_text("Correction listing the errors to mark:\n" . $correctedCorrection);
        return $request->call_text();
    }

    /**
     * Map each error box from the model frame onto the source image.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function translateErrorBoxes(array $data): array
    {
        if ($this->referenceChanger === null) {
            throw new WSException('Reference changer is required to map error boxes', 400);
        }

        $errors = $data['errors'] ?? null;
        if (!is_array($errors)) {
            return $data;
        }

        foreach ($errors as $index => $error) {
            if (!is_array($error) || !is_array($error['box'] ?? null)) {
                continue;
            }
            $box = $error['box'];
            if (!is_numeric($box['x1'] ?? null) || !is_numeric($box['y1'] ?? null)
                || !is_numeric($box['x2'] ?? null) || !is_numeric($box['y2'] ?? null)) {
                continue;
            }

            [$x1, $y1] = $this->referenceChanger->transform( $box['x1'], $box['y1']);
            [$x2, $y2] = $this->referenceChanger->transform( $box['x2'], $box['y2']);
            $error['box']['x1'] = $this->limitX($x1);
            $error['box']['y1'] = $this->limitY($y1);
            $error['box']['x2'] = $this->limitX($x2);
            $error['box']['y2'] = $this->limitY($y2);
            $errors[$index] = $error;
        }

        $data['errors'] = $errors;

        return $data;
    }

    private function limitX(int $inSource): int
    {
        if ($this->imageWidth !== null && $this->imageWidth >= 1 && $inSource > $this->imageWidth) {
            error_log(sprintf(
                '[DictationFranceCM2] X %d exceeds source image width %d',
                $inSource,
                $this->imageWidth
            ));
            return $this->imageWidth;
        }
        if ($inSource < 0) {
            return 0;
        }

        return $inSource;
    }

    private function limitY(int $inSource): int
    {
        if ($this->imageHeight !== null && $this->imageHeight >= 1 && $inSource > $this->imageHeight) {
            error_log(sprintf(
                '[DictationFranceCM2] Y %d exceeds source image height %d',
                $inSource,
                $this->imageHeight
            ));
            return $this->imageHeight;
        }
        if ($inSource < 0) {
            return 0;
        }

        return $inSource;
    }

    protected function createClaudeSonnetClient(): ClaudeSonnetClient
    {
        return new ClaudeSonnetClient();
    }

    /**
     * Draw a green rectangle on the source image for each calibrated error box.
     *
     * @param array<string, mixed> $data
     */
    private function renderErrorBoxes(string $copyPath, array $data): string
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new WSException('PHP GD is not available', 500);
        }

        $bytes = file_get_contents($copyPath);
        if ($bytes === false) {
            throw new WSException('Cannot read the source image', 400);
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new WSException('The source file is not an image GD can annotate', 400);
        }

        try {
            if (!imageistruecolor($image)) {
                imagepalettetotruecolor($image);
            }
            imagealphablending($image, true);

            $green = imagecolorallocate($image, 0, 200, 0);
            if ($green === false) {
                throw new WSException('GD could not allocate a color', 500);
            }
            imagesetthickness($image, self::ERROR_BOX_THICKNESS);

            $errors = $data['errors'] ?? [];
            if (is_array($errors)) {
                foreach ($errors as $error) {
                    if (!is_array($error)) {
                        continue;
                    }
                    $box = $error['box'] ?? null;
                    if (!is_array($box)
                        || !is_numeric($box['x1'] ?? null) || !is_numeric($box['y1'] ?? null)
                        || !is_numeric($box['x2'] ?? null) || !is_numeric($box['y2'] ?? null)) {
                        continue;
                    }
                    $x1 = (int) $box['x1'];
                    $y1 = (int) $box['y1'];
                    $x2 = (int) $box['x2'];
                    $y2 = (int) $box['y2'];
                    if (imagerectangle($image, $x1, $y1, $x2, $y2, $green) !== true) {
                        throw new WSException('GD failed to draw an error box', 500);
                    }
                }
            }

            ob_start();
            imagepng($image);
            $png = ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        if (!is_string($png) || $png === '') {
            throw new WSException('GD did not produce an image', 500);
        }

        return $png;
    }

    /**
     * Draw $GD_directives onto a copy of the source image.
     */
    private function renderCorrection(string $copyPath, string $directivesPhp): string
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new WSException('PHP GD is not available', 500);
        }
        if (!is_readable(self::FONT)) {
            throw new WSException('Annotation font is missing', 500);
        }

        $bytes = file_get_contents($copyPath);
        if ($bytes === false) {
            throw new WSException('Cannot read the source image', 400);
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new WSException('The source file is not an image GD can annotate', 400);
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        $colors = [];
        foreach ($this->loadDirectives($directivesPhp) as $directive) {
            $this->applyDirective($image, $directive, $colors);
        }

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        unset($image);

        if (!is_string($png) || $png === '') {
            throw new WSException('GD did not produce an image', 500);
        }
        return $png;
    }

    /**
     * Accept only a PHP array assigned to $GD_directives.
     *
     * @return list<array<string, mixed>>
     */
    private function loadDirectives(string $source): array
    {
        $source = trim($source);
        if (preg_match('/```(?:php)?\s*(.*?)```/s', $source, $match) === 1) {
            $source = trim($match[1]);
        }
        if (!str_starts_with($source, '<?php')) {
            $source = "<?php\n" . $source;
        }

        $variableCount = 0;
        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                if (!in_array($token, ['=', ';', '[', ']', ',', '(', ')', '-'], true)) {
                    throw new WSException('GD directives contain unsupported PHP', 400);
                }
                continue;
            }
            [$id, $text] = $token;
            $allowed = [
                T_OPEN_TAG,
                T_WHITESPACE,
                T_COMMENT,
                T_DOC_COMMENT,
                T_VARIABLE,
                T_CONSTANT_ENCAPSED_STRING,
                T_LNUMBER,
                T_DNUMBER,
                T_DOUBLE_ARROW,
            ];
            if ($id === T_STRING && in_array(strtolower($text), ['true', 'false', 'null'], true)) {
                continue;
            }
            if (!in_array($id, $allowed, true)) {
                throw new WSException('GD directives contain unsupported PHP', 400);
            }
            if ($id === T_VARIABLE) {
                if ($text !== '$GD_directives') {
                    throw new WSException('GD directives contain unsupported PHP', 400);
                }
                $variableCount++;
            }
        }
        if ($variableCount !== 1) {
            throw new WSException('GD directives must assign $GD_directives', 400);
        }

        $GD_directives = null;
        try {
            eval('?>' . $source);
        } catch (\Throwable $th) {
            throw new WSException('GD directives could not be read', 400);
        }
        if (!is_array($GD_directives)) {
            throw new WSException('GD directives must assign an array to $GD_directives', 400);
        }
        return array_values($GD_directives);
    }

    /**
     * @param array<string, int> $colors
     * @param array<string, mixed> $directive
     */
    private function applyDirective(\GdImage $image, array $directive, array &$colors): void
    {
        $fn = $directive['fn'] ?? '';
        if ($fn === 'imagecolorallocate') {
            $name = $directive['as'] ?? '';
            $rgb = $directive['rgb'] ?? null;
            if (!is_string($name) || $name === '' || !is_array($rgb) || count($rgb) < 3) {
                throw new WSException('Invalid imagecolorallocate directive', 400);
            }
            $color = imagecolorallocate($image, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2]);
            if ($color === false) {
                throw new WSException('GD could not allocate a color', 500);
            }
            $colors[$name] = $color;
            return;
        }

        if ($fn === 'imagesetthickness') {
            $px = $directive['args'][0] ?? null;
            if (!is_numeric($px)) {
                throw new WSException('Invalid imagesetthickness directive', 400);
            }
            imagesetthickness($image, (int) $px);
            return;
        }

        $colorName = $directive['color'] ?? '';
        if (!is_string($colorName) || !isset($colors[$colorName])) {
            if ($colorName === 'red') {
                $allocated = imagecolorallocate($image, 200, 30, 30);
                if ($allocated !== false) {
                    $colors['red'] = $allocated;
                }
            }
            if (!isset($colors[$colorName])) {
                throw new WSException('GD directive uses an unknown color', 400);
            }
        }
        $color = $colors[$colorName];
        $args = $directive['args'] ?? null;
        if (!is_array($args)) {
            throw new WSException('Invalid GD directive', 400);
        }

        $numbers = array_map(static fn($value) => (int) $value, $args);
        $drawn = match ($fn) {
            'imageline' => count($numbers) >= 4
                && imageline($image, $numbers[0], $numbers[1], $numbers[2], $numbers[3], $color),
            'imagerectangle' => count($numbers) >= 4
                && imagerectangle($image, $numbers[0], $numbers[1], $numbers[2], $numbers[3], $color),
            'imageellipse' => count($numbers) >= 4
                && imageellipse($image, $numbers[0], $numbers[1], $numbers[2], $numbers[3], $color),
            'imagettftext' => count($numbers) >= 4
                && is_string($directive['text'] ?? null)
                && imagettftext(
                    $image,
                    $numbers[0],
                    $numbers[1],
                    $numbers[2],
                    $numbers[3],
                    $color,
                    self::FONT,
                    $directive['text']
                ) !== false,
            default => throw new WSException('Unsupported GD directive', 400),
        };
        if ($drawn !== true) {
            throw new WSException('GD failed to draw a directive', 500);
        }
    }
}
