<?php

namespace Corrai\Model;

/**
 * Default submission file when a subject has no pipeline of its own.
 */
class File extends SubmissionFile
{
    public function on_stored(): void
    {
    }

    public function on_correction_asked(): void
    {
    }
}
