<?php

use Corrai\Utils\JsonUtils;
use Corrai\Utils\Request;
use Corrai\Model\School;

try {
    $post_data = Request::getPostStr();
    $name = null;

    if ($post_data !== null && $post_data !== '') {
        $body = JsonUtils::decodeStrict($post_data);
        if ($body === null) {
            Request::add_error_message('error', 'Invalid JSON in POST request body');
            Request::output_all();
            exit();
        }
        if (is_array($body) && isset($body['name']) && is_string($body['name'])) {
            $name = $body['name'];
        }
    }

    $user = School::addIndependentUser($name);

    Request::add_output('user', $user->to_output());
    if (!headers_sent()) {
        http_response_code(201);
    }
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
