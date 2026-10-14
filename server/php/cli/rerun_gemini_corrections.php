<?php

declare(strict_types=1);

/**
 * Delete correction.json, run LawFranceGemini StudentTask1Correcting, save locally.
 *
 * Usage:
 *   php cli/rerun_gemini_corrections.php
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/inc/autoload.php';

use Corrai\Model\BaseStudent;
use Corrai\Subject\LawFranceGemini\StudentTask1Correcting;
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

foreach (
    [
        'S3_ENDPOINT',
        'S3_REGION',
        'S3_BUCKET',
        'S3_ACCESS_KEY',
        'S3_SECRET_KEY',
    ] as $key
) {
    $val = getenv($key);
    if ($val !== false) {
        $_ENV[$key] = $val;
    }
}

$students = [
    '482' => 'BdyohQN',
    '483' => '4vvAZt3',
    '484' => 'Zgganah',
    '485' => '9NlnMPr',
    '486' => 'Op5WN2y',
    '487' => '87oQCA5',
    '488' => 'HXoOZwW',
    '489' => 'oJ7Bq4h',
    '490' => 'SgyvVq6',
    '491' => 'EeAP30C',
    '492' => 'TCcmv5V',
];

$outRoot = dirname($root, 2) . '/doc/corr_ai';
$store = ObjectStore::getInstance();
$task = new StudentTask1Correcting();

$schoolId = 'IND';
$teacherId = 'pQHeAXW';
$assessmentId = 'IdC3gkB';

$ok = 0;
$fail = 0;

foreach ($students as $name => $studentId) {
    $prefix = ObjectStore::assessmentStudentPrefix($schoolId, $teacherId, $assessmentId, $studentId);
    $correctionKey = $prefix . 'correction.json';
    $destDir = $outRoot . '/' . $name;
    if (!is_dir($destDir)) {
        mkdir($destDir, 0775, true);
    }

    echo date('H:i:s') . " === Student {$name} ({$studentId}) ===\n";

    if ($store->exists($correctionKey)) {
        $store->delete($correctionKey);
        echo date('H:i:s') . " deleted {$correctionKey}\n";
    } else {
        echo date('H:i:s') . " no existing correction.json\n";
    }

    try {
        $student = BaseStudent::from_hash($studentId);
        $task->processStudent($student);
    } catch (Throwable $e) {
        echo date('H:i:s') . " ERROR: " . $e->getMessage() . "\n";
        $fail++;
        continue;
    }

    if (!$store->exists($correctionKey)) {
        echo date('H:i:s') . " ERROR: correction.json not created\n";
        $fail++;
        continue;
    }

    $contents = $store->getContents($correctionKey);
    $dest = $destDir . '/correction_gemini.json';
    file_put_contents($dest, $contents);
    echo date('H:i:s') . " saved {$dest} (" . strlen($contents) . " bytes)\n";
    $ok++;
}

echo date('H:i:s') . " DONE ok={$ok} fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
