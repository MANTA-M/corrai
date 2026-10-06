<?php

use Corrai\Model\School;
use Corrai\Model\User;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Http\WSException;

try {
    $hash = Request::getStringParam('hash');
    if ($hash === null || $hash === '') {
        Request::add_error_message('error', 'No hash parameter');
        Request::output_all();
        exit();
    }

    try {
        $school = School::from_hash($hash);
    } catch (\Exception $e) {
        throw new WSException('School does not exist', 404);
    }

    $teachers = array_map(
        static fn(User $user): array => $user->to_output(),
        $school->users()
    );

    Request::add_output('school', $school->to_output());
    Request::add_output('teachers', $teachers);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
