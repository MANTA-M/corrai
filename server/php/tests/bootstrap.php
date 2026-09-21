<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap: autoload + S3/SeaweedFS env for integration tests.
 */

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';
require_once $root . '/inc/autoload.php';

$envFile = $root . '/.env';
if (is_readable($envFile)) {
    $loaded = [];
    \Corrai\Utils::loadKeyValueFile($envFile, $loaded);
    foreach ($loaded as $key => $value) {
        // Do not override env already set by Docker Compose / the shell.
        if (getenv($key) === false) {
            putenv("$key=$value");
        }
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = getenv($key) !== false ? getenv($key) : $value;
        }
    }
}

// Ensure $_ENV mirrors process env for ObjectStore.
foreach (['S3_ENDPOINT', 'S3_REGION', 'S3_BUCKET', 'S3_ACCESS_KEY', 'S3_SECRET_KEY'] as $key) {
    $val = getenv($key);
    if ($val !== false) {
        $_ENV[$key] = $val;
    }
}
