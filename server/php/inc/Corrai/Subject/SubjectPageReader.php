<?php

namespace Corrai\Subject;

/**
 * Reads name, subject, level, and date from the first page of a subject file.
 */
interface SubjectPageReader
{
    /**
     * @param array $tree Subject catalog tree for the teacher's locale.
     * @return array{name?: string, subject?: string, level?: string, country?: string, date?: string}
     */
    public function read(string $pagePath, string $pageName, array $tree): array;
}
