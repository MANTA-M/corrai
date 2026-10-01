<?php

use Corrai\Model\School;
use Corrai\Utils\JsonUtils;
use Corrai\Utils\Request;

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

    $name = $body['name'] ?? '';
    if (!is_string($name)) {
        Request::add_error_message('error', 'School name is required');
        Request::output_all();
        exit();
    }

    $school = new School();
    $school->name = trim($name);
    $school->save();

    Request::add_output('school', $school->to_output());
    if (!headers_sent()) {
        http_response_code(201);
    }
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
