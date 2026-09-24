<?php

use Corrai\Model\Exam;
use Corrai\Utils\JsonUtils;
use Corrai\Utils\Request;
use Corrai\Utils\WSException;

try {
    $examId = Request::getStringParam("id");
    if (!$examId) {
        Request::add_error_message("error", "No id parameter provided");
        Request::output_all();
        exit();
    }

    $fileName = Request::getStringParam("filename");
    if (!$fileName) {
        Request::add_error_message("error", "No filename parameter provided");
        Request::output_all();
        exit();
    }

    if (preg_match('/[\/\\\\]/', $fileName)) {
        Request::add_error_message("error", "Invalid file name");
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

    $request_user = Request::get_mandatory_author();
    if ($exam->user_id !== $request_user) {
        throw new WSException("Not authorized", 403);
    }

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

    $type = null;
    if (array_key_exists('type', $body)) {
        if (!is_string($body['type'])) {
            throw new WSException('type must be a string', 400);
        }
        $type = $body['type'];
    }

    $student = null;
    if (array_key_exists('student', $body)) {
        if (!is_string($body['student'])) {
            throw new WSException('student must be a string', 400);
        }
        $student = $body['student'];
    }

    $newName = null;
    if (array_key_exists('name', $body)) {
        if (!is_string($body['name'])) {
            throw new WSException('name must be a string', 400);
        }
        $newName = $body['name'];
    }

    $content = null;
    if (array_key_exists('content', $body)) {
        if (!is_string($body['content'])) {
            throw new WSException('content must be a string', 400);
        }
        $content = $body['content'];
    }

    if ($type === null && $student === null && $newName === null && $content === null) {
        throw new WSException('No type, student, name or content provided', 400);
    }

    $currentName = $fileName;
    $files = null;

    if ($content !== null) {
        $files = $exam->writeFileContents($currentName, $content);
    }

    if ($newName !== null) {
        $files = $exam->renameFile($currentName, $newName);
        $currentName = trim($newName);
    }

    if ($type !== null || $student !== null) {
        $files = $exam->setFileTags($currentName, $type, $student);
    }

    Request::add_output("filename", $currentName);
    Request::add_output("id", $examId);
    Request::add_output("files", $files);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
