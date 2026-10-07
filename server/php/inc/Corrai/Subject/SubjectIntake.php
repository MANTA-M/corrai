<?php

namespace Corrai\Subject;

use Corrai\Model\Assessment;
use Corrai\Model\InputFile;
use Corrai\Model\OCRResult;
use Corrai\Model\User;
use Corrai\Queue\RedisQueue;
use Corrai\Task\TaskRotateAndCrop;
use Corrai\Utils\Image\FirstPageImage;
use Corrai\Utils\Store\HashId;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Utils;
use Corrai\Utils\Http\WSException;

/**
 * Creates an assessment from subject files.
 *
 * Files are read one by one until subject, country, and level are known:
 * HEIC is stored as WebP, an image is read by Google OCR, then the text is
 * classified. The files still waiting are stored and queued the same way as
 * a subject file added after the assessment exists.
 */
class SubjectIntake
{
    private const EXCERPT_CHARS = 8000;

    /**
     * @param list<array{path: string, name: string, contentType: ?string}> $files
     */
    public static function create(
        User $user,
        array $files,
        string $locale,
        SubjectPageReader $reader,
        SubjectImageOcr $ocr
    ): Assessment {
        if ($user->id === null || $user->id === '') {
            throw new WSException('User id is required', 401);
        }
        if ($files === []) {
            throw new WSException('No file uploaded', 400);
        }

        $assessment = null;
        try {
            $files = array_values($files);
            foreach ($files as $upload) {
                $name = $upload['name'];
                if ($name === '' || preg_match('/[\/\\\\]/', $name)) {
                    throw new WSException('Invalid file name', 400);
                }
                if (!is_file($upload['path'])) {
                    throw new WSException('Cannot read subject file', 400);
                }
            }

            $assessment = new Assessment();
            $assessment->id = HashId::create();
            $assessment->school_id = $user->school_id;
            $assessment->user_id = $user->id;
            $assessment->subject = 'Other';
            $assessment->country = $user->country;
            $assessment->name = '';
            $assessment->date = '';
            $assessment->correction_language = Assessment::normalizeLocale($locale);
            $assessment->save();

            $attributes = null;
            $lastError = null;
            $queueFrom = count($files);
            foreach ($files as $index => $upload) {
                $stored = $assessment->createFileFromPath(
                    $upload['name'],
                    $upload['path'],
                    $upload['contentType'],
                    'subject',
                    null,
                    false
                );
                if (!$stored instanceof InputFile) {
                    continue;
                }

                try {
                    $text = self::textFor($stored, $upload['path'], $upload['name'], $ocr);
                } catch (\Throwable $exception) {
                    error_log('[SubjectIntake] Subject text extraction failed for ' . $upload['name'] . ': ' . $exception->getMessage());
                    $lastError = $exception;
                    continue;
                }
                $text = trim($text ?? '');
                if ($text === '') {
                    continue;
                }
                if (mb_strlen($text) > self::EXCERPT_CHARS) {
                    $text = mb_substr($text, 0, self::EXCERPT_CHARS);
                }

                $pagePath = tempnam(sys_get_temp_dir(), 'subject_text_');
                if ($pagePath === false) {
                    throw new WSException('Cannot create a temporary file', 500);
                }
                try {
                    if (file_put_contents($pagePath, $text) === false) {
                        throw new WSException('Cannot create a temporary file', 500);
                    }
                    $raw = $reader->read($pagePath, 'page.txt', Catalog::tree($locale));
                    $attributes = SubjectDraft::normalize($raw, $user->country, $upload['name']);
                } catch (\Throwable $exception) {
                    error_log('[SubjectIntake] Subject page analysis failed for ' . $upload['name'] . ': ' . $exception->getMessage());
                    $lastError = $exception;
                    continue;
                } finally {
                    if (is_file($pagePath)) {
                        @unlink($pagePath);
                    }
                }

                if (self::classified($attributes)) {
                    $queueFrom = $index + 1;
                    break;
                }
            }

            if ($attributes === null) {
                if ($lastError !== null) {
                    error_log('[SubjectIntake] Subject analysis failed: ' . $lastError->getMessage());
                }
                if ($lastError instanceof WSException) {
                    throw $lastError;
                }
                $message = $lastError !== null ? 'Subject analysis failed: ' . $lastError->getMessage() : 'Subject analysis failed';
                throw new WSException($message, 502, $lastError);
            }

            $assessment->name = $attributes['name'];
            $assessment->subject = $attributes['subject'];
            $assessment->level = $attributes['level'];
            $assessment->country = $attributes['country'];
            $assessment->date = $attributes['date'];
            $assessment->save();

            for ($index = $queueFrom; $index < count($files); $index++) {
                $upload = $files[$index];
                $assessment->createFileFromPath(
                    $upload['name'],
                    $upload['path'],
                    $upload['contentType'],
                    'subject',
                    null
                );
            }

            return $assessment;
        } catch (\Throwable $exception) {
            if ($assessment !== null && $assessment->id !== null && $assessment->id !== '') {
                try {
                    $assessment->delete();
                } catch (\Throwable $cleanup) {
                    error_log('Failed to roll back subject intake: ' . $cleanup->getMessage());
                }
            }
            throw $exception;
        }
    }

    /**
     * Subject, country, and level are known when the catalog can be resolved.
     *
     * A subject with no levels is complete without one. Dictation stays open
     * until a country and a level are present, because those pick the pipeline.
     *
     * @param array{name: string, subject: string, level: ?string, country: ?string, date: string} $attributes
     */
    private static function classified(array $attributes): bool
    {
        $subject = $attributes['subject'];
        if ($subject === '' || strcasecmp($subject, 'Other') === 0) {
            return false;
        }

        $node = null;
        foreach (Catalog::tree('en') as $item) {
            if (strcasecmp($item['subject'], $subject) === 0) {
                $node = $item;
                break;
            }
        }
        if ($node === null) {
            return false;
        }

        $needsLevel = ($node['levels'] ?? []) !== [];
        if (!$needsLevel) {
            foreach ($node['countries'] as $country) {
                if (($country['levels'] ?? []) !== []) {
                    $needsLevel = true;
                    break;
                }
            }
        }
        if ($needsLevel && ($attributes['level'] === null || $attributes['level'] === '')) {
            return false;
        }
        if (($node['countries'] ?? []) !== [] && ($attributes['country'] === null || $attributes['country'] === '')) {
            return false;
        }

        return true;
    }

    private static function textFor(
        InputFile $file,
        string $originalPath,
        string $originalName,
        SubjectImageOcr $ocr
    ): ?string {
        $document = SubjectDocumentText::textFile($originalPath, $originalName);
        if ($document !== null) {
            try {
                $text = file_get_contents($document);
                return is_string($text) ? $text : null;
            } finally {
                if (is_file($document)) {
                    @unlink($document);
                }
            }
        }

        if (self::isPdf($originalPath, $originalName)) {
            $page = FirstPageImage::jpegFile($originalPath, $originalName);
            try {
                return self::recognize($file, $page, 'page.jpg', $ocr);
            } finally {
                if (is_file($page)) {
                    @unlink($page);
                }
            }
        }

        $copy = ObjectStore::getInstance()->downloadToTemp($file->contentKey());
        try {
            $bytes = file_get_contents($copy);
            if (!is_string($bytes) || $bytes === '' || !self::isImage($file->name, $bytes)) {
                return null;
            }
            $text = self::recognize($file, $copy, $file->name, $ocr);
            if ($file->id !== null && $file->id !== '') {
                try {
                    RedisQueue::getInstance()->enqueueFile($file->id, TaskRotateAndCrop::class);
                } catch (\Throwable $e) {
                    error_log(sprintf('[SubjectIntake] Failed to enqueue TaskRotateAndCrop for subject file %s: %s', (string) $file->id, $e->getMessage()));
                }
            }
            return $text;
        } finally {
            if (is_file($copy)) {
                @unlink($copy);
            }
        }
    }

    private static function recognize(
        InputFile $file,
        string $path,
        string $filename,
        SubjectImageOcr $ocr
    ): string {
        $result = OCRResult::from_google($ocr->recognize($path, $filename, $file));
        ObjectStore::getInstance()->putContents(
            $file->ocrResultKey(),
            $result->to_json(true),
            'application/json'
        );
        return $result->text;
    }

    private static function isPdf(string $path, string $filename): bool
    {
        $mime = strtolower(trim(explode(';', Utils::mimeTypeForFilename($filename))[0]));
        if ($mime === 'application/pdf') {
            return true;
        }
        $head = file_get_contents($path, false, null, 0, 5);
        return $head === '%PDF-';
    }

    private static function isImage(string $filename, string $bytes): bool
    {
        $mime = strtolower(trim(explode(';', Utils::mimeTypeForFilename($filename))[0]));
        if (str_starts_with($mime, 'image/')) {
            return true;
        }
        return @getimagesizefromstring($bytes) !== false;
    }
}
