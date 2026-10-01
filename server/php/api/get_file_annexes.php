<?php

use Corrai\Model\Exam;
use Corrai\Utils\Request;
use Corrai\Utils\WSException;

try {
    $examId = Request::getStringParam("id");
    if (!$examId) {
        Request::add_error_message("error", "No id parameter provided");
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
        $exam = Exam::from_hash($examId);
    } catch (\Exception $e) {
        Request::add_error_message("error", "Exam with id $examId does not exist");
        Request::output_all();
        exit();
    }

    $request_user = Request::get_mandatory_author();
    if ($exam->user_id !== $request_user) {
        throw new WSException("Not authorized", 403);
    }

    $file = $exam->getFile($fileId);
    Request::add_output("annexes", $file->listAnnexes());
    Request::add_output("events", $file->listEvents());
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
