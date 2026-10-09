<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Model\BaseAssessment;
use Corrai\Model\Task\QueueItemTask;
use Throwable;

class AssTask1Affectation extends QueueItemTask
{
    protected function process(object $queue_item_data): void
    {
        $assessmentId = $queue_item_data->assessment_id ?? null;
        if ($assessmentId === null || $assessmentId === '') {
            error_log('[AssTask1Affectation] Assessment ID is missing on ticket');
            return;
        }

        try {
            $assessment = BaseAssessment::from_hash($assessmentId);
            $this->processAssessment($assessment);
        } catch (Throwable $e) {
            error_log(sprintf('[AssTask1Affectation] Error processing assessment %s: %s', $assessmentId, $e->getMessage()));
        }
    }

    public function processAssessment(BaseAssessment $assessment): void
    {
        // #region agent log
        @file_put_contents('/home/maintainer/corrai_test/.cursor/debug-3c9dba.log', json_encode(['sessionId' => '3c9dba', 'runId' => 'post-fix', 'hypothesisId' => 'E', 'location' => 'AssTask1Affectation.php:processAssessment', 'message' => 'affectation task body', 'data' => ['assessmentId' => $assessment->id], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND | LOCK_EX);
        // #endregion
        if (!$assessment instanceof Assessment) {
            return;
        }
        if ($assessment->hasUnassignedSubmissionsAwaitingIdentification()) {
            // #region agent log
            @file_put_contents('/home/maintainer/corrai_test/.cursor/debug-3c9dba.log', json_encode(['sessionId' => '3c9dba', 'runId' => 'post-fix', 'hypothesisId' => 'D', 'location' => 'AssTask1Affectation.php:processAssessment', 'message' => 'wait for identification', 'data' => ['assessmentId' => $assessment->id], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND | LOCK_EX);
            // #endregion
            return;
        }
        $assessment->allocateSubmission();
    }
}

if (!class_exists('LawFrance\\AssTask1Affectation', false)) {
    class_alias(AssTask1Affectation::class, 'LawFrance\\AssTask1Affectation');
}
