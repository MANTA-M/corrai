<?php

namespace Corrai\Subject;

use Corrai\Model\Assessment;
use Corrai\Model\User;
use Corrai\Utils\Image\FirstPageImage;
use Corrai\Utils\Store\HashId;
use Corrai\Utils\Http\WSException;

/**
 * Creates the assessment in object storage, stores the subject file, then fills attributes from the first page.
 */
class SubjectIntake
{
    public static function create(
        User $user,
        string $localPath,
        string $filename,
        ?string $contentType,
        string $locale,
        SubjectPageReader $reader
    ): Assessment {
        if ($user->id === null || $user->id === '') {
            throw new WSException('User id is required', 401);
        }

        $pagePath = SubjectDocumentText::textFile($localPath, $filename);
        $pageName = 'page.txt';
        if ($pagePath === null) {
            $pagePath = FirstPageImage::jpegFile($localPath, $filename);
            $pageName = 'page.jpg';
        }
        $assessment = null;
        try {
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

            $assessment->createFileFromPath($filename, $localPath, $contentType, 'subject', null);

            $raw = $reader->read($pagePath, $pageName, Catalog::tree($locale));
            $attributes = SubjectDraft::normalize($raw, $user->country, $filename);
            $assessment->name = $attributes['name'];
            $assessment->subject = $attributes['subject'];
            $assessment->level = $attributes['level'];
            $assessment->country = $attributes['country'];
            $assessment->date = $attributes['date'];
            $assessment->save();

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
        } finally {
            if (is_file($pagePath)) {
                @unlink($pagePath);
            }
        }
    }
}
