<?php

namespace Corrai\Model;

/**
 * User file stored as a directory and followed through processing.
 *
 * SubmissionFile, SubjectFile and InstructionFile are the three roles.
 * BaseFile is the same type: this class exists so callers can name the role.
 */
abstract class InputFile extends BaseFile
{
}
