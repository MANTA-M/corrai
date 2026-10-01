<?php

use Corrai\Utils\JsonUtils;
use Corrai\Utils\Request;
use Corrai\Model\User;

try {
    $authorId = Request::get_mandatory_author();
    $user = User::from_hash($authorId);

    $put_data = file_get_contents('php://input');
    if ($put_data === false || $put_data === '') {
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

    if (array_key_exists('country', $body)) {
        $user->country = User::normalizeCountry($body['country'], true);
    }

    $user->validate();
    $user->save();

    Request::add_output('user', $user->to_output());
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
