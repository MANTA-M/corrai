<?php

use Corrai\Utils\Request;
use Corrai\Model\User;

try {
    $authorId = Request::get_mandatory_author();
    $user = User::from_hash($authorId);
    Request::add_output('user', $user->to_output());
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
