<?php

namespace Corrai\Subject;

use Corrai\Model\Exam;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\WSException;
use Corrai\LlmClient\ClaudeSonnetClient;
use Corrai\LlmClient\LlmClientFactory;

class Dictation
{
    public const TRANSCRIPTION_INSTRUCTION =
        'Transcript only what is writen without correcting it. '
        . 'If something is badly written, put a mark to say it\'s unreadable. '
        . 'Value de quality of caligraphy from 0.0 to 1.0.';

    /**
     * Transcribe literally, correct, then annotate a dictation submission.
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
            'You are a careful transcription assistant for a student dictation. '
            . 'Follow the user instruction exactly. '
            . 'Return only the transcription, the unreadable marks, and the calligraphy score.'
        );
        $request->add_file($tmpPath, $filename);
        $request->add_text(self::TRANSCRIPTION_INSTRUCTION);
        return $request->call_text();
    }

    private function correct(Exam $exam, string $transcription, string $languageName): string
    {
        $instructionText = $exam->instructionFilesText();
        $request = new ClaudeSonnetClient();
        $request->set_system_content(
            'You are a professor correcting a dictation'
            . '. The transcription is literal: do not assume spelling was already fixed. '
            . 'Grade spelling, grammar, unreadable passages, and the calligraphy score already given. '
            . 'Respond with a textual correction including the mark and the appreciation. '
            . 'Use the language ' . $languageName
            . ' with the following instructions bellow. '
            . $instructionText
        );
        $request->add_text("Dictation transcription:\n" . $transcription);
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
            'You annotate student dictation papers. '
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
