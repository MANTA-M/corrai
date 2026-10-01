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

    $studentId = Request::getStringParam("student");
    if (!$studentId) {
        Request::add_error_message("error", "No student parameter provided");
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

    $student = $exam->getStudent($studentId);

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

    Request::add_output("student", $student->to_output());
    Request::add_output("students", $exam->list_students());
    Request::add_output("files", $exam->list_files());
    Request::add_output("id", $examId);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
