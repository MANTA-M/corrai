<?php

use Corrai\Model\Assessment;
use Corrai\Model\StudentsBulkAction;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Http\WSException;

try {
    $body = Request::getPostDataArray() ?? [];
    $assessmentId = Request::getStringParam('id') ?? ($body['id'] ?? null);
    $action = Request::getStringParam('action') ?? ($body['action'] ?? null);
    $model = Request::getStringParam('model') ?? ($body['model'] ?? null);

    $studentsParam = Request::getArrayParam('students');
    if ($studentsParam === [] && isset($body['students']) && is_array($body['students'])) {
        $studentsParam = $body['students'];
    }

    if (!$assessmentId) {
        Request::add_error_message('error', 'No id parameter provided');
        Request::output_all();
        exit();
    }

    if (!$action) {
        Request::add_error_message('error', 'No action parameter provided');
        Request::output_all();
        exit();
    }

    try {
        $assessment = Assessment::from_hash($assessmentId);
    } catch (\Exception $e) {
        Request::add_error_message('error', "Assessment with id $assessmentId does not exist");
        Request::output_all();
        exit();
    }

    $request_user = Request::get_mandatory_author();
    if ($assessment->user_id !== $request_user) {
        throw new WSException('Not authorized', 403);
    }

    /** @var list<string> $studentIds */
    $studentIds = [];
    foreach ($studentsParam as $studentId) {
        if (is_string($studentId) || is_int($studentId)) {
            $studentIds[] = (string) $studentId;
        }
    }

    StudentsBulkAction::run(
        $assessment,
        $studentIds,
        (string) $action,
        is_string($model) ? $model : null
    );

    $assessment = Assessment::from_hash($assessmentId);
    $locale = Request::getStringParam('locale') ?? ($body['locale'] ?? null);
    Request::add_output('id', $assessmentId);
    Request::add_output('students', $assessment->list_students($locale));
    Request::add_output('files', $assessment->list_files($locale));
    Request::add_output('assessed_students_number', $assessment->assessed_students_number);
    Request::add_output('mark_average', $assessment->mark_average);
    Request::add_output('mark_min', $assessment->mark_min);
    Request::add_output('mark_max', $assessment->mark_max);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
