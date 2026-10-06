<?php

namespace Corrai\Model;

/**
 * Subject instructions that are not given to students. No correction pipeline.
 */
class InstructionFile extends InputFile
{
    public function on_stored(): void
    {
    }
}
