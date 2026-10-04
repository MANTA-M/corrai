<?php

declare(strict_types=1);

/**
 * Migration script: Rename assessment files of type "instructions" or referencing "consigne"
 * to "instructions.md" (or "Instructions_N.md") in S3.
 *
 * Usage:
 *   php server/php/cli/rename_consignes_to_instructions.php
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/inc/autoload.php';

use Corrai\Model\Assessment;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\Utils;

$envFile = $root . '/.env';
if (is_readable($envFile)) {
    $loaded = [];
    Utils::loadKeyValueFile($envFile, $loaded);
    foreach ($loaded as $key => $value) {
        if (getenv($key) === false) {
            putenv("$key=$value");
        }
        if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
            $_ENV[$key] = getenv($key) !== false ? (string) getenv($key) : $value;
        }
    }
}

foreach (['S3_ENDPOINT', 'S3_REGION', 'S3_BUCKET', 'S3_ACCESS_KEY', 'S3_SECRET_KEY'] as $key) {
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        $_ENV[$key] = $val;
    }
}

$store = ObjectStore::getInstance();
$updated = 0;

foreach ($store->listKeys() as $key) {
    if (!str_ends_with($key, '/' . ObjectStore::ATTR_FILE)) {
        continue;
    }

    $parsed = ObjectStore::parseNodePrefix(dirname($key) . '/');
    if ($parsed['kind'] !== 'file') {
        continue;
    }

    try {
        $loaded = $store->getJson($key);
        $data = $loaded['data'];
        $name = (string) ($data['name'] ?? '');
        $type = (string) ($data['type'] ?? '');

        if ($type !== 'instructions') {
            continue;
        }

        // Target extension must be .md and base must be instructions
        $assessmentId = $parsed['assessment_id'];
        $fileId = $parsed['file_id'];
        $assessment = Assessment::from_hash($assessmentId);

        $base = pathinfo($name, PATHINFO_FILENAME);
        // Normalize base
        if (stripos($base, 'consigne') !== false) {
            $base = preg_replace_callback(
                '/\bconsigne([s]?)\b/i',
                static fn($m) => ctype_upper($m[0][0]) ? 'Instructions' : 'instructions',
                $base
            );
        }

        $newName = $base . '.md';
        if ($newName === $name && ($data['content_type'] ?? '') === 'text/markdown') {
            continue;
        }

        // If duplicate in assessment, generate unique name
        $existing = [];
        foreach ($assessment->listFileModels() as $f) {
            if ($f->id !== $fileId) {
                $existing[strtolower($f->name)] = true;
            }
        }

        if (isset($existing[strtolower($newName)])) {
            $idx = 1;
            do {
                $candidate = "{$base}_{$idx}.md";
                $idx++;
            } while (isset($existing[strtolower($candidate)]));
            $newName = $candidate;
        }

        echo "Assessment {$assessmentId}, file {$fileId}: '{$name}' -> '{$newName}'\n";
        $file = $assessment->getFile($fileId);
        $file->name = $newName;
        $file->content_type = 'text/markdown';
        $file->saveAttributes();
        $updated++;
    } catch (\Throwable $e) {
        fwrite(STDERR, "Error processing {$key}: " . $e->getMessage() . "\n");
    }
}

echo "Finished. Renamed {$updated} file(s) to .md.\n";
