<?php

namespace Corrai\Subject\DictationFranceCM2;

use Corrai\Model\BaseAssessment;
use Corrai\Model\Student;
use Corrai\Utils\WSException;

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

    public function fileClass(): string
    {
        return File::class;
    }

    public function submissionClass(): string
    {
        return File::class;
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
     * Creates the assessment's instruction file from the template file if one exists.
     */
    public function createInstructionFileFromTemplate(?string $locale = null): ?File
    {
        if ($this->id === null || $this->id === '' || $this->school_id === '' || $this->user_id === '') {
            return null;
        }

        $path = $this->templateInstructionPath($locale);
        if ($path === null) {
            return null;
        }

        foreach ($this->listFileModels() as $file) {
            if ($file->type === 'instructions') {
                /** @var File $file */
                return $file;
            }
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        /** @var File $file */
        $file = $this->createFileModel(
            'instructions.md',
            $content,
            'text/markdown',
            'instructions',
            null
        );

        return $file;
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

    /**
     * @return string[]
     */
    private function pendingSubmissionIds(): array
    {
        $ids = [];
        foreach ($this->listFileModels() as $file) {
            if ($file->type !== 'submission' || $file->id === null || $file->id === '') {
                continue;
            }
            if ($file->status === 'corrected') {
                continue;
            }
            $ids[] = $file->id;
        }
        return $ids;
    }

    public function testCorrection(): array
    {
        return $this->correctFirstCopy();
    }
}
