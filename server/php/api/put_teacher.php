<?php

use Corrai\Model\User;
use Corrai\Utils\Http\JsonUtils;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Http\WSException;

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
        $teacher = User::from_hash($hash);
    } catch (\Exception $e) {
        throw new WSException('Teacher does not exist', 404);
    }

    $name = $body['name'] ?? '';
    if (!is_string($name)) {
        throw new WSException('Teacher name is required', 400);
    }

    $teacher->name = trim($name);
    if (array_key_exists('discount_rate', $body)) {
        $teacher->discount_rate = User::normalizeDiscountRate($body['discount_rate'], true);
    }
    $teacher->save();

    Request::add_output('teacher', $teacher->to_output());
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
