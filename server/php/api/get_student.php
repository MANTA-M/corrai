<?php

use Corrai\Model\Assessment;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Http\WSException;

try {
    $assessmentId = Request::getStringParam('id');
    if (!$assessmentId) {
        Request::add_error_message('error', 'No id parameter provided');
        Request::output_all();
        exit();
    }

    $studentId = Request::getStringParam('student');
    if (!$studentId) {
        Request::add_error_message('error', 'No student parameter provided');
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

    $locale = Request::getStringParam('locale');
    $student = $assessment->getStudent($studentId);

    Request::add_output('assessment', $assessment->to_output($locale));
    Request::add_output('student', $student->to_output($locale));
    Request::add_output('students', $assessment->list_students($locale));
    Request::add_output('files', $assessment->list_student_files($studentId, $locale));
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
