<?php

use Corrai\Model\User;
use Corrai\Utils\Request;

try {
    $authorId = Request::get_mandatory_author();
    $user = User::from_hash($authorId);
    $user->delete();

    Request::add_output('message', 'User deleted successfully');
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
