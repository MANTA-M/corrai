<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Task\Thumbnail;

class SubmissionTask3Thumbnail extends Thumbnail
{
}

if (!class_exists('LawFrance\SubmissionTask3Thumbnail', false)) {
    class_alias(SubmissionTask3Thumbnail::class, 'LawFrance\SubmissionTask3Thumbnail');
}

if (!class_exists('Corrai\Subject\LawFrance\SubmissionTask4Thumbnail', false)) {
    class_alias(SubmissionTask3Thumbnail::class, 'Corrai\Subject\LawFrance\SubmissionTask4Thumbnail');
}

if (!class_exists('LawFrance\SubmissionTask4Thumbnail', false)) {
    class_alias(SubmissionTask3Thumbnail::class, 'LawFrance\SubmissionTask4Thumbnail');
}
