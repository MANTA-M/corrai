<?php

namespace Corrai\Subject\Law;

use Corrai\Llm\Openrouter\ClaudeSonnetClient;
use Corrai\Llm\Openrouter\LlmClientFactory;
use Corrai\Model\BaseAssessment;
use Corrai\Model\SubmissionFile;
use Corrai\Model\Task\PathQueueItemTask;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\WSException;
use Throwable;

class Task3Annotating extends PathQueueItemTask
{
    protected function process(object $queue_item_data, string $s3_path): void
    {
        $file = $this->loadFile($s3_path);
        if ($file instanceof SubmissionFile) {
            $this->annotateSubmission($file);
        }
    }

    public function annotateSubmission(SubmissionFile $file): void
    {
        if ($file->type !== 'submission') {
            return;
        }

        $copyPath = null;
        try {
            $assessment = $this->loadAssessment($file);
            $store = ObjectStore::getInstance();
            $annotationsKey = $file->markupAnnotationsKey();
            if (!$store->exists($annotationsKey)) {
                throw new WSException('Correction text is missing for this file', 400);
            }
            $correction = $store->getContents($annotationsKey);
            $copyPath = $store->downloadToTemp($file->contentKey());
            $image = $this->annotate($copyPath, $file->name, $correction);
            $student = $file->student ?? '';
            $assessment->deleteFilesOfType('correction', $student);
            $png = $this->ensurePng($image);
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
     * @return array{mime: string, body: string}
     */
    private function annotate(string $tmpPath, string $filename, string $correction): array
    {
        $imageModel = $_ENV['OPENROUTER_IMAGE_MODEL'] ?? 'google/gemini-2.5-flash-image';
        $request = LlmClientFactory::create($imageModel);
        $request->set_system_content(
            'You annotate student law papers. '
            . 'Using the correction text provided, annotate the source image accordingly. '
            . 'Return an annotated image.'
        );
        $request->enable_image_output();
        $request->add_text("Correction to apply as annotations:\n" . $correction);
        $request->add_file($tmpPath, $filename);
        $result = $request->call_annotation();

        if ($result['images'] === []) {
            throw new WSException('The model did not return an annotated image', 400);
        }

        return $result['images'][0];
    }

    /**
     * @param array{mime: string, body: string} $image
     */
    private function ensurePng(array $image): string
    {
        if ($image['mime'] === 'image/png') {
            return $image['body'];
        }
        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($image['body']);
            if ($img !== false) {
                ob_start();
                imagepng($img);
                $png = ob_get_clean();
                imagedestroy($img);
                if (is_string($png) && $png !== '') {
                    return $png;
                }
            }
        }
        return $image['body'];
    }
}
