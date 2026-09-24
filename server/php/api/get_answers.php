<?php

use Corrai\Model\Exam;
use Corrai\Utils\Request;

try {
    // Get hash parameter
    $hash = Request::getStringParam("hash");
    if (!$hash) {
        Request::add_error_message("error", "No hash parameter");
        Request::output_all();
        exit();
    }

    $exam = Exam::from_hash($hash);
    $answers = $exam->verify();

    Request::add_output("answers", $answers);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
