<?php

namespace Corrai\Model;

use Corrai\Utils\Http\Request;
use Corrai\Utils\MenuLabels;

/**
 * Student, assessment and file state labels for the locale of the current request.
 */
class StateLocales
{
    /**
     * @return array{
     *     student_states: array<string, string>,
     *     assessment_states: array<string, string>,
     *     file_states: array<string, string>
     * }
     */
    public static function maps(BaseAssessment $assessment, ?string $locale = null): array
    {
        $locale = MenuLabels::locale($locale);
        return [
            'student_states' => BaseStudent::statusLabels($locale),
            'assessment_states' => BaseAssessment::statusLabels($locale),
            'file_states' => $assessment->fileStatusLabels($locale),
        ];
    }

    public static function addToOutput(BaseAssessment $assessment, ?string $locale = null): void
    {
        foreach (self::maps($assessment, $locale) as $key => $labels) {
            Request::add_output($key, $labels);
        }
    }
}
