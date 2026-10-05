<?php

use Corrai\Model\Assessment;
use Corrai\Stream\AssessmentEventFeed;
use Corrai\Utils\Request;
use Corrai\Utils\WSException;

/**
 * SSE feed for an open assessment or student page.
 *
 * Query: hash (assessment id), locale, and student when a student page is open.
 * Each event is a full snapshot of the fields that page displays. Identical
 * snapshots are skipped; a comment keeps the connection alive.
 */

try {
    $hash = Request::getStringParam('hash');
    if ($hash === null || $hash === '') {
        throw new WSException('No hash parameter', 400);
    }

    $assessment = Assessment::from_hash($hash);
    $requestUser = Request::get_mandatory_author();
    if ($assessment->user_id !== $requestUser) {
        throw new WSException('Not authorized', 403);
    }

    $locale = Request::getStringParam('locale');
    $studentParam = Request::getStringParam('student');
    $studentId = $studentParam !== null && trim($studentParam) !== '' ? trim($studentParam) : null;
} catch (\Throwable $th) {
    Request::handle_throwable($th);
    Request::output_all();
    return;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

Request::beginEventStream();

$previous = null;
$ticks = 0;
while (!connection_aborted()) {
    try {
        $assessment = Assessment::from_hash($hash);
        if ($assessment->user_id !== $requestUser) {
            break;
        }
        $payload = $studentId === null
            ? AssessmentEventFeed::assessmentSnapshot($assessment, $locale)
            : AssessmentEventFeed::studentSnapshot($assessment, $studentId, $locale);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false && $encoded !== $previous) {
            $previous = $encoded;
            Request::emitServerEvent($payload);
        } elseif ($ticks % 15 === 0) {
            Request::emitServerComment('ping');
        }
    } catch (\Throwable $th) {
        error_log('Assessment event stream: ' . $th->getMessage());
        break;
    }

    if (connection_aborted()) {
        break;
    }
    $ticks++;
    sleep(1);
}
