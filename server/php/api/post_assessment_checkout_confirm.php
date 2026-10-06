<?php

use Corrai\Model\Assessment;
use Corrai\Payment\CorrectionCheckout;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Http\WSException;

try {
    $assessmentId = Request::getStringParam("id");
    $sessionId = Request::getStringParam("session_id");
    if (!$assessmentId) {
        Request::add_error_message("error", "No id parameter provided");
        Request::output_all();
        exit();
    }
    if (!$sessionId) {
        throw new WSException("Payment required", 402);
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

    $locale = Request::getStringParam('locale');
    $files = CorrectionCheckout::fromEnv()->fulfill($assessment, $sessionId);

    Request::add_output("id", $assessmentId);
    Request::add_output("files", $files);
    Request::add_output("students", $assessment->list_students($locale));
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
