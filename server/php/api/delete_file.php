<?php

use Corrai\Model\Assessment;
use Corrai\Utils\Http\Request;

try {
    $assessmentId = Request::getStringParam("assessment");
    if (!$assessmentId) {
        Request::add_error_message("error", "No assessment parameter provided");
        Request::output_all();
        exit();
    }

    $fileId = Request::getStringParam("file");
    if (!$fileId) {
        Request::add_error_message("error", "No file parameter provided");
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

    try {
        $files = $assessment->deleteFile($fileId);
    } catch (\Throwable $e) {
        Request::add_error_message("error", "Error deleting file '$fileId'");
        Request::output_all();
        exit();
    }

    Request::add_output("file", $fileId);
    Request::add_output("id", $assessmentId);
    Request::add_output("message", "File deleted successfully");
    Request::add_output("files", $files);
    Request::add_output("students", $assessment->list_students());
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
