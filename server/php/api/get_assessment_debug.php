<?php

use Corrai\Model\Assessment;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Http\WSException;

try {
    $hash = Request::getStringParam("hash");
    if (!$hash) {
        Request::add_error_message("error", "No hash parameter");
        Request::output_all();
        exit();
    }

    try {
        $assessment = Assessment::from_hash($hash);
    } catch (\Exception $e) {
        Request::add_error_message("error", "Assessment with id $hash does not exist");
        Request::output_all();
        exit();
    }

    $request_user = Request::get_mandatory_author();
    if ($assessment->user_id !== $request_user) {
        throw new WSException("Not authorized", 403);
    }

    Request::add_output("objects", $assessment->listRootObjects());
    Request::add_output("events", $assessment->listEvents());
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
