#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * CLI script: Delete all teachers whose name starts with "Teacher" in the Independent (IND) school.
 *
 * Usage:
 *   php server/php/cli/delete_ind_teachers.php [options]
 *
 * Options:
 *   --dry-run, -d        Preview teachers that would be deleted without actually deleting them.
 *   --prefix=<prefix>    Custom prefix to match teacher names (default: "Teacher").
 *   --help, -h           Show this help message.
 */

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}
require_once $root . '/inc/autoload.php';

use Corrai\Model\School;
use Corrai\Model\User;
use Corrai\Utils\CmdUtils;
use Corrai\Utils\Utils;

function init_env(string $root): void
{
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
}

init_env($root);

/**
 * Delete teachers whose name starts with a given prefix in the IND school.
 *
 * @param string $prefix Prefix to match (default: 'Teacher')
 * @param bool $dryRun If true, do not delete anything
 * @param (callable(string $type, string $message): void)|null $logger Optional callback for logging
 * @return array{
 *     total_ind_teachers: int,
 *     matched: int,
 *     deleted: int,
 *     errors: list<string>,
 *     teachers: list<array{id: string, name: string, email: string}>
 * }
 */
function delete_ind_teachers(
    string $prefix = 'Teacher',
    bool $dryRun = false,
    ?callable $logger = null
): array {
    $log = function (string $type, string $message) use ($logger): void {
        if ($logger !== null) {
            $logger($type, $message);
            return;
        }
        match ($type) {
            'info' => CmdUtils::print_info($message),
            'warn' => CmdUtils::print_warn($message),
            'error' => CmdUtils::print_error($message),
            default => fwrite(STDOUT, "$message\n"),
        };
    };

    $school = School::ensureIndependent();
    $allUsers = $school->users();

    $matchedUsers = [];
    foreach ($allUsers as $user) {
        $name = trim($user->name);
        if (str_starts_with($user->name, $prefix) || str_starts_with($name, $prefix)) {
            $matchedUsers[] = $user;
        }
    }

    $result = [
        'total_ind_teachers' => count($allUsers),
        'matched' => count($matchedUsers),
        'deleted' => 0,
        'errors' => [],
        'teachers' => [],
    ];

    foreach ($matchedUsers as $user) {
        $result['teachers'][] = [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    if (count($matchedUsers) === 0) {
        $log('info', "No teachers starting with '{$prefix}' found in the IND school.");
        return $result;
    }

    $log('info', sprintf(
        "Found %d teacher(s) whose name starts with '%s' out of %d total IND users.",
        count($matchedUsers),
        $prefix,
        count($allUsers)
    ));

    if ($dryRun) {
        $log('warn', "DRY-RUN mode enabled. No teachers will be deleted.");
        foreach ($matchedUsers as $index => $user) {
            $log('info', sprintf(
                " [%d/%d] Would delete: %s (id: %s, email: %s)",
                $index + 1,
                count($matchedUsers),
                $user->name,
                $user->id,
                $user->email
            ));
        }
        return $result;
    }

    foreach ($matchedUsers as $index => $user) {
        $userName = $user->name;
        $userId = (string) $user->id;
        try {
            $user->delete();
            $result['deleted']++;
            $log('info', sprintf(
                " [%d/%d] Deleted: %s (id: %s)",
                $index + 1,
                count($matchedUsers),
                $userName,
                $userId
            ));
        } catch (\Throwable $e) {
            $errorMsg = sprintf("Failed to delete teacher %s (%s): %s", $userId, $userName, $e->getMessage());
            $result['errors'][] = $errorMsg;
            $log('error', " [ERROR] {$errorMsg}");
        }
    }

    $log('info', sprintf(
        "Completed: %d/%d teacher(s) deleted successfully.%s",
        $result['deleted'],
        $result['matched'],
        count($result['errors']) > 0 ? sprintf(" (%d error(s))", count($result['errors'])) : ""
    ));

    return $result;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $options = getopt('dh', ['dry-run', 'prefix:', 'help']);
    if (isset($options['h']) || isset($options['help'])) {
        echo "Usage: php " . basename(__FILE__) . " [options]\n\n";
        echo "Delete all teachers whose name starts with 'Teacher' in the IND school.\n\n";
        echo "Options:\n";
        echo "  -d, --dry-run        List matching teachers without deleting them\n";
        echo "      --prefix=<val>   Name prefix to match (default: 'Teacher')\n";
        echo "  -h, --help           Show this help message\n";
        exit(0);
    }

    $dryRun = isset($options['d']) || isset($options['dry-run']);
    $prefix = (string) ($options['prefix'] ?? 'Teacher');

    $result = delete_ind_teachers($prefix, $dryRun);
    exit(count($result['errors']) > 0 ? 1 : 0);
}
