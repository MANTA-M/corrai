<?php

namespace Corrai\Subject;

use Corrai\Model\BaseAssessment;
use Corrai\Model\Assessment as GenericAssessment;

/**
 * Instantiates the most specific subject Assessment for stored attributes.
 */
class AssessmentFactory
{
    /**
     * Every subject Assessment class under Corrai\Subject.
     *
     * @return list<class-string<BaseAssessment>>
     */
    public static function classes(): array
    {
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'Assessment.php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(__DIR__) + 1, -strlen('/Assessment.php'));
            $classes[] = 'Corrai\\Subject\\' . str_replace('/', '\\', $relative) . '\\Assessment';
        }
        sort($classes);
        return $classes;
    }

    /**
     * Most specific Assessment class for a subject, country, and level.
     *
     * @return class-string<BaseAssessment>
     */
    public static function assessmentClass(string $subject, string $country, string $level): string
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
        return $countryOnly ?? $bare ?? GenericAssessment::class;
    }

    /**
     * Build an Assessment from S3 attribute payload plus path ids.
     */
    public static function fromAttributes(
        array $data,
        string $schoolId,
        string $userId,
        string $assessmentId
    ): BaseAssessment {
        $subject = (string) ($data['subject'] ?? '');
        $country = self::normalizeOptional($data['country'] ?? null);
        $level = self::normalizeOptional($data['level'] ?? null);
        $class = self::assessmentClass($subject, $country, $level);
        /** @var BaseAssessment $assessment */
        $assessment = $class::from_array($data);
        $assessment->id = $assessmentId;
        $assessment->school_id = $schoolId;
        $assessment->user_id = $userId;
        return $assessment;
    }

    private static function normalizeOptional(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        return trim($value);
    }
}
