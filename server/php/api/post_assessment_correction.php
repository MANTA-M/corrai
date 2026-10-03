<?php

use Corrai\Model\Assessment;
use Corrai\Utils\Request;
use Corrai\Utils\WSException;

try {
    $assessmentId = Request::getStringParam("id");
    if (!$assessmentId) {
        Request::add_error_message("error", "No id parameter provided");
        Request::output_all();
        exit();
    }

    try {
        $assessment = Assessment::from_hash($assessmentId);
    } catch (\Exception $e) {
        Request::add_error_message("error", "Assessment with id $assessmentId does not exist");
        Request::output_all();
        exit();
    }

    $request_user = Request::get_mandatory_author();
    if ($assessment->user_id !== $request_user) {
        throw new WSException("Not authorized", 403);
    }

    $language = Request::getStringParam("language");
    $body = Request::getPostDataArray();
    if (is_array($body) && isset($body['language']) && is_string($body['language'])) {
        $language = $body['language'];
    }
    if ($language === null || $language === '') {
        $language = 'French';
    }

    $locale = Request::getStringParam('locale');
    $files = $assessment->correctUnclassifiedFiles($language);

    Request::add_output("id", $assessmentId);
    Request::add_output("files", $files);
    Request::add_output("students", $assessment->list_students($locale));
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
