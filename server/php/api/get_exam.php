<?php

use Corrai\Exam;
use Corrai\Request;
use Corrai\WSException;

try {
    $hash = Request::getStringParam("hash");
    if (!$hash) {
        Request::add_error_message("error", "No hash parameter");
        Request::output_all();
        exit();
    }

    $exam = Exam::from_hash($hash);
    $request_user = Request::get_mandatory_author();

    if ($exam->user_id !== $request_user) {
        throw new WSException("Not authorized", 403);
    }

    Request::add_output("exam", $exam->to_output());
    Request::add_output("files", $exam->list_files());
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
