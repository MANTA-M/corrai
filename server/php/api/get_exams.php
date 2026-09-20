<?php

use Corrai\Exam;
use Corrai\Request;

try {
    $authorId = Request::get_mandatory_author();
    $exams = Exam::list_for_author($authorId);
    Request::add_output("exams", $exams);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
