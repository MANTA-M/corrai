<?php

use Corrai\Model\Assessment;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Http\WSException;
use Corrai\Utils\Http\JsonUtils;

try {
    $hash = Request::getStringParam("hash");
    if (!$hash) {
        Request::add_error_message("error", "No hash parameter");
        Request::output_all();
        exit();
    }

    $existing_assessment = Assessment::from_hash($hash);

    $request_user = Request::get_mandatory_author();

    if ($existing_assessment->user_id !== $request_user) {
        throw new WSException("Not authorized", 403);
    }

    $put_data = file_get_contents('php://input');
    if ($put_data === false) {
        Request::add_error_message("error", "No body in PUT request");
        Request::output_all();
        exit();
    }

    $assessment_data = JsonUtils::decodeStrict($put_data);
    if ($assessment_data === null) {
        Request::add_error_message("error", "Invalid JSON in PUT request body");
        Request::output_all();
        exit();
    }

    // Only allow updating name, subject, and date
    $existing_assessment->name = $assessment_data['name'] ?? $existing_assessment->name;
    $existing_assessment->subject = $assessment_data['subject'] ?? $existing_assessment->subject;
    if (array_key_exists('country', $assessment_data)) {
        $existing_assessment->country = Assessment::optionalAttribute($assessment_data['country']);
    }
    if (array_key_exists('level', $assessment_data)) {
        $existing_assessment->level = Assessment::optionalAttribute($assessment_data['level']);
    }
    $existing_assessment->date = $assessment_data['date'] ?? $existing_assessment->date;

    $existing_assessment->validate();
    $existing_assessment->save();

    Request::add_output("hash", $hash);
    Request::add_output("message", "Assessment updated successfully");
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
