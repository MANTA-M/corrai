<?php

use Corrai\Model\Assessment;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Http\WSException;
use Corrai\Utils\Store\ObjectStore;

try {
    $body = Request::getPostDataArray();
    $assessmentId = Request::getStringParam("id") ?? ($body['id'] ?? null);
    $studentId = Request::getStringParam("student") ?? ($body['student'] ?? null);

    if (!$studentId) {
        Request::add_error_message("error", "No student parameter provided");
        Request::output_all();
        exit();
    }

    if (!$assessmentId) {
        $store = ObjectStore::getInstance();
        $prefix = $store->resolveIdPointer($studentId);
        $parsed = ObjectStore::parseNodePrefix($prefix);
        $assessmentId = $parsed['assessment_id'] ?? null;
    }

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

    $student = $assessment->getStudent($studentId);
    $student->correct();

    $assessment = Assessment::from_hash($assessmentId);
    $locale = Request::getStringParam('locale');
    Request::add_output("id", $assessmentId);
    Request::add_output("student", $student->to_output($locale));
    Request::add_output("students", $assessment->list_students($locale));
    Request::add_output("files", $assessment->list_files($locale));
    Request::add_output("assessed_students_number", $assessment->assessed_students_number);
    Request::add_output("mark_average", $assessment->mark_average);
    Request::add_output("mark_min", $assessment->mark_min);
    Request::add_output("mark_max", $assessment->mark_max);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
