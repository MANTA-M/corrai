<?php

namespace Corrai\Subject;

use Corrai\Model\Exam;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\WSException;
use Corrai\LlmClient\ClaudeSonnetClient;
use Corrai\LlmClient\Qwen25Vl72bInstructClient;

class Dictation
{
    public const TRANSCRIPTION_INSTRUCTION =
        'Transcript only what is writen without correcting it. DO NOT ADD ANY LETTER OR SIGN. '
        . 'If something is badly written, put a mark to say it\'s unreadable. '
        . 'Value de quality of caligraphy from 0.0 to 1.0.';

    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

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
                'correction',
                $student
            );

            $directivesPhp = $this->gdDirectives($tmpPath, $filename, $correction);
            $exam->createFile(
                $base . ' directives.php',
                $directivesPhp,
                'text/plain; charset=utf-8',
                null,
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

    private function findErrors(
        Exam $exam,
        string $copyPath,
        string $copyName,
        string $solutionPath,
        string $solutionName,
        string $languageName
    ): string {
        $instructionText = $exam->instructionFilesText();
        $request = new ClaudeSonnetClient();
        $request->set_system_content(
            'First step, find the errors: You decipher a student dictation copy by reading it against the official corrigé. '
            . 'Identify every error compared with the corrigé: spelling, accents, missing or extra words, '
            . 'punctuation, word order, and passages that are unreadable. '
            . 'Second step, filter the errors: Do not get missing space errors. '
            . 'Do not count as errors badly written letters and keep only clear spelling or grammar errors. '
            . 'Step three, write the correction: Do not rewrite the full dictation. List only the errors. '
            . 'For each error give the student writing, the expected text from the corrigé, and the kind of mistake. '
            . 'Write in ' . $languageName . '. '
            . "Follow these exam-specific instructions:\n"
            . $instructionText
        );
        $request->add_text('Official corrigé:');
        $request->add_file($solutionPath, $solutionName);
        $request->add_text('Student copy to decipher:');
        $request->add_file($copyPath, $copyName);
        return $request->call_text();
    }

    private function gdDirectives(string $copyPath, string $copyName, string $correction): string
    {
        $size = @getimagesize($copyPath);
        $width = is_array($size) ? (int) $size[0] : 0;
        $height = is_array($size) ? (int) $size[1] : 0;
        if ($width < 1 || $height < 1) {
            throw new WSException('The source file is not an image GD can annotate', 400);
        }

        $request = new ClaudeSonnetClient();
        $request->set_system_content(
            'You annotate a scanned dictation by writing PHP GD directives. '
            . 'Return only a PHP file that assigns an array to $GD_directives. No markdown, no explanation. '
            . 'The image is ' . $width . ' by ' . $height . ' pixels, origin at the top-left. '
            . 'Place marks on the student writing that the correction lists as wrong. '
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
        $request->add_text("Correction listing the errors to mark:\n" . $correction);
        return $request->call_text();
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
            throw new WSException('GD directive uses an unknown color', 400);
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
