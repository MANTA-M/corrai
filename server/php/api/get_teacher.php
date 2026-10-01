<?php

use Corrai\Model\User;
use Corrai\Utils\Request;
use Corrai\Utils\WSException;

try {
    $hash = Request::getStringParam('hash');
    if ($hash === null || $hash === '') {
        Request::add_error_message('error', 'No hash parameter');
        Request::output_all();
        exit();
    }

    try {
        $teacher = User::from_hash($hash);
    } catch (\Exception $e) {
        throw new WSException('Teacher does not exist', 404);
    }

    Request::add_output('teacher', $teacher->to_output());
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
