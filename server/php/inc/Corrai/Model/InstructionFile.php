<?php

namespace Corrai\Model;

/**
 * Legacy subject instructions stored as an InputFile directory.
 * New instructions are bare S3 blobs (type "instructions").
 */
class InstructionFile extends InputFile
{
    public function on_stored(): void
    {
    }
}
