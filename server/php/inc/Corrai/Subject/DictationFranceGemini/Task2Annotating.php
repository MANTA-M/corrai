<?php

namespace Corrai\Subject\DictationFranceGemini;

use Corrai\Llm\Openrouter\ClaudeSonnetClient;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\WSException;
use Throwable;

class Task2Annotating extends PathQueueItemTask
{
    public const DEBUG = 1;

    private const BASELINE_MARK_LENGTH = 30;

    public int $debug = self::DEBUG;

    public ?float $rescale = null;
    public ?int $cropped_x1 = null;
    public ?int $cropped_y1 = null;
    public ?int $cropped_x2 = null;
    public ?int $cropped_y2 = null;

    private ?int $imageWidth = null;
    private ?int $imageHeight = null;

    protected function process(object $queue_item_data, string $s3_path): void
    {
        $this->annotate($this->loadFile($s3_path));
    }
    /**
     * Turn found errors into markup annotations. Same step as File::on_errors_found.
     */
    public function annotate(File $file): void
    {
        if ($file->type !== 'submission') {
            return;
        }

        $copyPath = null;
        try {
            $store = ObjectStore::getInstance();
            $errorsKey = $file->foundErrorsKey();
            if (!$store->exists($errorsKey)) {
                throw new WSException('Errors JSON is missing for this file', 400);
            }
            $correction = $store->getContents($errorsKey);
            $correction = $this->withoutRescale($correction);
            $copyPath = $store->downloadToTemp($this->imageKey($file));
            $annotationsPhp = $this->gdAnnotations($copyPath, $file->name, $correction);

            $store->putContents(
                $file->markupAnnotationsKey(),
                $annotationsPhp,
                'text/plain; charset=utf-8'
            );

            $file->status = 'annotations';
            $file->saveAttributes();
            $file->appendEvent('Annotations written');
            RedisQueue::getInstance()->enqueueFile($file->id, Task3Rendering::class);
        } catch (Throwable $th) {
            $this->failCorrection($file, $th);
        } finally {
            if ($copyPath !== null) {
                @unlink($copyPath);
            }
        }
    }

    private function imageKey(File $file): string
    {
        $key = $file->straightenedKey();
        if (ObjectStore::getInstance()->exists($key)) {
            return $key;
        }

        return $file->contentKey();
    }

    private function withoutRescale(string $correction): string
    {
        $data = json_decode($correction, true);
        if (!is_array($data)) {
            return $correction;
        }
        if (is_numeric($data['rescale'] ?? null)) {
            $this->rescale = (float) $data['rescale'];
        }
        unset($data['rescale']);
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return $correction;
        }

        return $encoded;
    }
    protected function gdAnnotations(string $copyPath, string $copyName, string $correction): string
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
            'You annotate a scanned dictation by writing PHP GD annotations. '
            . 'Return only a PHP file that assigns an array to $GD_annotations. No markdown, no explanation. '
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
            . '$GD_annotations = [' . "\n"
            . '    ["fn" => "imagecolorallocate", "as" => "red", "rgb" => [200, 30, 30]],' . "\n"
            . '    ["fn" => "imagesetthickness", "args" => [3]],' . "\n"
            . '    ["fn" => "imageline", "args" => [40, 120, 260, 120], "color" => "red"],' . "\n"
            . '    ["fn" => "imagettftext", "args" => [18, 0, 270, 120], "color" => "red", "text" => "et"],' . "\n"
            . '];'
        );
        $request->add_file($copyPath, $copyName);
        $request->add_text("Correction listing the errors to mark:\n" . $correctedCorrection);
        $annotations = $request->call_text();
        $annotations = $this->addHandwrittenBaselineAnnotations($annotations, $data);

        if ($this->isDebug() && $this->hasCroppedCoordinates()) {
            $annotations = $this->addCropDebugAnnotation($annotations);
        }

        return $annotations;
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
     * Append a GD annotation drawing the crop rectangle when DEBUG is active.
     */
    protected function addCropDebugAnnotation(string $annotationsPhp): string
    {
        $rescale = ($this->rescale !== null && $this->rescale > 0.0) ? $this->rescale : 1.0;
        $x1 = $this->limitToImage((int) round(($this->cropped_x1 ?? 0) / $rescale), $this->imageWidth);
        $y1 = $this->limitToImage((int) round(($this->cropped_y1 ?? 0) / $rescale), $this->imageHeight);
        $x2 = $this->limitToImage((int) round(($this->cropped_x2 ?? ($this->cropped_x1 ?? 0)) / $rescale), $this->imageWidth);
        $y2 = $this->limitToImage((int) round(($this->cropped_y2 ?? ($this->cropped_y1 ?? 0)) / $rescale), $this->imageHeight);

        $cropAnnotation = [
            'fn' => 'imagerectangle',
            'args' => [$x1, $y1, $x2, $y2],
            'color' => 'red',
        ];

        try {
            $annotations = $this->loadAnnotations($annotationsPhp);
            $annotations[] = $cropAnnotation;
            return $this->formatAnnotations($annotations);
        } catch (\Throwable) {
            $cropAnnotationCode = "    ['fn' => 'imagerectangle', 'args' => [{$x1}, {$y1}, {$x2}, {$y2}], 'color' => 'red'],\n";
            $lastBracketPos = strrpos($annotationsPhp, ']');
            if ($lastBracketPos !== false) {
                return substr($annotationsPhp, 0, $lastBracketPos) . $cropAnnotationCode . substr($annotationsPhp, $lastBracketPos);
            }
            return $annotationsPhp;
        }
    }

/**
     * Format a list of GD annotations into PHP code.
     *
     * @param list<array<string, mixed>> $annotations
     */
    private function formatAnnotations(array $annotations): string
    {
        $lines = ["<?php\n\n\$GD_annotations = ["];
        foreach ($annotations as $annotation) {
            $parts = [];
            foreach ($annotation as $key => $val) {
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
    protected function addHandwrittenBaselineAnnotations(string $annotationsPhp, array $data): string
    {
        $marks = $this->handwrittenBaselineAnnotations($data);
        if ($marks === []) {
            return $annotationsPhp;
        }

        try {
            $annotations = $this->loadAnnotations($annotationsPhp);
            foreach ($marks as $mark) {
                $annotations[] = $mark;
            }
            return $this->formatAnnotations($annotations);
        } catch (\Throwable) {
            $snippet = '';
            foreach ($marks as $mark) {
                $y = (int) $mark['args'][1];
                $x2 = (int) $mark['args'][2];
                $snippet .= "    ['fn' => 'imageline', 'args' => [0, {$y}, {$x2}, {$y}], 'color' => 'red'],\n";
            }
            $lastBracketPos = strrpos($annotationsPhp, ']');
            if ($lastBracketPos !== false) {
                return substr($annotationsPhp, 0, $lastBracketPos) . $snippet . substr($annotationsPhp, $lastBracketPos);
            }
            return $annotationsPhp;
        }
    }

/**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function handwrittenBaselineAnnotations(array $data): array
    {
        $lines = $data['lines'] ?? null;
        if (!is_array($lines)) {
            return [];
        }

        $annotations = [];
        foreach ($lines as $line) {
            if (!is_array($line) || !is_numeric($line['y'] ?? null)) {
                continue;
            }
            $y = $this->limitToImage((int) $line['y'], $this->imageHeight);
            $x2 = $this->limitToImage(self::BASELINE_MARK_LENGTH, $this->imageWidth);
            $annotations[] = [
                'fn' => 'imageline',
                'args' => [0, $y, $x2, $y],
                'color' => 'red',
            ];
        }
        return $annotations;
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

/**
     * Accept only a PHP array assigned to $GD_annotations.
     *
     * @return list<array<string, mixed>>
     */
    private function loadAnnotations(string $source): array
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
                    throw new WSException('GD annotations contain unsupported PHP', 400);
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
                throw new WSException('GD annotations contain unsupported PHP', 400);
            }
            if ($id === T_VARIABLE) {
                if ($text !== '$GD_annotations') {
                    throw new WSException('GD annotations contain unsupported PHP', 400);
                }
                $variableCount++;
            }
        }
        if ($variableCount !== 1) {
            throw new WSException('GD annotations must assign $GD_annotations', 400);
        }

        $GD_annotations = null;
        try {
            eval('?>' . $source);
        } catch (\Throwable $th) {
            throw new WSException('GD annotations could not be read', 400);
        }
        if (!is_array($GD_annotations)) {
            throw new WSException('GD annotations must assign an array to $GD_annotations', 400);
        }
        return array_values($GD_annotations);
    }
}
