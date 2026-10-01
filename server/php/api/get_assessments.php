<?php

use Corrai\Model\Assessment;
use Corrai\Utils\Request;

try {
    $authorId = Request::get_mandatory_author();
    $assessments = Assessment::list_for_author($authorId);
    Request::add_output("assessments", $assessments);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
