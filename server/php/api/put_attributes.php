<?php

use Corrai\Model\Assessment;
use Corrai\Model\AttributeDocument;
use Corrai\Utils\Http\JsonUtils;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Http\WSException;

try {
    $assessmentId = Request::getStringParam('assessment');
    if (!$assessmentId) {
        throw new WSException('No assessment parameter provided', 400);
    }

    try {
        $assessment = Assessment::from_hash($assessmentId);
    } catch (\Exception $e) {
        throw new WSException("Assessment with id $assessmentId does not exist", 404);
    }

    $request_user = Request::get_mandatory_author();
    if ($assessment->user_id !== $request_user) {
        throw new WSException('Not authorized', 403);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false) {
        throw new WSException('No body in PUT request', 400);
    }
    $body = JsonUtils::decodeStrict($raw);
    if (!is_array($body) || !array_key_exists('attributes', $body) || !is_array($body['attributes'])) {
        throw new WSException('Attributes must be a JSON object', 400);
    }
    $etag = $body['etag'] ?? null;
    if ($etag !== null && !is_string($etag)) {
        throw new WSException('etag must be a string', 400);
    }

    $next = AttributeDocument::write(
        $assessment,
        Request::getStringParam('student'),
        Request::getStringParam('file'),
        $body['attributes'],
        $etag
    );
    Request::add_output('etag', $next);
    Request::add_output('message', 'Attributes updated');
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
