<?php

declare(strict_types=1);

/**
 * Add the concrete class name to assessment and file attributes in S3.
 *
 * Usage (inside the php container):
 *   php /var/corrai/php/cli/migrate_class_attributes.php
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/inc/autoload.php';

use Corrai\Utils\ClassAttributeMigrator;
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

$migrator = new ClassAttributeMigrator();
foreach ($migrator->run() as $line) {
    echo $line . PHP_EOL;
}

echo "Done.\n";
