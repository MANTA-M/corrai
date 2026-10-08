<?php

use Corrai\Model\Assessment;
use Corrai\Utils\Http\Request;
use Corrai\Model\StateLocales;
use Corrai\Utils\Http\WSException;
use Corrai\Utils\MenuLabels;

try {
    $hash = Request::getStringParam("hash");
    if (!$hash) {
        Request::add_error_message("error", "No hash parameter");
        Request::output_all();
        exit();
    }

    $assessment = Assessment::from_hash($hash);
    $request_user = Request::get_mandatory_author();

    if ($assessment->user_id !== $request_user) {
        throw new WSException("Not authorized", 403);
    }

    $locale = Request::getStringParam('locale');
    $output = $assessment->to_output($locale);
    $output['menu'] = $assessment->get_menu(MenuLabels::locale($locale));
    Request::add_output("assessment", $output);
    Request::add_output("files", $assessment->list_files($locale));
    Request::add_output("students", $assessment->list_students($locale));
    StateLocales::addToOutput($assessment, $locale);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
