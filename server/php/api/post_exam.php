<?php

use Corrai\Exam;
use Corrai\HashId;
use Corrai\Request;
use Corrai\User;
use Corrai\JsonUtils;
use Corrai\WSException;

try {
    $post_data = Request::getPostStr();

    if ($post_data === null) {
        Request::add_error_message("error", "No body in POST request");
        Request::output_all();
        exit();
    }

    $exam_data = JsonUtils::decodeStrict($post_data);
    if ($exam_data === null) {
        Request::add_error_message("error", "Invalid JSON in POST request body");
        Request::output_all();
        exit();
    }

    $userId = Request::get_mandatory_author();
    try {
        $user = User::from_hash($userId);
    } catch (\Exception $e) {
        throw new WSException("User with hash $userId does not exist", 401);
    }

    $exam = Exam::from_array($exam_data);
    $exam->school_id = $user->school_id;
    $exam->user_id = $user->id;
    $exam->validate();

    $hash = HashId::create();
    $exam->id = $hash;
    $exam->save();

    Request::add_output("hash", $hash);
    if (!headers_sent()) {
        http_response_code(201);
    }
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
