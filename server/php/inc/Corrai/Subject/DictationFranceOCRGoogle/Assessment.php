<?php

namespace Corrai\Subject\DictationFranceOCRGoogle;

use Corrai\Model\BaseAssessment;
use Corrai\Model\Student;

class Assessment extends BaseAssessment
{
    public string $subject = 'Dictation';
    public ?string $country = 'fr';
    public ?string $level = 'OCRGoogle';

    public const NAMES = [
        'en' => 'Dictation OCR Google France',
        'fr' => 'Dictée OCR Google France',
        'ru' => 'Диктант OCR Google Франция',
        'uk' => 'Диктант OCR Google Франція',
        'es' => 'Dictado OCR Google Francia',
        'pt' => 'Ditado OCR Google França',
        'ro' => 'Dictare OCR Google Franța',
        'de' => 'Diktat OCR Google Frankreich',
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
}
