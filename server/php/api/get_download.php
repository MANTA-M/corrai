<?php

use Corrai\Model\Assessment;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\Request;
use Corrai\Utils\Utils;

try {
    $assessmentId = Request::getStringParam("id");
    if (!$assessmentId) {
        http_response_code(400);
        exit('No id parameter provided.');
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
    $key = $file->contentKey();

    if (!$store->exists($key)) {
        http_response_code(404);
        exit("File '$fileId' does not exist for assessment $assessmentId.");
    }

    $object = $store->get($key);
    $mimeType = Utils::mimeTypeForFilename(
        $file->name,
        $object['ContentType'] ?? $file->content_type
    );
    $contentLength = $object['ContentLength'];

    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '\\"', basename($file->name)) . '"');
    header('Content-Transfer-Encoding: binary');
    if ($contentLength > 0) {
        header('Content-Length: ' . $contentLength);
    }
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: public');

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
