<?php

namespace Corrai\Subject\Other;

use Corrai\Model\Assessment;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\WSException;
use Corrai\Llm\Openrouter\ClaudeSonnetClient;
use Corrai\Llm\Openrouter\LlmClientFactory;
use Corrai\Subject\Dictation\Pipeline as DictationPipeline;

class Pipeline
{
    public const SUBJECT = 'Other';
    public const LEVEL = '';
    public const COUNTRY = '';
    public const NAMES = [
        'en' => 'Other',
        'fr' => 'Autre',
        'ru' => 'Другое',
        'uk' => 'Інше',
        'es' => 'Otro',
        'pt' => 'Outro',
        'ro' => 'Altul',
        'de' => 'Sonstiges',
    ];

    /**
     * Transcribe literally, correct, then annotate a submission in any other subject.
     *
     * @return array Updated assessment file list
     */
    public function run(Assessment $assessment, string $fileId, string $language): array
    {
        $file = $assessment->getFile($fileId);
        $student = $file->student ?? '';
        $filename = $file->name;
        $languageName = trim($language) !== '' ? trim($language) : 'French';
        $base = pathinfo($filename, PATHINFO_FILENAME);

        $store = ObjectStore::getInstance();
        $tmpPath = $store->downloadToTemp($file->contentKey());

        try {
            $assessment->deleteFilesOfType('debug', $student);
            $assessment->deleteFilesOfType('correction', $student);

            $transcription = $this->transcribe($tmpPath, $filename);
            $assessment->createFile(
                $base . ' transcription.txt',
                $transcription,
                'text/plain; charset=utf-8',
                'debug',
                $student
            );

            $correction = $this->correct($assessment, $transcription, $languageName);
            $assessment->createFile(
                $base . ' correction.txt',
                $correction,
                'text/plain; charset=utf-8',
                'debug',
                $student
            );

            $image = $this->annotate($tmpPath, $filename, $correction);
            $imageExt = self::extensionForMime($image['mime']);
            $assessment->createFile(
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

        return $assessment->list_files();
    }

    private function transcribe(string $tmpPath, string $filename): string
    {
        $request = new ClaudeSonnetClient();
        $request->set_system_content(
            'You are a careful transcription assistant. '
            . 'Follow the user instruction exactly. '
            . 'Return only the transcription, the unreadable marks, and the calligraphy score.'
        );
        $request->add_file($tmpPath, $filename);
        $request->add_text(DictationPipeline::TRANSCRIPTION_INSTRUCTION);
        return $request->call_text();
    }

    private function correct(Assessment $assessment, string $transcription, string $languageName): string
    {
        $instructionText = $assessment->instructionFilesText();
        $request = new ClaudeSonnetClient();
        $request->set_system_content(
            'You are a professor'
            . ' and you have to correct the following submission. '
            . 'The transcription is literal: do not assume wording was already fixed. '
            . 'Grade the answer, unreadable passages, and the calligraphy score already given. '
            . 'Respond with a textual correction including the mark and the appreciation. '
            . 'Use the language ' . $languageName
            . ' with the following instructions bellow. '
            . $instructionText
        );
        $request->add_text("Submission transcription:\n" . $transcription);
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
            'You annotate student assessment papers. '
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
