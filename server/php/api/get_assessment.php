<?php

use Corrai\Model\Assessment;
use Corrai\Utils\Request;
use Corrai\Utils\WSException;

try {
    $hash = Request::getStringParam("hash");
    if (!$hash) {
        Request::add_error_message("error", "No hash parameter");
        Request::output_all();
        exit();
    }

    $assessment = Assessment::from_hash($hash);
    $request_user = Request::get_mandatory_author();

    if ($assessment->user_id !== $request_user) {
        throw new WSException("Not authorized", 403);
    }

    Request::add_output("assessment", $assessment->to_output());
    Request::add_output("files", $assessment->list_files());
    Request::add_output("students", $assessment->list_students());
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
