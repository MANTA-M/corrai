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

    $fileName = Request::getStringParam("filename");
    if (!$fileName) {
        http_response_code(400);
        exit('No filename parameter provided.');
    }

    if (preg_match('/[\/\\\\]/', $fileName)) {
        http_response_code(400);
        exit('Invalid file name.');
    }

    try {
        $exam = Exam::from_hash($examId);
    } catch (\Exception $e) {
        http_response_code(404);
        exit("Exam with id $examId does not exist.");
    }

    $store = ObjectStore::getInstance();
    $key = $exam->unassignedFileKey($fileName);

    if (!$store->exists($key)) {
        http_response_code(404);
        exit("File '$fileName' does not exist for exam $examId.");
    }

    $object = $store->get($key);
    $mimeType = Utils::mimeTypeForFilename($fileName, $object['ContentType'] ?? null);
    $contentLength = $object['ContentLength'];

    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="' . str_replace('"', '\\"', basename($fileName)) . '"');
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
