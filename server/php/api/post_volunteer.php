<?php

use Corrai\Utils\Emailer;
use Corrai\Utils\JsonUtils;
use Corrai\Utils\Request;

try {
    $post_data = Request::getPostStr();
    $email = null;

    if ($post_data !== null && $post_data !== '') {
        $body = JsonUtils::decodeStrict($post_data);
        if ($body === null) {
            Request::add_error_message('error', 'Invalid JSON in POST request body');
            Request::output_all();
            exit();
        }
        if (is_array($body) && isset($body['email']) && is_string($body['email'])) {
            $email = trim($body['email']);
        }
    }

    if ($email === null || $email === '') {
        Request::add_error_message('error', 'Missing email');
        Request::output_all();
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        Request::add_error_message('error', 'Invalid email');
        Request::output_all();
        exit();
    }

    $adminEmail = $_ENV['ADMIN_EMAIL'] ?? '';
    if ($adminEmail === '') {
        Request::add_error_message('error', 'ADMIN_EMAIL is not configured');
        Request::output_all();
        exit();
    }

    $message = 'Volontaire Corrail: ' . $email;
    $emailer = new Emailer($adminEmail, $message, $message);
    $emailer->send();

    Request::add_output('ok', true);
    if (!headers_sent()) {
        http_response_code(200);
    }
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
