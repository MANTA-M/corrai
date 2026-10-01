<?php

declare(strict_types=1);

/**
 * Blocking Redis consumer for file-status tickets.
 *
 * Usage (inside the php / redis-consumer container):
 *   php /var/corrai/php/cli/redis_consumer.php
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/inc/autoload.php';

use Corrai\Queue\RedisConsumer;
use Corrai\Queue\RedisQueue;
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

foreach (
    [
        'S3_ENDPOINT',
        'S3_REGION',
        'S3_BUCKET',
        'S3_ACCESS_KEY',
        'S3_SECRET_KEY',
        'REDIS_HOST',
        'REDIS_PORT',
    ] as $key
) {
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        $_ENV[$key] = $val;
    }
}

ini_set('error_log', '/dev/stderr');

$queue = RedisQueue::getInstance();
error_log('Redis consumer started, waiting on ' . RedisQueue::LIST_KEY);

while (true) {
    try {
        $ticket = $queue->blockingPop(5);
        if ($ticket === null) {
            continue;
        }
        error_log('Treating file ticket ' . $ticket['file_id']);
        RedisConsumer::treat($ticket['file_id']);
    } catch (Throwable $e) {
        error_log('Redis consumer error: ' . $e->getMessage());
        error_log($e->getTraceAsString());
    }
}
