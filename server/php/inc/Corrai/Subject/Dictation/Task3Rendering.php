<?php

namespace Corrai\Subject\Dictation;

use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\WSException;
use Throwable;

class Task3Rendering extends PathQueueItemTask
{
    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    protected function process(object $queue_item_data, string $s3_path): void
    {
        $this->render($this->loadFile($s3_path));
    }
    /**
     * Render the corrected copy. Same step as Submission::on_annotations.
     */
    public function render(Submission $file): void
    {

        $copyPath = null;
        try {
            $assessment = $this->loadAssessment($file);
            $store = ObjectStore::getInstance();
            $annotationsKey = $file->markupAnnotationsKey();
            if (!$store->exists($annotationsKey)) {
                throw new WSException('Annotations are missing for this file', 400);
            }
            $annotationsPhp = $store->getContents($annotationsKey);
            $copyPath = $store->downloadToTemp($file->contentKey());
            $png = $this->renderCorrection($copyPath, $annotationsPhp);

            $student = $file->student ?? '';
            $assessment->deleteFilesOfType('correction', $student);
            $assessment->createFile(
                'correction.png',
                $png,
                'image/png',
                'correction',
                $student !== '' ? $student : null
            );

            $file->status = 'corrected';
            $file->saveAttributes();
            $file->appendEvent('Correction rendered');
        } catch (Throwable $th) {
            $this->failCorrection($file, $th);
        } finally {
            if ($copyPath !== null) {
                @unlink($copyPath);
            }
        }
    }

/**
     * Draw $GD_annotations onto a copy of the source image.
     */
    private function renderCorrection(string $copyPath, string $annotationsPhp): string
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
        foreach ($this->loadAnnotations($annotationsPhp) as $annotation) {
            $this->applyAnnotation($image, $annotation, $colors);
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

/**
     * @param array<string, int> $colors
     * @param array<string, mixed> $annotation
     */
    private function applyAnnotation(\GdImage $image, array $annotation, array &$colors): void
    {
        $fn = $annotation['fn'] ?? '';
        if ($fn === 'imagecolorallocate') {
            $name = $annotation['as'] ?? '';
            $rgb = $annotation['rgb'] ?? null;
            if (!is_string($name) || $name === '' || !is_array($rgb) || count($rgb) < 3) {
                throw new WSException('Invalid imagecolorallocate annotation', 400);
            }
            $color = imagecolorallocate($image, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2]);
            if ($color === false) {
                throw new WSException('GD could not allocate a color', 500);
            }
            $colors[$name] = $color;
            return;
        }

        if ($fn === 'imagesetthickness') {
            $px = $annotation['args'][0] ?? null;
            if (!is_numeric($px)) {
                throw new WSException('Invalid imagesetthickness annotation', 400);
            }
            imagesetthickness($image, (int) $px);
            return;
        }

        $colorName = $annotation['color'] ?? '';
        if (!is_string($colorName) || !isset($colors[$colorName])) {
            throw new WSException('GD annotation uses an unknown color', 400);
        }
        $color = $colors[$colorName];
        $args = $annotation['args'] ?? null;
        if (!is_array($args)) {
            throw new WSException('Invalid GD annotation', 400);
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
                && is_string($annotation['text'] ?? null)
                && imagettftext(
                    $image,
                    $numbers[0],
                    $numbers[1],
                    $numbers[2],
                    $numbers[3],
                    $color,
                    self::FONT,
                    $annotation['text']
                ) !== false,
            default => throw new WSException('Unsupported GD annotation', 400),
        };
        if ($drawn !== true) {
            throw new WSException('GD failed to draw an annotation', 500);
        }
    }
}
