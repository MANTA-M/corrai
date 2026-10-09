<?php

namespace Corrai\Model;

/**
 * Student copy. Subject packages specialize this with their correction pipeline.
 * Default submission file when a subject has no pipeline of its own.
 */
class SubmissionFile extends InputFile
{
    /**
     * Student hash this file belongs to. Null / empty when unassigned.
     */
    public ?string $student = null;

    /**
     * Identifier read on the copy (a number or a name). Null when none was found.
     */
    public ?string $student_identifier = null;

    /**
     * Calligraphy score evaluated on the copy. Null when none was evaluated.
     */
    public int|float|null $qualigraphy_score = null;

    /**
     * Subject material stays with the assessment. Copies can move between students.
     */
    public function canReassign(): bool
    {
        return true;
    }

    public function on_stored(): void
    {
    }

    public function on_correction_asked(): void
    {
    }
}

