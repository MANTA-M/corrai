<?php

use Corrai\Model\File;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\Request;

/**
 * Resolve file metadata for a known file id. On failure, keep the id and an error status.
 *
 * @return array{file_id: string, name: string, type: string, status: string, content_key: string}
 */
function queues_file_fields(string $fileId): array
{
    try {
        $file = File::from_hash($fileId);
        return [
            'file_id' => (string) $file->id,
            'name' => $file->name,
            'type' => $file->type,
            'status' => $file->status,
            'content_key' => $file->contentKey(),
        ];
    } catch (\Throwable $th) {
        return [
            'file_id' => $fileId,
            'name' => '',
            'type' => '',
            'status' => 'error: ' . $th->getMessage(),
            'content_key' => '',
        ];
    }
}

/**
 * Decode an OCR ticket (JSON or bare path), matching the Python consumer.
 *
 * @return array{path: string, lang: string}
 */
function queues_parse_ocr_ticket(string $raw): array
{
    $text = trim($raw);
    if ($text === '') {
        throw new InvalidArgumentException('Empty OCR ticket');
    }

    $path = '';
    $lang = 'fr';

    if (str_starts_with($text, '{')) {
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Invalid OCR ticket JSON');
        }
        $ticketPath = $decoded['path'] ?? null;
        if (!is_string($ticketPath) || trim($ticketPath) === '') {
            throw new InvalidArgumentException('OCR ticket missing path');
        }
        $path = trim($ticketPath);
        $ticketLang = $decoded['lang'] ?? null;
        if (is_string($ticketLang) && trim($ticketLang) !== '') {
            $lang = trim($ticketLang);
        }
    } else {
        $path = $text;
    }

    $path = ltrim($path, '/');
    if ($path === '') {
        throw new InvalidArgumentException('OCR ticket path is empty');
    }

    return ['path' => $path, 'lang' => $lang];
}

/**
 * Derive file id from a content object key ending in /content.
 */
function queues_file_id_from_content_path(string $contentPath): string
{
    $path = trim($contentPath, '/');
    if (str_ends_with($path, '/' . ObjectStore::CONTENT_FILE)) {
        $path = substr($path, 0, -strlen('/' . ObjectStore::CONTENT_FILE));
    }
    $prefix = rtrim($path, '/') . '/';
    $parsed = ObjectStore::parseNodePrefix($prefix);
    if (($parsed['kind'] ?? '') !== 'file' || !isset($parsed['file_id'])) {
        throw new InvalidArgumentException('Content path does not point to a file: ' . $contentPath);
    }
    return $parsed['file_id'];
}

try {
    $queue = RedisQueue::getInstance();

    $phpItems = [];
    foreach ($queue->listTickets(RedisQueue::LIST_KEY) as $raw) {
        $decoded = json_decode($raw, true);
        $fileId = is_array($decoded) && isset($decoded['file_id']) && is_string($decoded['file_id'])
            ? $decoded['file_id']
            : '';
        if ($fileId === '' && is_array($decoded) && isset($decoded['path']) && is_string($decoded['path'])) {
            try {
                $fileId = queues_file_id_from_content_path($decoded['path']);
            } catch (\Throwable $th) {
                $fileId = '';
            }
        }
        if ($fileId === '') {
            $phpItems[] = [
                'file_id' => '',
                'name' => '',
                'type' => '',
                'status' => 'error: invalid ticket',
                'content_key' => '',
                'raw' => $raw,
            ];
            continue;
        }
        $phpItems[] = queues_file_fields($fileId);
    }

    $pythonItems = [];
    foreach ($queue->listTickets(RedisQueue::OCR_LIST_KEY) as $raw) {
        try {
            $ticket = queues_parse_ocr_ticket($raw);
            $fileId = queues_file_id_from_content_path($ticket['path']);
            $fields = queues_file_fields($fileId);
            $pythonItems[] = [
                'path' => $ticket['path'],
                'lang' => $ticket['lang'],
                'file_id' => $fields['file_id'],
                'name' => $fields['name'],
                'type' => $fields['type'],
                'status' => $fields['status'],
            ];
        } catch (\Throwable $th) {
            $pythonItems[] = [
                'path' => $raw,
                'lang' => '',
                'file_id' => '',
                'name' => '',
                'type' => '',
                'status' => 'error: ' . $th->getMessage(),
            ];
        }
    }

    Request::add_output('php', [
        'key' => RedisQueue::LIST_KEY,
        'items' => $phpItems,
    ]);
    Request::add_output('python', [
        'key' => RedisQueue::OCR_LIST_KEY,
        'items' => $pythonItems,
    ]);
} catch (\Throwable $th) {
    Request::handle_throwable($th);
}

Request::output_all();
