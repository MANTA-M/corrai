<?php

use Corrai\Model\Assessment;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\Request;
use Corrai\Utils\Utils;

try {
    $assessmentId = Request::getStringParam("assessment");
    if (!$assessmentId) {
        http_response_code(400);
        exit('No assessment parameter provided.');
    }

    $fileId = Request::getStringParam("file");
    if (!$fileId) {
        http_response_code(400);
        exit('No file parameter provided.');
    }

    try {
        $assessment = Assessment::from_hash($assessmentId);
    } catch (\Exception $e) {
        http_response_code(404);
        exit("Assessment with id $assessmentId does not exist.");
    }

    try {
        $file = $assessment->getFile($fileId);
    } catch (\Exception $e) {
        http_response_code(404);
        exit("File '$fileId' does not exist for assessment $assessmentId.");
    }

    $store = ObjectStore::getInstance();
    $annex = Request::getStringParam('annex');
    $event = Request::getStringParam('event');
    $object = Request::getStringParam('object');
    $downloadName = $file->name;

    $selectors = array_filter([
        ($annex !== null && $annex !== '') ? 'annex' : null,
        ($event !== null && $event !== '') ? 'event' : null,
        ($object !== null && $object !== '') ? 'object' : null,
    ]);
    if (count($selectors) > 1) {
        http_response_code(400);
        exit('Provide only one of annex, event or object.');
    }

    if ($object !== null && $object !== '') {
        if (!is_safe_file_object_path($object)) {
            http_response_code(400);
            exit('Invalid object path.');
        }
        $key = $file->prefix() . $object;
        if (!str_starts_with($key, $file->prefix())) {
            http_response_code(400);
            exit('Invalid object path.');
        }
        $downloadName = basename($object);
    } elseif ($annex !== null && $annex !== '') {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $annex)
            || $annex === ObjectStore::ATTR_FILE
            || $annex === ObjectStore::CONTENT_FILE
        ) {
            http_response_code(400);
            exit('Invalid annex name.');
        }
        $key = $file->prefix() . $annex;
        $downloadName = $annex;
    } elseif ($event !== null && $event !== '') {
        if (!preg_match('/^[0-9]+-[a-f0-9]+$/', $event)) {
            http_response_code(400);
            exit('Invalid event id.');
        }
        $key = $file->prefix() . 'events/' . $event . '.json';
        $downloadName = $event . '.json';
    } else {
        $key = $file->contentKey();
    }

    if (!$store->exists($key)) {
        http_response_code(404);
        exit("File '$fileId' does not exist for assessment $assessmentId.");
    }

    $object = $store->get($key);
    $mimeType = Utils::mimeTypeForFilename(
        $downloadName,
        $object['ContentType'] ?? $file->content_type
    );
    $contentLength = $object['ContentLength'];

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

    $body = $object['Body'];
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

/**
 * Relative key under a file directory: no traversal, one or more safe segments.
 */
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
