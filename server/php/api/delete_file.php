<?php

use Corrai\Model\Assessment;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Http\WSException;

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

    $object = Request::getStringParam('object');
    if ($object !== null && $object !== '') {
        $request_user = Request::get_mandatory_author();
        if ($assessment->user_id !== $request_user) {
            throw new WSException("Not authorized", 403);
        }

        try {
            $file = $assessment->getFile($fileId);
        } catch (\Exception $e) {
            Request::add_error_message("error", "File '$fileId' does not exist for assessment $assessmentId");
            Request::output_all();
            exit();
        }

        if (!is_safe_file_object_path($object) || $object === ObjectStore::CONTENT_FILE) {
            throw new WSException('Invalid object path.', 400);
        }

        $key = $file->prefix() . $object;
        if (!str_starts_with($key, $file->prefix())) {
            throw new WSException('Invalid object path.', 400);
        }

        $store = ObjectStore::getInstance();
        if (!$store->exists($key)) {
            throw new WSException('Object does not exist.', 404);
        }

        $store->delete($key);

        Request::add_output("file", $fileId);
        Request::add_output("id", $assessmentId);
        Request::add_output("object", $object);
        Request::add_output("objects", $file->listDirectoryObjects());
        Request::add_output("message", "Object deleted successfully");
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

/**
 * Relative key under a file directory: no traversal, one or more safe segments.
 */
if (!function_exists('is_safe_file_object_path')) {
    function is_safe_file_object_path(string $object): bool
    {
        if ($object === '' || str_contains($object, '\\') || str_contains($object, "\0")) {
            return false;
        }
        if (str_starts_with($object, '/')) {
            return false;
        }
        foreach (explode('/', $object) as $segment) {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $segment)) {
                return false;
            }
        }
        return true;
    }
}
