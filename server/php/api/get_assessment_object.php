<?php

use Corrai\Model\Assessment;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Utils;

try {
    $hash = Request::getStringParam("hash");
    if (!$hash) {
        http_response_code(400);
        exit('No hash parameter provided.');
    }

    try {
        $assessment = Assessment::from_hash($hash);
    } catch (\Exception $e) {
        http_response_code(404);
        exit("Assessment with id $hash does not exist.");
    }

    if ($assessment->id === null || $assessment->id === '') {
        http_response_code(404);
        exit('Assessment does not exist.');
    }

    $object = Request::getStringParam('object');
    $event = Request::getStringParam('event');
    $selectors = array_filter([
        ($object !== null && $object !== '') ? 'object' : null,
        ($event !== null && $event !== '') ? 'event' : null,
    ]);
    if (count($selectors) !== 1) {
        http_response_code(400);
        exit('Provide object or event.');
    }

    $prefix = ObjectStore::assessmentPrefix($assessment->school_id, $assessment->user_id, $assessment->id);
    if ($object !== null && $object !== '') {
        if (!preg_match('/^(?:(?:subject|students\/[A-Za-z0-9_-]+)\/)?[A-Za-z0-9][A-Za-z0-9_.-]*$/', $object)) {
            http_response_code(400);
            exit('Invalid object path.');
        }
        $key = $prefix . $object;
        $downloadName = $object;
    } else {
        if (!preg_match('/^[0-9]+-[a-f0-9]+$/', (string) $event)) {
            http_response_code(400);
            exit('Invalid event id.');
        }
        $key = ObjectStore::assessmentEventKey(
            $assessment->school_id,
            $assessment->user_id,
            $assessment->id,
            (string) $event
        );
        $downloadName = $event . '.json';
    }

    if (!str_starts_with($key, $prefix)) {
        http_response_code(400);
        exit('Invalid object path.');
    }

    $store = ObjectStore::getInstance();
    if (!$store->exists($key)) {
        http_response_code(404);
        exit('Object does not exist.');
    }

    $stored = $store->get($key);
    $mimeType = Utils::mimeTypeForFilename($downloadName, $stored['ContentType'] ?? 'application/octet-stream');
    $contentLength = $stored['ContentLength'];

    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="' . str_replace('"', '\\"', basename($downloadName)) . '"');
    header('X-Content-Type-Options: nosniff');
    if ($contentLength > 0) {
        header('Content-Length: ' . $contentLength);
    }
    header('Cache-Control: private, max-age=60');

    $body = $stored['Body'];
    if (is_string($body)) {
        echo $body;
    } else {
        echo (string) $body;
    }
    exit;
} catch (\Throwable $th) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    exit('Internal server error.');
}
