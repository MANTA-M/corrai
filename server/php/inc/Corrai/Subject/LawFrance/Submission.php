<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Model\InputFile;
use Corrai\Model\SubjectFile;
use Corrai\Model\SubmissionFile;
use Corrai\Queue\RedisQueue;
use Corrai\Task\Thumbnail;

/**
 * Submission state machine.
 *
 * Statuses: stored|correction_asked → transcribing → transcribed → correction_ready → corrected
 */
class Submission extends SubmissionFile
{
    public function on_stored(): void
    {
        $this->status = 'stored';
        self::queueThumbnail($this);
    }

    public function on_store(): void
    {
        $this->on_stored();
    }

    /**
     * Queue a 250px-wide thumbnail for an image stored as a copy or a subject.
     */
    public static function queueThumbnail(SubmissionFile $file): void
    {
        if ($file->thumbnail || $file->id === null || $file->id === '') {
            return;
        }
        if (!self::isRasterImage($file)) {
            return;
        }
        RedisQueue::getInstance()->enqueueFile($file->id, Thumbnail::class);
    }

    public static function isRasterImage(InputFile $file): bool
    {
        $type = strtolower(trim(explode(';', $file->content_type, 2)[0]));
        if (in_array($type, [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/bmp',
            'image/tiff',
            'image/heic',
            'image/heif',
        ], true)) {
            return true;
        }
        $extension = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic', 'heif'], true);
    }

    public function on_correction_asked(): void
    {
        $this->status = 'transcribing';
        try {
            $this->saveAttributes();
        } catch (\Throwable) {
        }
        if ($this->id !== null && $this->id !== '') {
            RedisQueue::getInstance()->enqueueFile($this->id, SubmissionTask1Ocr::class);
        }
    }

    public function on_transcribed(): void
    {
        (new Task2Correcting())->correctSubmission($this);
    }

    public function on_correction_ready(): void
    {
        (new Task3Annotating())->annotateSubmission($this);
    }
}

if (!class_exists('LawFrance\Submission', false)) {
    class_alias(Submission::class, 'LawFrance\Submission');
}
