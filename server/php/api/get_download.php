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
    $key = $file->contentKey();

    if (!$store->exists($key)) {
        http_response_code(404);
        exit("File '$fileId' does not exist for exam $examId.");
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
