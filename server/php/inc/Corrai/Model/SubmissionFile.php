<?php

namespace Corrai\Model;

/**
 * Student copy. Subject packages specialize this with their correction pipeline.
 * Default submission file when a subject has no pipeline of its own.
 */
class SubmissionFile extends InputFile
{
    public function on_stored(): void
    {
    }

    public function on_correction_asked(): void
    {
    }
}

