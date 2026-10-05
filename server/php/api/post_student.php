<?php

use Corrai\Model\Assessment;
use Corrai\Utils\JsonUtils;
use Corrai\Utils\Request;
use Corrai\Utils\WSException;

try {
    $assessmentId = Request::getStringParam("id");
    if (!$assessmentId) {
        Request::add_error_message("error", "No id parameter provided");
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

    $student = $assessment->createStudent($name);

    if (!headers_sent()) {
        http_response_code(201);
    }
    Request::add_output("student", $student->to_output());
    Request::add_output("students", $assessment->list_students());
    Request::add_output("files", $assessment->list_files());
    Request::add_output("assessed_students_number", $assessment->assessed_students_number);
    Request::add_output("mark_average", $assessment->mark_average);
    Request::add_output("mark_min", $assessment->mark_min);
    Request::add_output("mark_max", $assessment->mark_max);
    Request::add_output("id", $assessmentId);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
