<?php

use Corrai\Model\Assessment;
use Corrai\Subject\AssessmentFactory;
use Corrai\Utils\HashId;
use Corrai\Utils\Request;
use Corrai\Model\User;
use Corrai\Utils\JsonUtils;
use Corrai\Utils\WSException;

try {
    $post_data = Request::getPostStr();

    if ($post_data === null) {
        Request::add_error_message("error", "No body in POST request");
        Request::output_all();
        exit();
    }

    $assessment_data = JsonUtils::decodeStrict($post_data);
    if ($assessment_data === null) {
        Request::add_error_message("error", "Invalid JSON in POST request body");
        Request::output_all();
        exit();
    }

    $userId = Request::get_mandatory_author();
    try {
        $user = User::from_hash($userId);
    } catch (\Exception $e) {
        throw new WSException("User with hash $userId does not exist", 401);
    }

    $assessmentClass = AssessmentFactory::assessmentClass(
        (string) ($assessment_data['subject'] ?? ''),
        (string) ($assessment_data['country'] ?? ''),
        (string) ($assessment_data['level'] ?? '')
    );
    $assessment = $assessmentClass::from_array($assessment_data);
    if (!isset($assessment_data['correction_language']) || !is_string($assessment_data['correction_language']) || trim($assessment_data['correction_language']) === '') {
        $assessment->correction_language = Assessment::normalizeLocale(Request::getStringParam('locale') ?? 'fr');
    }
    $assessment->school_id = $user->school_id;
    $assessment->user_id = $user->id;
    $assessment->validate();

    $hash = HashId::create();
    $assessment->id = $hash;
    $assessment->save();

    Request::add_output("hash", $hash);
    if (!headers_sent()) {
        http_response_code(201);
    }
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
