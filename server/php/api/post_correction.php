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

    $language = Request::getStringParam("language");
    $body = Request::getPostDataArray();
    if (is_array($body) && isset($body['language']) && is_string($body['language'])) {
        $language = $body['language'];
    }
    if ($language === null || $language === '') {
        $language = 'French';
    }

    $files = $exam->correctSubmission($fileId, $language);

    Request::add_output("id", $examId);
    Request::add_output("file", $fileId);
    Request::add_output("files", $files);
    Request::add_output("students", $exam->list_students());
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
