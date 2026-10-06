<?php

use Corrai\Model\Assessment;
use Corrai\Utils\Http\JsonUtils;
use Corrai\Utils\Http\Request;
use Corrai\Model\StateLocales;
use Corrai\Utils\Http\WSException;

try {
    $assessmentId = Request::getStringParam("id");
    if (!$assessmentId) {
        Request::add_error_message("error", "No id parameter provided");
        Request::output_all();
        exit();
    }

    $studentId = Request::getStringParam("student");
    if (!$studentId) {
        Request::add_error_message("error", "No student parameter provided");
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

    $put_data = file_get_contents('php://input');
    if ($put_data === false) {
        Request::add_error_message("error", "No body in PUT request");
        Request::output_all();
        exit();
    }

    $body = JsonUtils::decodeStrict($put_data);
    if ($body === null || !is_array($body)) {
        Request::add_error_message("error", "Invalid JSON in PUT request body");
        Request::output_all();
        exit();
    }

    $changed = false;
    if (array_key_exists('name', $body)) {
        if (!is_string($body['name']) || trim($body['name']) === '') {
            throw new WSException('name must be a non-empty string', 400);
        }
        $student->name = trim($body['name']);
        $changed = true;
    }
    if (array_key_exists('status', $body)) {
        if (!is_string($body['status'])) {
            throw new WSException('status must be a string', 400);
        }
        $student->status = $body['status'];
        $changed = true;
    }
    if (array_key_exists('mark', $body)) {
        if ($body['mark'] === null) {
            $student->mark = null;
        } elseif (is_numeric($body['mark'])) {
            $student->mark = (float) $body['mark'];
        } else {
            throw new WSException('mark must be a number or null', 400);
        }
        $changed = true;
    }

    if (!$changed) {
        throw new WSException('No name, status or mark provided', 400);
    }

    $student->save();
    $assessment = Assessment::from_hash($assessmentId);

    $locale = Request::getStringParam('locale');
    Request::add_output("student", $student->to_output($locale));
    Request::add_output("students", $assessment->list_students($locale));
    Request::add_output("files", $assessment->list_files($locale));
    StateLocales::addToOutput($assessment, $locale);
    Request::add_output("assessed_students_number", $assessment->assessed_students_number);
    Request::add_output("mark_average", $assessment->mark_average);
    Request::add_output("mark_min", $assessment->mark_min);
    Request::add_output("mark_max", $assessment->mark_max);
    Request::add_output("id", $assessmentId);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
