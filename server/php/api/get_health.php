<?php

use Corrai\Utils\Request;

try {
    Request::add_output("status", true);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
