<?php

use Corrai\Model\School;
use Corrai\Utils\Http\JsonUtils;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Http\WSException;

try {
    $post_data = Request::getPostStr();
    if ($post_data === null || $post_data === '') {
        Request::add_error_message('error', 'No body in POST request');
        Request::output_all();
        exit();
    }

    $body = JsonUtils::decodeStrict($post_data);
    if (!is_array($body)) {
        Request::add_error_message('error', 'Invalid JSON in POST request body');
        Request::output_all();
        exit();
    }

    $schoolId = $body['school_id'] ?? '';
    $name = $body['name'] ?? '';
    if (!is_string($schoolId) || trim($schoolId) === '') {
        throw new WSException('School id is required', 400);
    }
    if (!is_string($name)) {
        throw new WSException('Teacher name is required', 400);
    }

    try {
        $school = School::from_hash(trim($schoolId));
    } catch (\Exception $e) {
        throw new WSException('School does not exist', 404);
    }

    $teacher = $school->addTeacher($name);

    Request::add_output('teacher', $teacher->to_output());
    if (!headers_sent()) {
        http_response_code(201);
    }
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
