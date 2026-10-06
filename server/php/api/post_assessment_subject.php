<?php

use Corrai\Llm\Openrouter\GeminiFlashLiteClient;
use Corrai\Model\User;
use Corrai\Subject\GeminiSubjectPageReader;
use Corrai\Subject\GoogleSubjectImageOcr;
use Corrai\Subject\SubjectIntake;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Http\WSException;

try {
    set_time_limit(600);

    $userId = Request::get_mandatory_author();
    try {
        $user = User::from_hash($userId);
    } catch (\Exception $e) {
        throw new WSException("User with hash $userId does not exist", 401);
    }

    $batches = [];
    if (isset($_FILES['files']) && is_array($_FILES['files']['name'] ?? null)) {
        $batches[] = $_FILES['files'];
    }
    if (isset($_FILES['file']) && is_string($_FILES['file']['name'] ?? null)) {
        $batches[] = [
            'name' => [$_FILES['file']['name']],
            'type' => [$_FILES['file']['type'] ?? ''],
            'tmp_name' => [$_FILES['file']['tmp_name'] ?? ''],
            'error' => [$_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE],
        ];
    }

    $uploads = [];
    foreach ($batches as $batch) {
        foreach ($batch['name'] as $index => $name) {
            $error = $batch['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            if ($error !== UPLOAD_ERR_OK) {
                Request::add_error_message('error', 'No file uploaded');
                Request::output_all();
                exit();
            }
            if (!is_string($name) || $name === '' || preg_match('/[\/\\\\]/', $name)) {
                Request::add_error_message('error', 'Invalid file name');
                Request::output_all();
                exit();
            }
            $tmp = $batch['tmp_name'][$index] ?? '';
            if (!is_string($tmp) || $tmp === '') {
                Request::add_error_message('error', 'No file uploaded');
                Request::output_all();
                exit();
            }
            $type = $batch['type'][$index] ?? null;
            $uploads[] = [
                'path' => $tmp,
                'name' => $name,
                'contentType' => is_string($type) && $type !== '' ? $type : null,
            ];
        }
    }

    if ($uploads === []) {
        Request::add_error_message('error', 'No file uploaded');
        Request::output_all();
        exit();
    }

    $locale = Request::getStringParam('locale') ?? 'fr';
    if (!preg_match('/^[a-z]{2}$/', $locale)) {
        $locale = 'fr';
    }

    $assessment = SubjectIntake::create(
        $user,
        $uploads,
        $locale,
        new GeminiSubjectPageReader(new GeminiFlashLiteClient()),
        new GoogleSubjectImageOcr()
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
