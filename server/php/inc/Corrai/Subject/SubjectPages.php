<?php

namespace Corrai\Subject;

use Corrai\Model\BaseAssessment;
use Corrai\Model\InputFile;
use Corrai\Model\SubjectFile;
use Corrai\Model\OCRResult;
use Corrai\Queue\RedisQueue;
use Corrai\Task\TaskRotateAndCrop;
use Corrai\Utils\Store\ObjectStore;
use Throwable;

/**
 * OCR statuses of subject page images, and the compiled page text.
 *
 * An image is queued as OCR requested. The OCR task sets OCR, then OCR refine
 * when the page must be rotated and cropped, then OCR done. Each time a page
 * reaches OCR done, the subject is compiled if no page is still in that pipeline.
 */
class SubjectPages
{
    public const STATUS_ASKED = 'ocr_asked';

    public const STATUS_OCR = 'ocr';

    public const STATUS_REFINE = 'ocr_refine';

    public const STATUS_DONE = 'ocr_done';

    /**
     * Statuses of a subject page whose text is not ready to compile.
     *
     * @var list<string>
     */
    private const PENDING = [
        self::STATUS_ASKED,
        self::STATUS_OCR,
        self::STATUS_REFINE,
    ];

    public static function isPageImage(InputFile $file): bool
    {
        $type = strtolower(trim(explode(';', $file->content_type, 2)[0]));
        if (str_starts_with($type, 'image/')) {
            return true;
        }
        $extension = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic', 'heif'], true);
    }

    /**
     * Mark a stored subject image as OCR requested and queue its OCR task.
     */
    public static function request(InputFile $file): void
    {
        $file->status = self::STATUS_ASKED;
        $file->saveAttributes();
        self::deleteCompile($file);
        if ($file->id === null || $file->id === '') {
            return;
        }
        RedisQueue::getInstance()->enqueueFile($file->id, SubjectPageOcr::class);
    }

    /**
     * Run OCR on a local copy of a subject page and advance its status.
     */
    public static function transcribe(
        InputFile $file,
        SubjectImageOcr $ocr,
        string $path,
        string $filename
    ): string {
        $file->status = self::STATUS_OCR;
        $file->saveAttributes();
        try {
            $result = OCRResult::from_google($ocr->recognize($path, $filename));
        } catch (Throwable $exception) {
            self::markError($file);
            throw $exception;
        }

        ObjectStore::getInstance()->putContents(
            $file->ocrResultKey(),
            $result->to_json(true),
            'application/json'
        );

        if ($result->words !== [] && self::isPageImage($file)) {
            $file->status = self::STATUS_REFINE;
            $file->saveAttributes();
            if ($file->id !== null && $file->id !== '') {
                RedisQueue::getInstance()->enqueueFile($file->id, TaskRotateAndCrop::class);
            }
            return $result->text;
        }

        self::finish($file);
        return $result->text;
    }

    /**
     * Subject page OCR is finished. Compile when every subject page is past OCR.
     */
    public static function finish(InputFile $file): void
    {
        if (!$file instanceof SubjectFile) {
            return;
        }
        if ($file->status !== self::STATUS_DONE) {
            $file->status = self::STATUS_DONE;
            $file->saveAttributes();
        }
        if ($file->assessment_id === '') {
            return;
        }
        self::compileIfReady(BaseAssessment::from_hash($file->assessment_id));
    }

    public static function compileIfReady(BaseAssessment $assessment): void
    {
        $files = self::subjectFiles($assessment);
        foreach ($files as $file) {
            if (in_array($file->status, self::PENDING, true)) {
                return;
            }
        }

        $pages = [];
        foreach ($files as $file) {
            $pages[] = self::pageText($file);
        }
        $json = json_encode(
            ['pages' => $pages],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        if ($json === false) {
            return;
        }
        ObjectStore::getInstance()->putContents(
            ObjectStore::assessmentSubjectCompileKey(
                $assessment->school_id,
                $assessment->user_id,
                (string) $assessment->id
            ),
            $json,
            'application/json'
        );
    }

    /**
     * @return list<InputFile>
     */
    private static function subjectFiles(BaseAssessment $assessment): array
    {
        $files = [];
        foreach ($assessment->listFileModels() as $file) {
            if ($file instanceof SubjectFile) {
                $files[] = $file;
            }
        }
        usort($files, static function (InputFile $a, InputFile $b): int {
            $byTime = $a->created <=> $b->created;
            if ($byTime !== 0) {
                return $byTime;
            }
            return strcmp($a->name, $b->name);
        });
        return $files;
    }

    private static function pageText(InputFile $file): string
    {
        $store = ObjectStore::getInstance();
        if ($store->exists($file->ocrResultKey())) {
            try {
                return OCRResult::from_json($store->getContents($file->ocrResultKey()))->text;
            } catch (Throwable $exception) {
                error_log('[SubjectPages] Unreadable OCR result for ' . (string) $file->id . ': ' . $exception->getMessage());
            }
        }
        if (self::isPageImage($file)) {
            return '';
        }
        try {
            return $store->getContents($file->contentKey());
        } catch (Throwable $exception) {
            error_log('[SubjectPages] Unreadable subject file ' . (string) $file->id . ': ' . $exception->getMessage());
            return '';
        }
    }

    private static function deleteCompile(InputFile $file): void
    {
        if ($file->school_id === '' || $file->user_id === '' || $file->assessment_id === '') {
            return;
        }
        $store = ObjectStore::getInstance();
        $key = ObjectStore::assessmentSubjectCompileKey($file->school_id, $file->user_id, $file->assessment_id);
        if ($store->exists($key)) {
            $store->delete($key);
        }
    }

    private static function markError(InputFile $file): void
    {
        try {
            $file->status = 'error';
            $file->saveAttributes();
        } catch (Throwable $ignore) {
        }
    }
}
