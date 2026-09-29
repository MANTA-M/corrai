<?php

namespace Corrai\Subject\DictationFranceGemini;

use Corrai\LlmClient\Gemini2FlashLiteClient;
use Corrai\Model\Exam;
use Corrai\Utils\ImageRedimentioner;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\WSException;
use Corrai\LlmClient\ClaudeSonnetClient;

/**
 * Dictation CM2 pipeline whose page is straightened and ruled by Gemini 1.5 Flash.
 *
 * The model returns the rotation, then the main ruling measured on the image it sees.
 * Those measures are scaled back to pixels of the full straightened image:
 * first horizontal Y, first vertical X, vertical step between main horizontal lines,
 * and horizontal step between main vertical lines. Thinner sub-lines are ignored.
 */
class Pipeline
{
    public const SUBJECT = 'Dictation';
    public const LEVEL = 'Gemini';
    public const COUNTRY = 'fr';
    public const NAMES = [
        'en' => 'Dictation Gemini France',
        'fr' => 'Dictée Gemini France',
        'ru' => 'Диктант Gemini Франция',
        'uk' => 'Диктант Gemini Франція',
        'es' => 'Dictado Gemini Francia',
        'pt' => 'Ditado Gemini Portugal',
        'ro' => 'Dictare Gemini Franța',
        'de' => 'Diktat Gemini Frankreich',
    ];

    public const TRANSCRIPTION_INSTRUCTION =
        'Transcript only what is writen without correcting it. DO NOT ADD ANY LETTER OR SIGN. '
        . 'If something is badly written, put a mark to say it\'s unreadable. '
        . 'Value de quality of caligraphy from 0.0 to 1.0.';

    public const DEBUG = 1;

    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    private const BASELINE_MARK_LENGTH = 30;

    /** Longest side sent to Gemini. Measures are scaled back onto the full image. */
    private const GEMINI_MAX_SIDE = 2048;

    public int $debug = self::DEBUG;

    /** Y of the first main horizontal line, in pixels of the straightened image. */
    public ?int $firstHorizontalY = null;

    /** X of the first main vertical line, in pixels of the straightened image. */
    public ?int $firstVerticalX = null;

    /** Vertical distance between main horizontal lines, ignoring thinner sub-lines. */
    public ?int $verticalStep = null;

    /** Horizontal distance between main vertical lines, ignoring thinner sub-lines. */
    public ?int $horizontalStep = null;

    public ?float $rescale = null;
    public ?int $cropped_x1 = null;
    public ?int $cropped_y1 = null;
    public ?int $cropped_x2 = null;
    public ?int $cropped_y2 = null;

    private ?int $imageWidth = null;
    private ?int $imageHeight = null;

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
            $grid = $this->straightenAndMeasureGrid($tmpPath);
            $straightenedPng = $this->pngFromFile($tmpPath);

            $exam->deleteFilesOfType('debug', $student);
            $exam->deleteFilesOfType('correction', $student);

            $exam->createFile(
                $base . ' straightened.png',
                $straightenedPng,
                'image/png',
                'debug',
                $student
            );
            $exam->createFile(
                $base . ' grid.json',
                json_encode($grid, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                'application/json',
                'debug',
                $student
            );

            $solution = $this->firstSolutionFile($exam);
            $solutionPath = $store->downloadToTemp($exam->unassignedFileKey($solution['name']));

            $correction = $this->findErrors(
                $exam,
                $tmpPath,
                $filename,
                $solutionPath,
                $solution['name'],
                $languageName
            );
            $exam->createFile(
                $base . ' correction.txt',
                $correction,
                'text/plain; charset=utf-8',
                'debug',
                $student
            );

            $directivesPhp = $this->gdDirectives($tmpPath, $filename, $correction);
            $exam->createFile(
                $base . ' directives.php',
                $directivesPhp,
                'text/plain; charset=utf-8',
                'debug',
                $student
            );

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
     * Ask Gemini 1.5 Flash for the rotation, straighten the file, then measure
     * the main ruling in pixels of that straightened image.
     *
     * @return array{first_horizontal_y: int, first_vertical_x: int, vertical_step: int, horizontal_step: int}
     */
    protected function straightenAndMeasureGrid(string $imagePath): array
    {
        $angle = $this->askStraightenAngle($imagePath);
        $this->rotateFile($imagePath, $angle);

        $grid = $this->askMainGrid($imagePath);
        $this->firstHorizontalY = $grid['first_horizontal_y'];
        $this->firstVerticalX = $grid['first_vertical_x'];
        $this->verticalStep = $grid['vertical_step'];
        $this->horizontalStep = $grid['horizontal_step'];

        return $grid;
    }

    protected function geminiMaxSide(): int
    {
        return self::GEMINI_MAX_SIDE;
    }

    private function askStraightenAngle(string $imagePath): float
    {
        $answer = $this->askGemini(
            $imagePath,
            'You straighten a scanned or photographed French school dictation page so its ruling becomes horizontal and vertical. '
            . 'Return angle_degrees: the counter-clockwise rotation, in degrees, to apply to the image. '
            . 'A positive angle rotates the page counter-clockwise. Use 0 when the ruling is already straight. '
            . 'Keep the value between -180 and 180. Do not crop. Do not return coordinates.',
            'straighten_angle',
            [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['angle_degrees'],
                'properties' => [
                    'angle_degrees' => [
                        'type' => 'number',
                        'description' => 'Counter-clockwise rotation in degrees. 0 if the ruling is already straight.',
                    ],
                ],
            ]
        );

        $angle = $answer['data']['angle_degrees'] ?? null;
        if (!is_numeric($angle) || abs((float) $angle) > 180.0) {
            throw new WSException('Gemini returned an unusable straighten angle', 400);
        }

        return (float) $angle;
    }

    /**
     * @return array{first_horizontal_y: int, first_vertical_x: int, vertical_step: int, horizontal_step: int}
     */
    private function askMainGrid(string $imagePath): array
    {
        $answer = $this->askGemini(
            $imagePath,
            'This image is already straightened. Measure its ruling in pixels of the attached image. '
            . 'Origin is the top-left corner. X increases to the right. Y increases downward. '
            . 'French notebooks often mix thick main lines with thinner sub-lines between them. '
            . 'Return only the main lines. '
            . 'first_horizontal_y is the Y of the topmost main horizontal line. '
            . 'first_vertical_x is the X of the leftmost main vertical line. '
            . 'vertical_step is the vertical pixel distance between two successive main horizontal lines. Ignore thinner sub-lines. '
            . 'horizontal_step is the horizontal pixel distance between two successive main vertical lines. Ignore thinner sub-lines. '
            . 'When every line has the same thickness, those lines are the main lines and the step is their regular spacing.',
            'main_grid',
            [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['first_horizontal_y', 'first_vertical_x', 'vertical_step', 'horizontal_step'],
                'properties' => [
                    'first_horizontal_y' => [
                        'type' => 'integer',
                        'description' => 'Y of the topmost main horizontal line, in pixels of the attached image.',
                    ],
                    'first_vertical_x' => [
                        'type' => 'integer',
                        'description' => 'X of the leftmost main vertical line, in pixels of the attached image.',
                    ],
                    'vertical_step' => [
                        'type' => 'integer',
                        'description' => 'Vertical pixel distance between successive main horizontal lines, ignoring thinner sub-lines.',
                    ],
                    'horizontal_step' => [
                        'type' => 'integer',
                        'description' => 'Horizontal pixel distance between successive main vertical lines, ignoring thinner sub-lines.',
                    ],
                ],
            ]
        );

        $factor = $answer['factor'];
        $data = $answer['data'];

        return [
            'first_horizontal_y' => $this->scaledGridValue($data['first_horizontal_y'] ?? null, $factor, false),
            'first_vertical_x' => $this->scaledGridValue($data['first_vertical_x'] ?? null, $factor, false),
            'vertical_step' => $this->scaledGridValue($data['vertical_step'] ?? null, $factor, true),
            'horizontal_step' => $this->scaledGridValue($data['horizontal_step'] ?? null, $factor, true),
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{data: array<string, mixed>, factor: float}
     */
    private function askGemini(string $imagePath, string $system, string $schemaName, array $schema): array
    {
        $bytes = file_get_contents($imagePath);
        if ($bytes === false) {
            throw new WSException('Cannot read the source image', 400);
        }

        $prepared = $this->prepareGeminiImage($bytes);
        $request = $this->createGeminiFlashClient();
        $request->set_system_content($system);
        $request->set_json_response($schemaName, $schema);
        $request->add_text(
            'The attached image is ' . $prepared['width'] . ' by ' . $prepared['height']
            . ' pixels. Answer in that pixel space.'
        );
        $request->add_file($prepared['path'], $prepared['name']);

        try {
            $text = $request->call_text();
        } finally {
            @unlink($prepared['path']);
        }

        return [
            'data' => $this->decodeModelJson($text),
            'factor' => $prepared['factor'],
        ];
    }

    /**
     * @return array{path: string, name: string, factor: float, width: int, height: int}
     */
    private function prepareGeminiImage(string $bytes): array
    {
        $prepared = new ImageRedimentioner($this->geminiMaxSide(), $bytes);
        $info = @getimagesizefromstring($prepared->image);
        if ($info === false) {
            throw new WSException('The source file is not an image GD can annotate', 400);
        }

        $extension = image_type_to_extension((int) $info[2], false);
        if (!is_string($extension) || $extension === '') {
            $extension = 'png';
        }
        $path = tempnam(sys_get_temp_dir(), 'gemini_page_');
        if ($path === false) {
            throw new WSException('Cannot prepare the image for Gemini', 500);
        }
        $named = $path . '.' . $extension;
        if (!@rename($path, $named)) {
            @unlink($path);
            throw new WSException('Cannot prepare the image for Gemini', 500);
        }
        if (file_put_contents($named, $prepared->image) === false) {
            @unlink($named);
            throw new WSException('Cannot prepare the image for Gemini', 500);
        }

        return [
            'path' => $named,
            'name' => 'page.' . $extension,
            'factor' => $prepared->factor,
            'width' => (int) $info[0],
            'height' => (int) $info[1],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeModelJson(string $text): array
    {
        $data = json_decode($text, true);
        if (!is_array($data)) {
            $trimmed = trim($text);
            if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $trimmed, $matches)) {
                $data = json_decode(trim($matches[1]), true);
            }
        }
        if (!is_array($data)) {
            throw new WSException('Invalid JSON from Gemini', 400);
        }

        return $data;
    }

    private function scaledGridValue(mixed $value, float $factor, bool $step): int
    {
        if (!is_numeric($value)) {
            throw new WSException('Gemini returned a non-numeric grid measure', 400);
        }

        $factor = $factor > 0.0 ? $factor : 1.0;
        $pixel = (int) round(((float) $value) / $factor);
        if ($step && $pixel < 1) {
            throw new WSException('Gemini returned an unusable grid step', 400);
        }
        if (!$step && $pixel < 0) {
            throw new WSException('Gemini returned an unusable grid position', 400);
        }

        return $pixel;
    }

    /**
     * Rotate the file counter-clockwise. A near-zero angle keeps the original bytes.
     */
    private function rotateFile(string $imagePath, float $angle): void
    {
        if (abs($angle) < 0.05) {
            return;
        }
        if (!function_exists('imagerotate')) {
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
            $white = imagecolorallocate($image, 255, 255, 255);
            if ($white === false) {
                throw new WSException('GD could not allocate a color', 500);
            }
            $rotated = imagerotate($image, $angle, $white);
            if ($rotated === false) {
                throw new WSException('GD could not straighten the image', 500);
            }
            try {
                $this->saveImage($rotated, $imagePath, (int) $info[2]);
            } finally {
                imagedestroy($rotated);
            }
        } finally {
            imagedestroy($image);
        }
    }

    private function pngFromFile(string $path): string
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new WSException('Cannot read the straightened image', 400);
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new WSException('The straightened file is not an image', 400);
        }

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        if (!is_string($png) || $png === '') {
            throw new WSException('GD did not produce an image', 500);
        }

        return $png;
    }

    private function saveImage(\GdImage $image, string $path, int $type): void
    {
        $written = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($image, $path, 90),
            IMAGETYPE_GIF => imagegif($image, $path),
            IMAGETYPE_WEBP => imagewebp($image, $path, 90),
            IMAGETYPE_BMP => imagebmp($image, $path),
            default => imagepng($image, $path),
        };
        if ($written !== true) {
            throw new WSException('GD did not produce an image', 500);
        }
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
            $this->straightenedGridHint()
            . 'First step, find the errors: You decipher a student dictation copy by reading it against the official corrigé. '
            . 'Identify every error compared with the corrigé: spelling, accents, missing or extra words, '
            . 'punctuation, word order, and passages that are unreadable. '
            . 'Gather the coordinates of the box containing the error in the original image using the main lines of the grid. . '
            . 'Third step, filter the errors: Do not get missing space errors. '
            . 'Do not count as errors badly written letters and keep only clear spelling or grammar errors. '
            . 'Step three, write the correction: Do not rewrite the full dictation. List only the errors. '
            . 'For each error give the student writing, the expected text from the corrigé, and the kind of mistake. '
            . 'Write text fields in ' . $languageName . '. '
            . 'All coordinates are pixels of the straightened image, origin top-left. '
            . "Follow these exam-specific instructions:\n"
            . $instructionText
        );
        $request->set_json_response('dictation_errors', [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['cropped_image', 'lines', 'errors'],
            'properties' => [
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
                                'description' => 'The error bounding box using the main lines of the grid,  0,0 is the top-left corner.',
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
        $resp = $request->call_text();
        $this->rescale = $request->rescale;
        return $resp;
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

        $this->extractCroppedCoordinates($data);
        $data = $this->adjustCoordinates($data);
        $promptData = $data;
        unset($promptData['lines']);
        $correctedCorrection = (string) json_encode($promptData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
        $directives = $request->call_text();
        $directives = $this->addHandwrittenBaselineDirectives($directives, $data);

        if ($this->isDebug() && $this->hasCroppedCoordinates()) {
            $directives = $this->addCropDebugDirective($directives);
        }

        return $directives;
    }

    public function isDebug(): bool
    {
        if ($this->debug !== self::DEBUG) {
            return (bool) $this->debug;
        }
        return (bool) static::DEBUG;
    }

    public function hasCroppedCoordinates(): bool
    {
        return $this->cropped_x1 !== null
            || $this->cropped_y1 !== null
            || $this->cropped_x2 !== null
            || $this->cropped_y2 !== null;
    }

    /**
     * Append a GD directive drawing the crop rectangle when DEBUG is active.
     */
    protected function addCropDebugDirective(string $directivesPhp): string
    {
        $rescale = ($this->rescale !== null && $this->rescale > 0.0) ? $this->rescale : 1.0;
        $x1 = $this->limitToImage((int) round(($this->cropped_x1 ?? 0) / $rescale), $this->imageWidth);
        $y1 = $this->limitToImage((int) round(($this->cropped_y1 ?? 0) / $rescale), $this->imageHeight);
        $x2 = $this->limitToImage((int) round(($this->cropped_x2 ?? ($this->cropped_x1 ?? 0)) / $rescale), $this->imageWidth);
        $y2 = $this->limitToImage((int) round(($this->cropped_y2 ?? ($this->cropped_y1 ?? 0)) / $rescale), $this->imageHeight);

        $cropDirective = [
            'fn' => 'imagerectangle',
            'args' => [$x1, $y1, $x2, $y2],
            'color' => 'red',
        ];

        try {
            $directives = $this->loadDirectives($directivesPhp);
            $directives[] = $cropDirective;
            return $this->formatDirectives($directives);
        } catch (\Throwable) {
            $cropDirectiveCode = "    ['fn' => 'imagerectangle', 'args' => [{$x1}, {$y1}, {$x2}, {$y2}], 'color' => 'red'],\n";
            $lastBracketPos = strrpos($directivesPhp, ']');
            if ($lastBracketPos !== false) {
                return substr($directivesPhp, 0, $lastBracketPos) . $cropDirectiveCode . substr($directivesPhp, $lastBracketPos);
            }
            return $directivesPhp;
        }
    }

    /**
     * Format a list of GD directives into PHP code.
     *
     * @param list<array<string, mixed>> $directives
     */
    private function formatDirectives(array $directives): string
    {
        $lines = ["<?php\n\n\$GD_directives = ["];
        foreach ($directives as $directive) {
            $parts = [];
            foreach ($directive as $key => $val) {
                $keyExport = var_export($key, true);
                if (is_array($val)) {
                    $valExport = '[' . implode(', ', array_map(static fn($v) => var_export($v, true), $val)) . ']';
                } else {
                    $valExport = var_export($val, true);
                }
                $parts[] = "$keyExport => $valExport";
            }
            $lines[] = '    [' . implode(', ', $parts) . '],';
        }
        $lines[] = "];\n";
        return implode("\n", $lines);
    }

    /**
     * Extract cropped coordinates from correction data and assign to object attributes.
     *
     * @param array<string, mixed>|string $data
     */
    public function extractCroppedCoordinates(array|string $data): void
    {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (!is_array($decoded)) {
                $trimmed = trim($data);
                if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $trimmed, $matches)) {
                    $decoded = json_decode(trim($matches[1]), true);
                }
            }
            if (!is_array($decoded)) {
                return;
            }
            $data = $decoded;
        }

        $cropped = is_array($data['cropped_image'] ?? null) ? $data['cropped_image'] : [];

        if (isset($cropped['x1'])) {
            $this->cropped_x1 = (int) $cropped['x1'];
        } elseif (isset($data['cropped_x1'])) {
            $this->cropped_x1 = (int) $data['cropped_x1'];
        }

        if (isset($cropped['y1'])) {
            $this->cropped_y1 = (int) $cropped['y1'];
        } elseif (isset($data['cropped_y1'])) {
            $this->cropped_y1 = (int) $data['cropped_y1'];
        }

        if (isset($cropped['x2'])) {
            $this->cropped_x2 = (int) $cropped['x2'];
        } elseif (isset($data['cropped_x2'])) {
            $this->cropped_x2 = (int) $data['cropped_x2'];
        }

        if (isset($cropped['y2'])) {
            $this->cropped_y2 = (int) $cropped['y2'];
        } elseif (isset($data['cropped_y2'])) {
            $this->cropped_y2 = (int) $data['cropped_y2'];
        }

        if ($this->cropped_x1 === null || $this->cropped_y1 === null || $this->cropped_x2 === null || $this->cropped_y2 === null) {
            $iterator = function (array $items) use (&$iterator): void {
                foreach ($items as $k => $v) {
                    if (is_array($v)) {
                        $iterator($v);
                    } elseif (is_numeric($v) && is_string($k)) {
                        $lower = strtolower($k);
                        if ($lower === 'cropped_x1' && $this->cropped_x1 === null) {
                            $this->cropped_x1 = (int) $v;
                        } elseif ($lower === 'cropped_y1' && $this->cropped_y1 === null) {
                            $this->cropped_y1 = (int) $v;
                        } elseif ($lower === 'cropped_x2' && $this->cropped_x2 === null) {
                            $this->cropped_x2 = (int) $v;
                        } elseif ($lower === 'cropped_y2' && $this->cropped_y2 === null) {
                            $this->cropped_y2 = (int) $v;
                        }
                    }
                }
            };
            $iterator($data);
        }
    }

    /**
     * Recursively adjust error coordinates given in 1/1000 of the cropped image.
     *
     * @param mixed $data
     * @return mixed
     */
    protected function adjustCoordinates(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        if ($this->cropped_x1 === null && $this->cropped_y1 === null) {
            $this->extractCroppedCoordinates($data);
        }

        foreach ($data as $key => $value) {
            if ($key === 'cropped_image') {
                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->adjustCoordinates($value);
            } elseif (is_numeric($value) && is_string($key)) {
                $lowerKey = strtolower($key);
                if (in_array($lowerKey, ['x', 'x1', 'x2', 'xmin', 'xmax', 'x_min', 'x_max'], true)) {
                    $data[$key] = $this->cropped2SourceX((float) $value);
                } elseif (in_array($lowerKey, ['y', 'y1', 'y2', 'ymin', 'ymax', 'y_min', 'y_max'], true)) {
                    $data[$key] = $this->cropped2SourceY((float) $value);
                }
            }
        }

        return $data;
    }

    /**
     * Map an X given in thousandths of the cropped zone onto the source image.
     */
    private function cropped2SourceX(float $x): int
    {
        $x1 = (int) ($this->cropped_x1 ?? 0);
        $x2 = (int) ($this->cropped_x2 ?? $x1);
        $croppedWidth = max(0, $x2 - $x1);
        $rescale = ($this->rescale !== null && $this->rescale > 0.0) ? $this->rescale : 1.0;

        $inCrop = ($x / 1000.0) * $croppedWidth;
        $inScaled = $inCrop + $x1;
        $inSource = (int) round($inScaled / $rescale);

        if ($this->imageWidth !== null && $this->imageWidth >= 1 && $inSource > $this->imageWidth) {
            error_log(sprintf(
                '[DictationFranceGemini] X %d exceeds source image width %d',
                $inSource,
                $this->imageWidth
            ));
            return $this->imageWidth;
        }

        return $inSource;
    }

    /**
     * Map a Y given in thousandths of the cropped zone onto the source image.
     */
    private function cropped2SourceY(float $y): int
    {
        $y1 = (int) ($this->cropped_y1 ?? 0);
        $y2 = (int) ($this->cropped_y2 ?? $y1);
        $croppedHeight = max(0, $y2 - $y1);
        $rescale = ($this->rescale !== null && $this->rescale > 0.0) ? $this->rescale : 1.0;

        $inCrop = ($y / 1000.0) * $croppedHeight;
        $inScaled = $inCrop + $y1;
        $inSource = (int) round($inScaled / $rescale);

        if ($this->imageHeight !== null && $this->imageHeight >= 1 && $inSource > $this->imageHeight) {
            error_log(sprintf(
                '[DictationFranceGemini] Y %d exceeds source image height %d',
                $inSource,
                $this->imageHeight
            ));
            return $this->imageHeight;
        }

        return $inSource;
    }

    protected function adjustYCoordinates(mixed $data): mixed
    {
        return $this->adjustCoordinates($data);
    }

    /**
     * Draw a 30px horizontal tick at each handwritten baseline.
     * Y values are already mapped like error coordinates.
     *
     * @param array<string, mixed> $data
     */
    protected function addHandwrittenBaselineDirectives(string $directivesPhp, array $data): string
    {
        $marks = $this->handwrittenBaselineDirectives($data);
        if ($marks === []) {
            return $directivesPhp;
        }

        try {
            $directives = $this->loadDirectives($directivesPhp);
            foreach ($marks as $mark) {
                $directives[] = $mark;
            }
            return $this->formatDirectives($directives);
        } catch (\Throwable) {
            $snippet = '';
            foreach ($marks as $mark) {
                $y = (int) $mark['args'][1];
                $x2 = (int) $mark['args'][2];
                $snippet .= "    ['fn' => 'imageline', 'args' => [0, {$y}, {$x2}, {$y}], 'color' => 'red'],\n";
            }
            $lastBracketPos = strrpos($directivesPhp, ']');
            if ($lastBracketPos !== false) {
                return substr($directivesPhp, 0, $lastBracketPos) . $snippet . substr($directivesPhp, $lastBracketPos);
            }
            return $directivesPhp;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function handwrittenBaselineDirectives(array $data): array
    {
        $lines = $data['lines'] ?? null;
        if (!is_array($lines)) {
            return [];
        }

        $directives = [];
        foreach ($lines as $line) {
            if (!is_array($line) || !is_numeric($line['y'] ?? null)) {
                continue;
            }
            $y = $this->limitToImage((int) $line['y'], $this->imageHeight);
            $x2 = $this->limitToImage(self::BASELINE_MARK_LENGTH, $this->imageWidth);
            $directives[] = [
                'fn' => 'imageline',
                'args' => [0, $y, $x2, $y],
                'color' => 'red',
            ];
        }
        return $directives;
    }

    /**
     * Cap a pixel coordinate at the image width or height.
     */
    private function limitToImage(int $value, ?int $maximum): int
    {
        if ($maximum === null || $maximum < 1 || $value <= $maximum) {
            return $value;
        }
        return $maximum;
    }

    protected function createClaudeSonnetClient(): ClaudeSonnetClient
    {
        return new ClaudeSonnetClient();
    }

    protected function createGeminiFlashClient(): Gemini2FlashLiteClient
    {
        return new Gemini2FlashLiteClient();
    }

    /**
     * Ruling already measured on the straightened image, so later boxes use those pixels.
     */
    private function straightenedGridHint(): string
    {
        if (
            $this->firstHorizontalY === null
            || $this->firstVerticalX === null
            || $this->verticalStep === null
            || $this->horizontalStep === null
        ) {
            return '';
        }

        return 'The student copy is already straightened. '
            . 'Main ruling in pixels of that image, origin top-left: '
            . 'first horizontal line y=' . $this->firstHorizontalY . ', '
            . 'first vertical line x=' . $this->firstVerticalX . ', '
            . 'vertical step between main horizontal lines=' . $this->verticalStep . ', '
            . 'horizontal step between main vertical lines=' . $this->horizontalStep . '. '
            . 'Thinner sub-lines are not part of these steps. ';
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
