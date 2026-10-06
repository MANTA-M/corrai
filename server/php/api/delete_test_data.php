<?php

use Corrai\Utils\Http\Request;
use Corrai\Utils\TestData;

try {
    $deleted = TestData::deleteAll();

    Request::add_output('schools', $deleted['schools']);
    Request::add_output('users', $deleted['users']);
    Request::add_output('assessments', $deleted['assessments']);
    Request::add_output('students', $deleted['students']);
    Request::add_output('errors', $deleted['errors']);
    Request::add_output('message', 'Test data deleted');
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
