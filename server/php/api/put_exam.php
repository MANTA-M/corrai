<?php

use Corrai\Model\Exam;
use Corrai\Utils\Request;
use Corrai\Utils\WSException;
use Corrai\Utils\JsonUtils;

try {
    $hash = Request::getStringParam("hash");
    if (!$hash) {
        Request::add_error_message("error", "No hash parameter");
        Request::output_all();
        exit();
    }

    $existing_exam = Exam::from_hash($hash);

    $request_user = Request::get_mandatory_author();

    if ($existing_exam->user_id !== $request_user) {
        throw new WSException("Not authorized", 403);
    }

    $put_data = file_get_contents('php://input');
    if ($put_data === false) {
        Request::add_error_message("error", "No body in PUT request");
        Request::output_all();
        exit();
    }

    $exam_data = JsonUtils::decodeStrict($put_data);
    if ($exam_data === null) {
        Request::add_error_message("error", "Invalid JSON in PUT request body");
        Request::output_all();
        exit();
    }

    // Only allow updating name, subject, and date
    $existing_exam->name = $exam_data['name'] ?? $existing_exam->name;
    $existing_exam->subject = $exam_data['subject'] ?? $existing_exam->subject;
    $existing_exam->date = $exam_data['date'] ?? $existing_exam->date;

    $existing_exam->validate();
    $existing_exam->save();

    Request::add_output("hash", $hash);
    Request::add_output("message", "Exam updated successfully");
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
