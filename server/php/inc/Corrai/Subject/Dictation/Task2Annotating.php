<?php

namespace Corrai\Subject\Dictation;

use Corrai\Clients\Openrouter\ClaudeSonnetClient;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\WSException;
use Throwable;

class Task2Annotating extends PathQueueItemTask
{

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


    private function gdAnnotations(string $copyPath, string $copyName, string $correction): string
    {
        $size = @getimagesize($copyPath);
        $width = is_array($size) ? (int) $size[0] : 0;
        $height = is_array($size) ? (int) $size[1] : 0;
        if ($width < 1 || $height < 1) {
            throw new WSException('The source file is not an image GD can annotate', 400);
        }

        $request = new ClaudeSonnetClient();
        $request->set_system_content(
            'You annotate a scanned dictation by writing PHP GD annotations. '
            . 'Return only a PHP file that assigns an array to $GD_annotations. No markdown, no explanation. '
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
            . '$GD_annotations = [' . "\n"
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
