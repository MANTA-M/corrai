<?php

use Corrai\Model\Exam;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\Request;

try {
    // Get exam ID parameter
    $examId = Request::getStringParam("id");
    if (!$examId) {
        http_response_code(400);
        exit('No id parameter provided.');
    }

    // Get file name parameter
    $fileName = Request::getStringParam("filename");
    if (!$fileName) {
        http_response_code(400);
        exit('No filename parameter provided.');
    }

    // Validate file name (basic security check - prevent directory traversal)
    if (preg_match('/[\/\\\\]/', $fileName)) {
        http_response_code(400);
        exit('Invalid file name.');
    }

    // Check if exam exists
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
    $mimeType = $object['ContentType'] ?: 'application/octet-stream';
    $contentLength = $object['ContentLength'];

    // Clean output buffer if any
    if (ob_get_level()) {
        ob_end_clean();
    }

    // HTTP headers
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . basename($fileName) . '"');
    header('Content-Transfer-Encoding: binary');
    if ($contentLength > 0) {
        header('Content-Length: ' . $contentLength);
    }
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: public');

    // Stream object body
    $body = $object['Body'];
    if (is_string($body)) {
        echo $body;
    } else {
        // Guzzle stream / PSR-7 stream
        echo (string) $body;
    }
    exit;
} catch (\Throwable $th) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    exit('Internal server error.');
}
