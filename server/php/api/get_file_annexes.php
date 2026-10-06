<?php

use Corrai\Model\Assessment;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Http\WSException;

try {
    $assessmentId = Request::getStringParam("assessment");
    if (!$assessmentId) {
        Request::add_error_message("error", "No assessment parameter provided");
        Request::output_all();
        exit();
    }

    $fileId = Request::getStringParam("file");
    if (!$fileId) {
        Request::add_error_message("error", "No file parameter provided");
        Request::output_all();
        exit();
    }

    try {
        $assessment = Assessment::from_hash($assessmentId);
    } catch (\Exception $e) {
        Request::add_error_message("error", "Assessment with id $assessmentId does not exist");
        Request::output_all();
        exit();
    }

    $request_user = Request::get_mandatory_author();
    if ($assessment->user_id !== $request_user) {
        throw new WSException("Not authorized", 403);
    }

    $file = $assessment->getFile($fileId);
    Request::add_output("annexes", $file->listAnnexes());
    Request::add_output("events", $file->listEvents());
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
