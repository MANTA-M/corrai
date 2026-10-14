<?php

namespace Corrai\Subject\LawFranceGemini;

use Corrai\Clients\Openrouter\GeminiFlashClient;
use Corrai\Model\BaseStudent;
use Corrai\Model\SubmissionFile;
use Corrai\Model\Task\QueueItemTask;
use Corrai\Utils\Http\WSException;
use Corrai\Utils\Image\FirstPageImage;
use Corrai\Utils\Store\ObjectStore;
use Throwable;

/**
 * Gemini OCR of a student's submission images into compiled_submission.json.
 *
 * Sends every submission image (sorted by name) in a single GeminiFlash request
 * asking to transcript all manuscript text, then stores one page of text.
 */
class AssTaskGeminiOCR extends QueueItemTask
{
    public function __construct(private readonly ?GeminiFlashClient $client = null)
    {
    }

    public function process_task(object $queue_item_data): void
    {
        $studentId = $queue_item_data->student_id ?? null;
        if ($studentId === null || $studentId === '') {
            error_log('[AssTaskGeminiOCR] Student ID is missing on ticket');
            return;
        }

        error_log('Processing task: ' . ($queue_item_data->task_id ?? static::class));
        try {
            $student = BaseStudent::from_hash($studentId);
            $this->processStudent($student);
        } catch (Throwable $e) {
            error_log(sprintf('[AssTaskGeminiOCR] Error processing student %s: %s', $studentId, $e->getMessage()));
        }
        error_log('Task processed: ' . ($queue_item_data->task_id ?? static::class));
    }

    protected function process(object $queue_item_data): void
    {
        $this->process_task($queue_item_data);
    }

    public function processStudent(BaseStudent $student): void
    {
        $files = $student->getSubmissions();
        if ($files === []) {
            throw new WSException('No submissions found for student ' . $student->name, 400);
        }
        usort($files, static function (SubmissionFile $a, SubmissionFile $b): int {
            return strcmp($a->name, $b->name);
        });

        $result = $this->transcriptManuscript($files);

        (new StudentTask1Correcting())->storeCompiledSubmissionFile($student, [
            'pages' => [$result['text']],
        ]);
    }

    /**
     * @param list<SubmissionFile> $files
     * @return array{text: string, caligraphy_quality: float}
     */
    public function transcriptManuscript(array $files): array
    {
        $store = ObjectStore::getInstance();
        $tempPaths = [];
        try {
            $client = $this->client ?? new GeminiFlashClient();
            $client->set_system_content(
                'You transcript handwritten exam papers. '
                . 'Respond only with a JSON object {"text": string, "caligraphy_quality": number}.'
            );
            $client->set_json_response('gemini_ocr', [
                'type' => 'object',
                'properties' => [
                    'text' => [
                        'type' => 'string',
                        'description' => 'Full transcript of all manuscript text from the images, in order.',
                    ],
                    'caligraphy_quality' => [
                        'type' => 'number',
                        'description' => 'Handwriting / calligraphy quality from 0 to 1.',
                    ],
                ],
                'required' => ['text', 'caligraphy_quality'],
                'additionalProperties' => false,
            ]);
            $client->add_text('Transcript all manuscript text');

            foreach ($files as $file) {
                $copyPath = $store->downloadToTemp($file->contentKey());
                $tempPaths[] = $copyPath;
                // Gemini rejects webp (and other unsupported mime types); convert to JPEG on the fly.
                $jpegPath = FirstPageImage::jpegFile($copyPath, $file->name);
                $tempPaths[] = $jpegPath;
                $client->add_file($jpegPath, self::jpegFilename($file->name));
            }

            $result = $client->call();
        } finally {
            foreach ($tempPaths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        $response = $result['response'] ?? null;
        if (!is_array($response) || !array_key_exists('text', $response)) {
            $detail = is_string($response) ? $response : 'empty OCR response';
            throw new WSException('Gemini OCR failed: ' . $detail, 500);
        }

        $text = $response['text'];
        if (!is_string($text)) {
            throw new WSException('Gemini OCR text must be a string', 500);
        }

        $quality = $response['caligraphy_quality'] ?? null;
        if (!is_int($quality) && !is_float($quality)) {
            if (is_numeric($quality)) {
                $quality = (float) $quality;
            } else {
                throw new WSException('Gemini OCR caligraphy_quality must be a number', 500);
            }
        }
        $quality = max(0.0, min(1.0, (float) $quality));

        return [
            'text' => $text,
            'caligraphy_quality' => $quality,
        ];
    }

    private static function jpegFilename(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        if (!is_string($base) || $base === '') {
            $base = 'page';
        }
        return $base . '.jpg';
    }
}

if (!class_exists('LawFranceGemini\\AssTaskGeminiOCR', false)) {
    class_alias(AssTaskGeminiOCR::class, 'LawFranceGemini\\AssTaskGeminiOCR');
}
