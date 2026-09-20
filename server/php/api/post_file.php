<?php

use Corrai\Exam;
use Corrai\ObjectStore;
use Corrai\Request;

try {
    // Get exam ID parameter
    $examId = Request::getStringParam("id");
    if (!$examId) {
        Request::add_error_message("error", "No id parameter provided");
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

    // Check if file was uploaded
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = "No file uploaded";
        if (isset($_FILES['file']['error'])) {
            switch ($_FILES['file']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errorMsg = "File too large";
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errorMsg = "File upload was incomplete";
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errorMsg = "No file was uploaded";
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $errorMsg = "Missing temporary folder";
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $errorMsg = "Failed to write file to disk";
                    break;
                case UPLOAD_ERR_EXTENSION:
                    $errorMsg = "File upload stopped by extension";
                    break;
            }
        }
        Request::add_error_message("error", $errorMsg);
        Request::output_all();
        exit();
    }

    $uploadedFile = $_FILES['file'];
    $fileName = $uploadedFile['name'];
    $tmpPath = $uploadedFile['tmp_name'];

    // Validate file name (basic security check)
    if (empty($fileName) || preg_match('/[\/\\\\]/', $fileName)) {
        Request::add_error_message("error", "Invalid file name");
        Request::output_all();
        exit();
    }

    $contentType = $uploadedFile['type'] ?? null;
    $key = $exam->unassignedFileKey($fileName);

    try {
        ObjectStore::getInstance()->put($key, $tmpPath, $contentType);
    } catch (\Throwable $e) {
        Request::add_error_message("error", "Failed to save uploaded file");
        Request::output_all();
        exit();
    }

    // Get updated file list
    $files = $exam->list_files();

    Request::add_output("filename", $fileName);
    Request::add_output("path", $key);
    Request::add_output("files", $files);
    if (!headers_sent()) {
        http_response_code(201);
    }
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
