<?php

use Corrai\Model\Assessment;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Http\WSException;

try {
    $hash = Request::getStringParam("hash");
    if (!$hash) {
        Request::add_error_message("error", "No hash parameter provided");
        Request::output_all();
        exit();
    }

    $object = Request::getStringParam('object');
    if ($object === null || $object === '') {
        Request::add_error_message("error", "No object parameter provided");
        Request::output_all();
        exit();
    }

    try {
        $assessment = Assessment::from_hash($hash);
    } catch (\Exception $e) {
        Request::add_error_message("error", "Assessment with id $hash does not exist");
        Request::output_all();
        exit();
    }

    if ($assessment->id === null || $assessment->id === '') {
        Request::add_error_message("error", "Assessment does not exist");
        Request::output_all();
        exit();
    }

    $request_user = Request::get_mandatory_author();
    if ($assessment->user_id !== $request_user) {
        throw new WSException("Not authorized", 403);
    }

    if (!preg_match('/^(?:(?:subject|students\/[A-Za-z0-9_-]+)\/)?[A-Za-z0-9][A-Za-z0-9_.-]*$/', $object)) {
        throw new WSException('Invalid object path.', 400);
    }

    $prefix = ObjectStore::assessmentPrefix($assessment->school_id, $assessment->user_id, $assessment->id);
    $key = $prefix . $object;
    if (!str_starts_with($key, $prefix)) {
        throw new WSException('Invalid object path.', 400);
    }

    $store = ObjectStore::getInstance();
    if (!$store->exists($key)) {
        throw new WSException('Object does not exist.', 404);
    }

    $store->delete($key);

    Request::add_output("hash", $hash);
    Request::add_output("object", $object);
    Request::add_output("objects", $assessment->listRootObjects());
    Request::add_output("message", "Object deleted successfully");
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
