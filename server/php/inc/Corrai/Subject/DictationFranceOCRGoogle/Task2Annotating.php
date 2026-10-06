<?php

namespace Corrai\Subject\DictationFranceOCRGoogle;

use Corrai\Llm\Openrouter\ClaudeSonnetClient;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\WSException;
use Throwable;

/**
 * Turn found errors into markup annotations. Same step as File::on_errors_found.
 */
class Task2Annotating extends PathQueueItemTask
{

    private ?int $imageWidth = null;
    private ?int $imageHeight = null;

    protected function process(object $queue_item_data, string $s3_path): void
    {
        $this->annotate($this->loadFile($s3_path));
    }

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
            $copyPath = $store->downloadToTemp($file->contentKey());

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

    public function gdAnnotations(string $copyPath, string $copyName, string $correction): string
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

        $data = $this->limitErrorBoxes($data);
        $correctedCorrection = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
            . '- ["fn" => "imagettftext", "args" => [size, angle, x, y], "color" => "red", "text" => "expected text"]' . "\n"
            . 'For each error, draw one red underline (imageline) under the student writing. '
            . 'The underline sits just below the error box, from its left edge to its right edge. '
            . 'Then write the expected text from the correction with imagettftext directly under that underline. '
            . 'imagettftext y is the baseline, so place it a few pixels below the underline, left-aligned with the line. '
            . 'The text is the expected field, nothing else. Do not put the text beside the line or above it. '
            . 'Then add the grade and the appreciation from the correction JSON. '
            . 'Write the note at the top right with imagettftext, formatted as the number followed by "/20" '
            . '(for a note of 15, the text is "15/20"). Right-align it so the text ends about 24 pixels before the right edge. '
            . 'Write the appreciation along the bottom of the image with imagettftext, left margin about 40 pixels, '
            . 'last line about 24 pixels above the bottom edge. The appreciation is Markdown: drop the marks (#, *, _) '
            . 'and draw the readable lines, wrapping so every line stays inside the image. '
            . 'Example:' . "\n"
            . "<?php\n"
            . '$GD_annotations = [' . "\n"
            . '    ["fn" => "imagecolorallocate", "as" => "red", "rgb" => [200, 30, 30]],' . "\n"
            . '    ["fn" => "imagesetthickness", "args" => [3]],' . "\n"
            . '    ["fn" => "imageline", "args" => [40, 128, 260, 128], "color" => "red"],' . "\n"
            . '    ["fn" => "imagettftext", "args" => [18, 0, 40, 152], "color" => "red", "text" => "et"],' . "\n"
            . '];'
        );
        $request->add_file($copyPath, $copyName);
        $request->add_text("Correction listing the errors to mark:\n" . $correctedCorrection);
        return $request->call_text();
    }

    /**
     * Keep each error box inside the source image.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function limitErrorBoxes(array $data): array
    {
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

            $error['box']['x1'] = $this->limitX((int) round((float) $box['x1']));
            $error['box']['y1'] = $this->limitY((int) round((float) $box['y1']));
            $error['box']['x2'] = $this->limitX((int) round((float) $box['x2']));
            $error['box']['y2'] = $this->limitY((int) round((float) $box['y2']));
            $errors[$index] = $error;
        }

        $data['errors'] = $errors;

        return $data;
    }

    private function limitX(int $inSource): int
    {
        if ($this->imageWidth !== null && $this->imageWidth >= 1 && $inSource > $this->imageWidth) {
            error_log(sprintf(
                '[DictationFranceOCRGoogle] X %d exceeds source image width %d',
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
                '[DictationFranceOCRGoogle] Y %d exceeds source image height %d',
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
}
