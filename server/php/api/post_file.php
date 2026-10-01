<?php

use Corrai\Model\Exam;
use Corrai\Utils\Request;
use Corrai\Utils\WSException;

try {
    $examId = Request::getStringParam("id");
    if (!$examId) {
        Request::add_error_message("error", "No id parameter provided");
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

    if (empty($fileName) || preg_match('/[\/\\\\]/', $fileName)) {
        Request::add_error_message("error", "Invalid file name");
        Request::output_all();
        exit();
    }

    $type = Request::getStringParam("type");
    if ($type !== null && $type !== '' && $type !== 'unknown' && !in_array($type, Exam::FILE_TYPES, true)) {
        Request::add_error_message("error", "Invalid file type");
        Request::output_all();
        exit();
    }

    $student = Request::getStringParam("student");
    $contentType = $uploadedFile['type'] ?? null;

    try {
        $file = $exam->createFileFromPath(
            $fileName,
            $tmpPath,
            $contentType,
            $type,
            $student
        );
    } catch (\Throwable $e) {
        Request::add_error_message("error", "Failed to save uploaded file");
        Request::output_all();
        exit();
    }

    Request::add_output("file", $file->id);
    Request::add_output("filename", $file->name);
    Request::add_output("id", $examId);
    Request::add_output("files", $exam->list_files());
    Request::add_output("students", $exam->list_students());
    if (!headers_sent()) {
        http_response_code(201);
    }
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
