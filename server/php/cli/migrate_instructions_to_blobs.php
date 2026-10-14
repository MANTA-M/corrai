#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Demote legacy InstructionFile directories under subject/ to bare S3 blobs.
 *
 * Usage:
 *   php cli/migrate_instructions_to_blobs.php [--dry-run] [schoolId] [teacherId]
 *
 * Defaults: school IND, teacher pQHeAXW.
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/inc/autoload.php';

use Corrai\Model\BaseAssessment;
use Corrai\Model\InputFile;
use Corrai\Model\S3File;
use Corrai\Utils\CmdUtils;
use Corrai\Utils\Store\ObjectStore;
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

$dryRun = in_array('--dry-run', $argv, true) || in_array('-d', $argv, true);
$args = array_values(array_filter(
    array_slice($argv, 1),
    static fn(string $arg): bool => !str_starts_with($arg, '-')
));
$schoolId = $args[0] ?? 'IND';
$teacherId = $args[1] ?? 'pQHeAXW';

$store = ObjectStore::getInstance();
$prefix = ObjectStore::assessmentsPrefix($schoolId, $teacherId);
$assessmentIds = $store->listChildPrefixes($prefix);

CmdUtils::print_info(sprintf(
    '%sMigrating instruction InputFiles → blobs for %s/%s (%d assessments)',
    $dryRun ? '[dry-run] ' : '',
    $schoolId,
    $teacherId,
    count($assessmentIds)
));

$migrated = 0;
$alreadyBlob = 0;
$errors = 0;

foreach ($assessmentIds as $assessmentId) {
    try {
        $assessment = BaseAssessment::from_hash($assessmentId);
    } catch (Throwable $e) {
        CmdUtils::print_error("Cannot load assessment {$assessmentId}: " . $e->getMessage());
        $errors++;
        continue;
    }

    foreach ($assessment->listFileModels() as $file) {
        if ($file instanceof S3File && $file->type === 'instructions') {
            $alreadyBlob++;
            continue;
        }
        if (!$file instanceof InputFile || $file->role() !== 'instructions') {
            continue;
        }

        $label = sprintf(
            '%s file=%s name=%s',
            $assessmentId,
            $file->id ?? '?',
            $file->name
        );

        if ($dryRun) {
            CmdUtils::print_info('[dry-run] would demote ' . $label);
            $migrated++;
            continue;
        }

        try {
            $assessment->setFileTags((string) $file->id, 'instructions', null);
            $reloaded = $assessment->getFile((string) $file->id);
            if (!$reloaded instanceof S3File) {
                throw new RuntimeException('Expected S3File after demote, got ' . $reloaded::class);
            }
            CmdUtils::print_info('demoted ' . $label . ' → ' . $reloaded->key);
            $migrated++;
        } catch (Throwable $e) {
            CmdUtils::print_error('Failed ' . $label . ': ' . $e->getMessage());
            $errors++;
        }
    }
}

CmdUtils::print_info(sprintf(
    'Done. migrated=%d already_blob=%d errors=%d',
    $migrated,
    $alreadyBlob,
    $errors
));
exit($errors > 0 ? 1 : 0);
