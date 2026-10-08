<?php

use Corrai\Model\Assessment;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Http\WSException;

try {
    $assessmentId = Request::getStringParam("id");
    if (!$assessmentId) {
        Request::add_error_message("error", "No id parameter provided");
        Request::output_all();
        exit();
    }

    $fileId = Request::getStringParam("file");
    $studentId = Request::getStringParam("student");
    if (!$fileId && !$studentId) {
        Request::add_error_message("error", "No file or student parameter provided");
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

    if ($studentId) {
        $student = $assessment->getStudent($studentId);
        $student->correct();
        Request::add_output("id", $assessmentId);
        Request::add_output("student", $studentId);
        Request::add_output("files", $assessment->list_files());
        Request::add_output("students", $assessment->list_students());
    } else {
        $files = $assessment->correctSubmission($fileId);
        Request::add_output("id", $assessmentId);
        Request::add_output("file", $fileId);
        Request::add_output("files", $files);
        Request::add_output("students", $assessment->list_students());
    }
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
