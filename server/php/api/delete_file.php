<?php

use Corrai\Model\Exam;
use Corrai\Utils\Request;

try {
    $examId = Request::getStringParam("id");
    if (!$examId) {
        Request::add_error_message("error", "No id parameter provided");
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
        $exam = Exam::from_hash($examId);
    } catch (\Exception $e) {
        Request::add_error_message("error", "Exam with id $examId does not exist");
        Request::output_all();
        exit();
    }

    try {
        $files = $exam->deleteFile($fileId);
    } catch (\Throwable $e) {
        Request::add_error_message("error", "Error deleting file '$fileId'");
        Request::output_all();
        exit();
    }

    Request::add_output("file", $fileId);
    Request::add_output("id", $examId);
    Request::add_output("message", "File deleted successfully");
    Request::add_output("files", $files);
    Request::add_output("students", $exam->list_students());
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
