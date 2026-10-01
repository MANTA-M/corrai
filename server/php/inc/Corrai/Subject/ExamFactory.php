<?php

namespace Corrai\Subject;

use Corrai\Model\BaseExam;
use Corrai\Model\Exam as GenericExam;

/**
 * Instantiates the most specific subject Exam for stored attributes.
 */
class ExamFactory
{
    /**
     * Every subject Exam class under Corrai\Subject.
     *
     * @return list<class-string<BaseExam>>
     */
    public static function classes(): array
    {
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'Exam.php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(__DIR__) + 1, -strlen('/Exam.php'));
            $classes[] = 'Corrai\\Subject\\' . str_replace('/', '\\', $relative) . '\\Exam';
        }
        sort($classes);
        return $classes;
    }

    /**
     * Most specific Exam class for a subject, country, and level.
     *
     * @return class-string<BaseExam>
     */
    public static function examClass(string $subject, string $country, string $level): string
    {
        $countryOnly = null;
        $bare = null;
        foreach (self::classes() as $class) {
            $defaults = (new \ReflectionClass($class))->getDefaultProperties();
            $entrySubject = (string) ($defaults['subject'] ?? '');
            if ($entrySubject !== $subject) {
                continue;
            }
            $entryCountry = self::normalizeOptional($defaults['country'] ?? null);
            $entryLevel = self::normalizeOptional($defaults['level'] ?? null);
            if ($entryCountry === $country && $entryLevel === $level) {
                return $class;
            }
            if ($entryCountry === $country && $entryLevel === '') {
                $countryOnly = $class;
            }
            if ($entryCountry === '' && $entryLevel === '') {
                $bare = $class;
            }
        }
        return $countryOnly ?? $bare ?? GenericExam::class;
    }

    /**
     * Build an Exam from S3 attribute payload plus path ids.
     */
    public static function fromAttributes(
        array $data,
        string $schoolId,
        string $userId,
        string $examId
    ): BaseExam {
        $subject = (string) ($data['subject'] ?? '');
        $country = self::normalizeOptional($data['country'] ?? null);
        $level = self::normalizeOptional($data['level'] ?? null);
        $class = self::examClass($subject, $country, $level);
        /** @var BaseExam $exam */
        $exam = $class::from_array($data);
        $exam->id = $examId;
        $exam->school_id = $schoolId;
        $exam->user_id = $userId;
        return $exam;
    }

    private static function normalizeOptional(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        return trim($value);
    }
}
