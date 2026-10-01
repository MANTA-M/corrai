<?php

use Corrai\Model\Exam;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\Request;
use Corrai\Utils\Utils;

try {
    $examId = Request::getStringParam("id");
    if (!$examId) {
        http_response_code(400);
        exit('No id parameter provided.');
    }

    $fileId = Request::getStringParam("file");
    if (!$fileId) {
        http_response_code(400);
        exit('No file parameter provided.');
    }

    try {
        $exam = Exam::from_hash($examId);
    } catch (\Exception $e) {
        http_response_code(404);
        exit("Exam with id $examId does not exist.");
    }

    try {
        $file = $exam->getFile($fileId);
    } catch (\Exception $e) {
        http_response_code(404);
        exit("File '$fileId' does not exist for exam $examId.");
    }

    $store = ObjectStore::getInstance();
    $annex = Request::getStringParam('annex');
    $event = Request::getStringParam('event');
    $downloadName = $file->name;

    if (($annex !== null && $annex !== '') && ($event !== null && $event !== '')) {
        http_response_code(400);
        exit('Provide either annex or event, not both.');
    }

    if ($annex !== null && $annex !== '') {
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
        exit("File '$fileId' does not exist for exam $examId.");
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
