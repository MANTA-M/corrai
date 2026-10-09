<?php

use Corrai\Model\Assessment;
use Corrai\Model\AttributeDocument;
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

    $document = AttributeDocument::read(
        $assessment,
        Request::getStringParam('student'),
        Request::getStringParam('file')
    );
    Request::add_output('attributes', $document['attributes']);
    Request::add_output('etag', $document['etag']);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
