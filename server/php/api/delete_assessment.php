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

    try {
        $assessment = Assessment::from_hash($hash);
    } catch (\Exception $e) {
        Request::add_error_message("error", "Assessment with hash $hash does not exist");
        Request::output_all();
        exit();
    }

    $request_user = Request::get_mandatory_author();

    if ($assessment->user_id !== $request_user) {
        throw new WSException("Cannot delete assessment: request author is not the assessment author", 403);
    }

    $assessment->delete();

    Request::add_output("hash", $hash);
    Request::add_output("message", "Assessment deleted successfully");
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
