<?php

namespace Corrai\Subject\Math;

use Corrai\Model\Exam;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\WSException;
use Corrai\LlmClient\ClaudeSonnetClient;
use Corrai\LlmClient\LlmClientFactory;

class Pipeline
{
    public const SUBJECT = 'Math';
    public const LEVEL = '';
    public const COUNTRY = '';
    public const NAMES = [
        'en' => 'Mathematics',
        'fr' => 'Math',
        'ru' => 'Математика',
        'uk' => 'Математика',
        'es' => 'Matemáticas',
        'pt' => 'Matemática',
        'ro' => 'Matematică',
        'de' => 'Mathematik',
    ];

    /**
     * Transcribe, correct, then annotate a submission.
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

        try {
            $transcription = $this->transcribe($tmpPath, $filename);
            $exam->createFile(
                $base . ' transcription.txt',
                $transcription,
                'text/plain; charset=utf-8',
                null,
                $student
            );

            $correction = $this->correct(
                $exam,
                $transcription,
                $languageName
            );
            $exam->createFile(
                $base . ' correction.txt',
                $correction,
                'text/plain; charset=utf-8',
                'correction',
                $student
            );

            $image = $this->annotate($tmpPath, $filename, $correction);
            $imageExt = self::extensionForMime($image['mime']);
            $exam->createFile(
                $base . ' annotated.' . $imageExt,
                $image['body'],
                $image['mime'],
                'correction',
                $student
            );
        } catch (WSException $e) {
            throw $e;
        } catch (\Throwable $th) {
            throw new WSException($th->getMessage(), 400);
        } finally {
            @unlink($tmpPath);
        }

        return $exam->list_files();
    }

    private function transcribe(string $tmpPath, string $filename): string
    {
        $request = new ClaudeSonnetClient();
        $request->set_system_content(
            'You are a careful transcription assistant. '
            . 'Transcribe the submitted exam paper into LaTeX. '
            . 'Return only the LaTeX transcription with no extra commentary.'
        );
        $request->add_file($tmpPath, $filename);
        $request->add_text('Transcribe this submission into LaTeX.');
        return $request->call_text();
    }

    private function correct(Exam $exam, string $transcription, string $languageName): string
    {
        $instructionText = $exam->instructionFilesText();
        $request = new ClaudeSonnetClient();
        $request->set_system_content(
            'You are a professor in mathematics'
            . ' and you have to correct the following submission. '
            . 'Respond with a textual correction including the mark and the appreciation. '
            . 'Use the language ' . $languageName
            . ' with the following instructions bellow. '
            . $instructionText
        );
        $request->add_text("Submission transcription (LaTeX):\n" . $transcription);
        return $request->call_text();
    }

    /**
     * @return array{mime: string, body: string}
     */
    private function annotate(string $tmpPath, string $filename, string $correction): array
    {
        $imageModel = $_ENV['OPENROUTER_IMAGE_MODEL'] ?? 'google/gemini-2.5-flash-image';
        $request = LlmClientFactory::create($imageModel);
        $request->set_system_content(
            'You annotate student exam papers. '
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

    private static function extensionForMime(string $mime): string
    {
        $map = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        $baseMime = strtolower(trim(explode(';', $mime)[0]));
        return $map[$baseMime] ?? 'png';
    }
}
