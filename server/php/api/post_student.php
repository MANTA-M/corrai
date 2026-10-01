<?php

use Corrai\Model\Exam;
use Corrai\Utils\JsonUtils;
use Corrai\Utils\Request;
use Corrai\Utils\WSException;

try {
    $examId = Request::getStringParam("id");
    if (!$examId) {
        Request::add_error_message("error", "No id parameter provided");
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

    $put_data = file_get_contents('php://input');
    if ($put_data === false || $put_data === '') {
        Request::add_error_message("error", "No body in POST request");
        Request::output_all();
        exit();
    }

    $body = JsonUtils::decodeStrict($put_data);
    if ($body === null || !is_array($body)) {
        Request::add_error_message("error", "Invalid JSON in POST request body");
        Request::output_all();
        exit();
    }

    $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
    if ($name === '') {
        throw new WSException('Student name is required', 400);
    }

    $student = $exam->createStudent($name);

    if (!headers_sent()) {
        http_response_code(201);
    }
    Request::add_output("student", $student->to_output());
    Request::add_output("students", $exam->list_students());
    Request::add_output("files", $exam->list_files());
    Request::add_output("id", $examId);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
