<?php

use Corrai\Subject\Catalog;
use Corrai\Utils\Request;

try {
    $locale = Request::getStringParam('locale');
    if ($locale === null || trim($locale) === '') {
        $locale = 'fr';
    }
    Request::add_output('subjects', Catalog::tree(trim($locale)));
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
