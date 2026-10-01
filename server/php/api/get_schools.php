<?php

use Corrai\Model\School;
use Corrai\Utils\Request;

try {
    $schools = array_map(
        static fn(School $school): array => $school->to_output(),
        School::all()
    );
    Request::add_output('schools', $schools);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
