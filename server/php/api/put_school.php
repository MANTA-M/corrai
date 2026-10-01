<?php

use Corrai\Model\School;
use Corrai\Utils\JsonUtils;
use Corrai\Utils\Request;
use Corrai\Utils\WSException;

try {
    $hash = Request::getStringParam('hash');
    if ($hash === null || $hash === '') {
        Request::add_error_message('error', 'No hash parameter');
        Request::output_all();
        exit();
    }

    $put_data = Request::getPostStr();
    if ($put_data === null || $put_data === '') {
        Request::add_error_message('error', 'No body in PUT request');
        Request::output_all();
        exit();
    }

    $body = JsonUtils::decodeStrict($put_data);
    if (!is_array($body)) {
        Request::add_error_message('error', 'Invalid JSON in PUT request body');
        Request::output_all();
        exit();
    }

    try {
        $school = School::from_hash($hash);
    } catch (\Exception $e) {
        throw new WSException('School does not exist', 404);
    }

    $name = $body['name'] ?? '';
    if (!is_string($name)) {
        throw new WSException('School name is required', 400);
    }

    $school->name = trim($name);
    $school->save();

    Request::add_output('school', $school->to_output());
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
