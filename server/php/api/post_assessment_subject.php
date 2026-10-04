<?php

use Corrai\Llm\Openrouter\GeminiFlashLiteClient;
use Corrai\Model\User;
use Corrai\Subject\GeminiSubjectPageReader;
use Corrai\Subject\SubjectIntake;
use Corrai\Utils\Request;
use Corrai\Utils\WSException;

try {
    set_time_limit(180);

    $userId = Request::get_mandatory_author();
    try {
        $user = User::from_hash($userId);
    } catch (\Exception $e) {
        throw new WSException("User with hash $userId does not exist", 401);
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        Request::add_error_message('error', 'No file uploaded');
        Request::output_all();
        exit();
    }

    $uploadedFile = $_FILES['file'];
    $fileName = $uploadedFile['name'];
    $tmpPath = $uploadedFile['tmp_name'];
    if (!is_string($fileName) || $fileName === '' || preg_match('/[\/\\\\]/', $fileName)) {
        Request::add_error_message('error', 'Invalid file name');
        Request::output_all();
        exit();
    }

    $locale = Request::getStringParam('locale') ?? 'fr';
    if (!preg_match('/^[a-z]{2}$/', $locale)) {
        $locale = 'fr';
    }

    $assessment = SubjectIntake::create(
        $user,
        $tmpPath,
        $fileName,
        is_string($uploadedFile['type'] ?? null) ? $uploadedFile['type'] : null,
        $locale,
        new GeminiSubjectPageReader(new GeminiFlashLiteClient())
    );

    Request::add_output('hash', $assessment->id);
    Request::add_output('name', $assessment->name);
    Request::add_output('subject', $assessment->subject);
    Request::add_output('level', $assessment->level);
    Request::add_output('country', $assessment->country);
    Request::add_output('date', $assessment->date);
    if (!headers_sent()) {
        http_response_code(201);
    }
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
