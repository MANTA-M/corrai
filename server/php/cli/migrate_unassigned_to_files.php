<?php

declare(strict_types=1);

/**
 * Move assessment binaries from {schoolId}/{userId}/{assessmentId}/unassigned/{filename}
 * to {schoolId}/{userId}/{assessmentId}/files/{filename}.
 *
 * Idempotent. A source whose destination already exists with the same size is
 * deleted. A size mismatch is left in place and reported.
 *
 * Usage (inside the php container, so S3_ENDPOINT=http://seaweedfs:8333 resolves):
 *   php /var/corrai/php/cli/migrate_unassigned_to_files.php
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/inc/autoload.php';

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
$moved = 0;
$removed = 0;
$conflicts = 0;

foreach ($store->listKeys() as $key) {
    if (!preg_match('#^([^/]+/[^/]+/[^/]+)/unassigned/(.+)$#', $key, $matches)) {
        continue;
    }

    $dest = $matches[1] . '/files/' . $matches[2];

    if ($store->exists($dest)) {
        $sourceSize = (int) $store->get($key)['ContentLength'];
        $destSize = (int) $store->get($dest)['ContentLength'];
        if ($sourceSize !== $destSize) {
            fwrite(STDERR, "conflict (size $sourceSize vs $destSize), left in place: $key\n");
            $conflicts++;
            continue;
        }
        $store->delete($key);
        echo "removed duplicate $key\n";
        $removed++;
        continue;
    }

    $store->copy($key, $dest);
    $store->delete($key);
    echo "moved $key -> $dest\n";
    $moved++;
}

echo "done: moved=$moved removed_duplicates=$removed conflicts=$conflicts\n";
if ($conflicts > 0) {
    exit(1);
}
