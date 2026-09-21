<?php

use Corrai\Exam;
use Corrai\JsonUtils;
use Corrai\Request;
use Corrai\WSException;

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

    $author = null;
    if (array_key_exists('author', $body)) {
        if (!is_string($body['author'])) {
            throw new WSException('author must be a string', 400);
        }
        $author = $body['author'];
    }

    $newName = null;
    if (array_key_exists('name', $body)) {
        if (!is_string($body['name'])) {
            throw new WSException('name must be a string', 400);
        }
        $newName = $body['name'];
    }

    if ($type === null && $author === null && $newName === null) {
        throw new WSException('No type, author or name provided', 400);
    }

    $currentName = $fileName;
    $files = null;

    if ($newName !== null) {
        $files = $exam->renameFile($currentName, $newName);
        $currentName = trim($newName);
    }

    if ($type !== null || $author !== null) {
        $files = $exam->setFileTags($currentName, $type, $author);
    }

    Request::add_output("filename", $currentName);
    Request::add_output("id", $examId);
    Request::add_output("files", $files);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
