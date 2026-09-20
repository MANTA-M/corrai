<?php

use Corrai\Request;

include_once(__DIR__ . '/../inc/common.php');

try {
    Request::add_output("status", true);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
