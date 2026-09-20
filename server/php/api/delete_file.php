<?php

use Corrai\Exam;
use Corrai\ObjectStore;
use Corrai\Request;

include_once(__DIR__ . '/../inc/common.php');

try {
    // Get exam ID parameter
    $examId = Request::getStringParam("id");
    if (!$examId) {
        Request::add_error_message("error", "No id parameter provided");
        Request::output_all();
        exit();
    }

    // Get file name parameter
    $fileName = Request::getStringParam("filename");
    if (!$fileName) {
        Request::add_error_message("error", "No filename parameter provided");
        Request::output_all();
        exit();
    }

    // Validate file name (basic security check - prevent directory traversal)
    if (preg_match('/[\/\\\\]/', $fileName)) {
        Request::add_error_message("error", "Invalid file name");
        Request::output_all();
        exit();
    }

    // Check if exam exists
    try {
        $exam = Exam::from_hash($examId);
    } catch (\Exception $e) {
        Request::add_error_message("error", "Exam with id $examId does not exist");
        Request::output_all();
        exit();
    }

    $store = ObjectStore::getInstance();
    $key = $exam->unassignedFileKey($fileName);

    if (!$store->exists($key)) {
        Request::add_error_message("error", "File '$fileName' does not exist for exam $examId");
        Request::output_all();
        exit();
    }

    try {
        $store->delete($key);
    } catch (\Throwable $e) {
        Request::add_error_message("error", "Error deleting file '$fileName'");
        Request::output_all();
        exit();
    }

    // Get updated file list
    $files = $exam->list_files();

    Request::add_output("filename", $fileName);
    Request::add_output("id", $examId);
    Request::add_output("message", "File deleted successfully");
    Request::add_output("files", $files);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
