<?php

use Corrai\Utils\JsonUtils;
use Corrai\Utils\Request;
use Corrai\Utils\WSException;
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

    if (array_key_exists('name', $body)) {
        if (!is_string($body['name']) || trim($body['name']) === '') {
            throw new WSException('User name is required', 400);
        }
        $user->name = trim($body['name']);
    }

    if (array_key_exists('email', $body)) {
        if (!is_string($body['email'])) {
            throw new WSException('User email is invalid', 400);
        }
        $email = strtolower(trim($body['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new WSException('User email is invalid', 400);
        }
        if (User::emailInUse($email, $user->id)) {
            throw new WSException('User email is already in use', 409);
        }
        $user->email = $email;
    }

    if (array_key_exists('password', $body)) {
        if (!is_string($body['password'])) {
            throw new WSException('Password cannot be empty', 400);
        }
        $user->setPassword($body['password']);
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
