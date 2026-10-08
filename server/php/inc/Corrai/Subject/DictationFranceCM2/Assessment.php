<?php

namespace Corrai\Subject\DictationFranceCM2;

use Corrai\Model\BaseAssessment;
use Corrai\Model\Student;
use Corrai\Model\SubjectFile;
use Corrai\Model\SubmissionFile;
use Corrai\Utils\Http\WSException;

class Assessment extends BaseAssessment
{
    public string $subject = 'Dictation';
    public ?string $country = 'fr';
    public ?string $level = 'CM2';

    public const NAMES = [
        'en' => 'Dictation CM2 France',
        'fr' => 'Dictée CM2 France',
        'ru' => 'Диктант CM2 Франция',
        'uk' => 'Диктант CM2 Франція',
        'es' => 'Dictado CM2 Francia',
        'pt' => 'Ditado CM2 Portugal',
        'ro' => 'Dictare CM2 Franța',
        'de' => 'Diktat CM2 Frankreich',
    ];

    public function assessmentItemClass(): string
    {
        return self::class;
    }

    public function studentClass(): string
    {
        return Student::class;
    }

    public function subjectFileClass(): string
    {
        return SubjectFile::class;
    }

    public function submissionClass(): string
    {
        return Submission::class;
    }

    /**
     * Resolves the template instruction file for this assessment.
     * Searches for "template_instruction_{locale}.md" first, and falls back to "template_instruction.md"
     * (also supporting plural "template_instructions*.md" filenames).
     */
    public function templateInstructionPath(?string $locale = null): ?string
    {
        $dir = __DIR__;
        $locale = $locale !== null && trim($locale) !== '' ? trim($locale) : $this->correction_language;
        $locales = [];
        $raw = strtolower(trim($locale));
        if ($raw !== '') {
            $locales[] = $raw;
            $dash = strpos($raw, '-');
            if ($dash !== false) {
                $locales[] = substr($raw, 0, $dash);
            }
            $underscore = strpos($raw, '_');
            if ($underscore !== false) {
                $locales[] = substr($raw, 0, $underscore);
            }
        }
        $locales = array_unique(array_filter($locales));

        $candidates = [];
        foreach ($locales as $loc) {
            $candidates[] = $dir . '/template_instruction_' . $loc . '.md';
            $candidates[] = $dir . '/template_instructions_' . $loc . '.md';
        }
        $candidates[] = $dir . '/template_instruction.md';
        $candidates[] = $dir . '/template_instructions.md';

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Copies still waiting for a correction: unclassified files, or submissions
     * that are not already corrected.
     */
    public function pricedCopyCount(): int
    {
        $unclassified = count($this->unclassifiedFileIds());
        if ($unclassified > 0) {
            return $unclassified;
        }

        return count($this->pendingSubmissionIds());
    }

    public function startCorrection(): array
    {
        if ($this->unclassifiedFileIds() !== []) {
            return $this->correctUnclassifiedFiles();
        }

        $ids = $this->pendingSubmissionIds();
        if ($ids === []) {
            throw new WSException('No copies to correct', 400);
        }
        foreach ($ids as $fileId) {
            $this->correctSubmission($fileId);
        }
        return $this->list_files();
    }
}
