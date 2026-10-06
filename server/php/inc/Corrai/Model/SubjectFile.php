<?php

namespace Corrai\Model;

/**
 * Subject material given to students. No correction pipeline.
 */
class SubjectFile extends InputFile
{
    public function on_stored(): void
    {
    }
}
