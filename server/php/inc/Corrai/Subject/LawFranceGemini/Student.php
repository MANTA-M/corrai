<?php

namespace Corrai\Subject\LawFranceGemini;

use Corrai\Model\BaseStudent;
use Corrai\Model\SubmissionFile;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Http\WSException;
use Corrai\Utils\MenuLabels;

/**
 * Law France student model.
 */
class Student extends BaseStudent
{
    /**
     * @return array<int, array{key: string, label: string, icon: string, color: string}>
     */
    public function get_menu(?string $locale = null): array
    {
        $locale = MenuLabels::locale($locale);
        $items = parent::get_menu($locale);
        $geminiOcr = MenuLabels::item('gemini_ocr', $locale, 'text', MenuLabels::BLUE);
        $inserted = false;
        $menu = [];
        foreach ($items as $item) {
            $menu[] = $item;
            if (($item['key'] ?? '') === 'transcribe') {
                $menu[] = $geminiOcr;
                $inserted = true;
            }
        }
        if (!$inserted) {
            $menu[] = $geminiOcr;
        }
        return $menu;
    }

    /**
     * Run Gemini OCR on this student's submission images.
     *
     * @return list<SubmissionFile>
     */
    public function geminiOcr(): array
    {
        $submissions = $this->getSubmissions();
        if ($submissions === []) {
            throw new WSException('No submissions found for student ' . $this->name, 400);
        }

        if ($this->id !== null && $this->id !== '') {
            RedisQueue::getInstance()->enqueueStudent((string) $this->id, AssTaskGeminiOCR::class);
        }

        return $submissions;
    }

    /**
     * Correct this student's submissions.
     *
     * @return list<SubmissionFile>
     */
    public function correct(): array
    {
        $submissions = $this->getSubmissions();
        if ($submissions === []) {
            throw new WSException('No submissions found for student ' . $this->name, 400);
        }

        $this->status = 'under_correction';
        $this->save();

        if ($this->id !== null && $this->id !== '') {
            RedisQueue::getInstance()->enqueueStudent((string) $this->id, StudentTask1Correcting::class);
        }

        return $submissions;
    }
}

if (!class_exists('LawFranceGemini\Student', false)) {
    class_alias(Student::class, 'LawFranceGemini\Student');
}
