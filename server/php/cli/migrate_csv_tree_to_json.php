<?php

declare(strict_types=1);

/**
 * Migrate the legacy CSV SeaweedFS tree into schools/... JSON attributes.
 *
 * Usage (inside the php container):
 *   php /var/corrai/php/cli/migrate_csv_tree_to_json.php
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/inc/autoload.php';

use Corrai\Utils\CsvTreeMigrator;
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

$migrator = new CsvTreeMigrator();
foreach ($migrator->run() as $line) {
    echo $line . PHP_EOL;
}

echo "Done.\n";
