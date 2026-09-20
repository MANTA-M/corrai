<?php

use Corrai\Exam;
use Corrai\Request;
use Corrai\WSException;

include_once(__DIR__ . '/../inc/common.php');

try {
    $hash = Request::getStringParam("hash");
    if (!$hash) {
        Request::add_error_message("error", "No hash parameter");
        Request::output_all();
        exit();
    }

    try {
        $exam = Exam::from_hash($hash);
    } catch (\Exception $e) {
        Request::add_error_message("error", "Exam with hash $hash does not exist");
        Request::output_all();
        exit();
    }

    $request_user = Request::get_mandatory_author();

    if ($exam->user_id !== $request_user) {
        throw new WSException("Cannot delete exam: request author is not the exam author", 403);
    }

    $exam->delete();

    Request::add_output("hash", $hash);
    Request::add_output("message", "Exam deleted successfully");
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
