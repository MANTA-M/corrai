<?php

namespace Corrai\Subject\DictationFranceGemini;

use Corrai\Llm\Openrouter\ClaudeSonnetClient;
use Corrai\Llm\Openrouter\Gemini2FlashLiteClient;
use Corrai\Model\BaseAssessment;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\ImageRedimentioner;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\WSException;
use Throwable;

class Task1Correcting extends PathQueueItemTask
{
    /** Longest side sent to Gemini. Measures are scaled back onto the full image. */
    private const GEMINI_MAX_SIDE = 2048;

    /** Y of the first main horizontal line, in pixels of the straightened image. */
    public ?int $firstHorizontalY = null;

    /** X of the first main vertical line, in pixels of the straightened image. */
    public ?int $firstVerticalX = null;

    /** Vertical distance between main horizontal lines, ignoring thinner sub-lines. */
    public ?int $verticalStep = null;

    /** Horizontal distance between main vertical lines, ignoring thinner sub-lines. */
    public ?int $horizontalStep = null;

    public ?float $rescale = null;

    protected function process(object $queue_item_data, string $s3_path): void
    {
        $this->correct($this->loadFile($s3_path));
    }
    /**
     * Find dictation errors. Same step as File::on_ocr_done.
     */
    public function correct(File $file): void
    {
        if ($file->type !== 'submission') {
            return;
        }

        $copyPath = null;
        $solutionPath = null;
        $subjectFiles = [];
        try {
            $assessment = $this->loadAssessment($file);
            $store = ObjectStore::getInstance();
            $copyPath = $store->downloadToTemp($file->contentKey());

            $this->straightenAndMeasureGrid($copyPath);
            $straightened = file_get_contents($copyPath);
            if ($straightened === false) {
                throw new WSException('Cannot read the straightened image', 400);
            }
            $mime = $file->content_type !== '' ? $file->content_type : 'image/png';
            $store->putContents($file->straightenedKey(), $straightened, $mime);

            $solution = $this->firstSolutionFile($assessment);
            $solutionPath = $store->downloadToTemp($assessment->fileContentKey($solution['id']));
            $subjectFiles = $this->downloadSubjectFiles($assessment, $store);
            $correction = $this->findErrors(
                $assessment,
                $copyPath,
                $file->name,
                $solutionPath,
                $solution['name'],
                $this->languageName($file),
                $subjectFiles
            );
            $store->putContents(
                $file->foundErrorsKey(),
                $this->correctionDocument($correction),
                'application/json'
            );

            $file->status = 'errors_found';
            $file->saveAttributes();
            $file->appendEvent('Errors found');
            RedisQueue::getInstance()->enqueueFile($file->id, Task2Annotating::class);
        } catch (Throwable $th) {
            $this->failCorrection($file, $th);
        } finally {
            if ($copyPath !== null) {
                @unlink($copyPath);
            }
            if ($solutionPath !== null) {
                @unlink($solutionPath);
            }
            foreach ($subjectFiles as $subjectFile) {
                @unlink($subjectFile['path']);
            }
        }
    }

    /**
     * Every file stored under the assessment subject/ folder.
     *
     * @return list<array{path: string, name: string}>
     */
    private function downloadSubjectFiles(BaseAssessment $assessment, ObjectStore $store): array
    {
        $prefix = ObjectStore::assessmentSubjectFilesPrefix(
            $assessment->school_id,
            $assessment->user_id,
            (string) $assessment->id
        );
        $files = [];
        try {
            foreach ($store->listChildPrefixes($prefix) as $fileId) {
                $subjectFile = $assessment->getFile($fileId);
                $path = $store->downloadToTemp($subjectFile->contentKey());
                $files[] = ['path' => $path, 'name' => $subjectFile->name];
            }
        } catch (Throwable $error) {
            foreach ($files as $subjectFile) {
                @unlink($subjectFile['path']);
            }
            throw $error;
        }

        return $files;
    }

    private function correctionDocument(string $correction): string
    {
        $data = json_decode($correction, true);
        if (!is_array($data)) {
            return $correction;
        }
        $data['rescale'] = $this->rescale;
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return $correction;
        }

        return $encoded;
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
     * @param list<array{path: string, name: string}> $subjectFiles
     */
    protected function findErrors(
        BaseAssessment $assessment,
        string $copyPath,
        string $copyName,
        string $solutionPath,
        string $solutionName,
        string $languageName,
        array $subjectFiles = []
    ): string {

        $size = @getimagesize($copyPath);
        $width = is_array($size) ? (int) $size[0] : 0;
        $height = is_array($size) ? (int) $size[1] : 0;
        if ($width < 1 || $height < 1) {
            throw new WSException('The source file is not an image GD can annotate', 400);
        }

        $instructionText = $assessment->instructionFilesText();
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
            . "Follow these assessment-specific instructions:\n"
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
        foreach ($subjectFiles as $subjectFile) {
            $request->add_text($subjectFile['name'] . ':');
            $request->add_file($subjectFile['path'], $subjectFile['name']);
        }
        $request->add_text('Student copy to decipher:');
        $request->add_file($copyPath, $copyName);
        $resp = $request->call_text();
        $this->rescale = $request->rescale;
        return $resp;
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
}
